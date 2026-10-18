# Architecture

Upfixy is independent of Gods Eye: separate repository, database, roles, secrets and (when approved) servers. Development domain: `agent25.in` (D-01, deferred). No server is selected (D-02, deferred).

## Processes

| Process | Runs as (DB role) | Does | Never does |
| --- | --- | --- | --- |
| API (`php artisan serve` locally) | `upfixy_app` | Auth, tenant reads and writes, quotes, approvals, diagnosis requests, AI calls (as a queued job) | Hold the repair key, read sealed DNS tokens, call Cloudflare write endpoints |
| Monitor (`upfixy:monitor`) | `upfixy_worker` | Claims due targets, probes DNS/HTTP/TLS, appends observations, opens and resolves incidents, sends alerts | Read user or billing data, change tickets or quotes |
| Repair executor (`upfixy:repairs`) | `upfixy_executor` (5 connections, 15 s statement timeout) | Reads drafts, runs approved attempts, writes repair events, moves the linked ticket | Anything not in `0013_repairs.sql` executor grants |
| Web (Vite, React + TS) | — | Mobile-first UI, real URLs per screen | Hold secrets; all rules are enforced server-side |

Migrations run as `upfixy_owner`. None of the runtime roles is a superuser, owns tables or can bypass RLS (tested).

## Data and isolation

- **PostgreSQL 16 with row-level security on every tenant table.** Each request sets `app.workspace_id`, `app.user_id` and `app.role` inside one transaction (`set_config(..., true)`), so context cannot leak between pooled requests.
- Composite foreign keys `(workspace_id, id)` stop cross-tenant references even from buggy code.
- Privileged steps (claims, incidents, repairs) are `SECURITY DEFINER` functions that check `session_user`, so the API cannot call worker or executor functions.
- Evidence tables (observations, check runs, findings, AI analyses, repair events, audit) are **append-only** through triggers.
- Actor foreign keys are `DEFERRABLE INITIALLY DEFERRED` so a workspace and its memberships can be removed in one transaction.

## Work scheduling

There is no message broker. Workers claim rows with `FOR UPDATE SKIP LOCKED` and a lease (`lease_until`). A crashed worker's lease expires and another worker picks the row up. The same pattern is used for monitor targets, repair drafts and repair attempts. The AI job uses Laravel's `dispatchAfterResponse` locally; a real queue is a deployment decision.

## Shared rules

`packages/core` (TypeScript) holds the URL/SSRF guard, health roll-up, incident debounce, ticket state machine and the issue library. `packages/core/vectors/*.json` are test cases that both the TS and the PHP implementations must pass, so the UI and the server cannot disagree.

## Diagnostics and repairs

```
observations ──► SnapshotBuilder ──► RuleEngine (deterministic) ──► findings
                                    └─► PayloadBuilder ─► OpenAI ─► Validator ─► ai_analyses (or rejected)
ticket ─► repair draft ─► executor reads live record ─► plan ─► quote (fingerprint) ─► approval ─► payment
       ─► attempt ─► executor gates ─► one PATCH ─► verify ─► resolved | rolled back | needs technician
```

Details: [AI_DIAGNOSTICS.md](AI_DIAGNOSTICS.md), [REPAIR_ENGINE.md](REPAIR_ENGINE.md). Security model: [SECURITY.md](SECURITY.md).

## Key decisions (ADR summary)

| # | Decision | Reason |
| --- | --- | --- |
| A-1 | Tenant isolation in the database (RLS), not only in controllers | One missed `where` cannot leak another tenant |
| A-2 | Lease-claimed rows instead of a queue | Fewer moving parts on a small VPS; monitoring survives connector outages |
| A-3 | Own DNS wire client, curl pinned to the vetted IP, manual redirects | Prevents DNS rebinding and redirect-based SSRF |
| A-4 | AI is advisory; rules decide confidence | FRD §25.1: AI cannot confirm a cause or authorise a change |
| A-5 | Separate executor role and process; DNS tokens sealed to its key | A compromised API cannot change DNS or read tokens |
| A-6 | Three independent off switches for repairs (env flag, global DB row, workspace row) | Kill switch must stop approved work too (AC-FIX-005.1) |
