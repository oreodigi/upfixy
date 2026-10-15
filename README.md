# Upfixy

Website diagnostics and resolution SaaS for solo owners, agencies and hosting providers. This repository is the **local development scaffold for milestone M1** (tenant foundation) plus the shared rules that M2–M3 build on.

Requirements: *Upfixy MVP FRD v0.2.1*. Nothing here is deployed or connected to any real server, provider account or payment gateway. This repo is independent of Gods Eye: it has its own database, roles and code, and holds no credentials.

## What's here

| Path | What it is | Status |
| --- | --- | --- |
| `db/migrations/` | PostgreSQL 16 schema: workspaces, memberships, clients, websites, exact monitor targets, ownership claims, delegations, observations, tickets, approvals, append-only audit log, **row-level security** | Built, 23 tests |
| `packages/core/` | Shared rules in TypeScript: URL/SSRF guard, health reducer and client roll-up, incident debounce, ticket state machine | Built, 88 tests |
| `web/` | React + TypeScript (Vite) shell: client board, website board, list view, work queue, *Help me understand* dialog. Runs on **demo data** | Built, smoke-tested |
| `api/` | Laravel API | **Not started.** Composer's registry (packagist.org) is blocked in the build environment |

## Run it locally

Needs Node 22+ and PostgreSQL 16. Use either a local install or Docker:

```bash
docker compose up -d          # or: create the roles/databases in db/docker/init.sql by hand
npm install
npm run db:migrate            # applies db/migrations to upfixy_dev
npm test                      # core + database + web
npm run dev                   # web app at http://127.0.0.1:5173
```

`npm run db:reset` drops and recreates the dev schema. It refuses to run against a non-local host.

## How tenant isolation works

The API will connect as `upfixy_app`. That role is **not** the table owner, so Postgres row-level security applies to every query. Each request sets its context inside a transaction:

```sql
SELECT set_config('app.user_id', $1, true), set_config('app.workspace_id', $2, true);
```

With no context, or with a workspace the user isn't a member of, tenant tables return nothing. The database itself enforces:

- **Client scoping (§6).** Technicians and client users see only their assigned clients. The billing role sees no sites.
- **Ticket transitions (§18.2).** Illegal moves are rejected. Leaving *Quote pending* requires a live approval, including for ₹0 work (FIX-A). Paid work also requires `payment_verified`, which clients can't set.
- **Payer rule (FIX-B).** For client work, only the client owner can approve, or the agency where `clients.agency_may_pay` is set.
- **Optimistic locking.** Every ticket update must bump `version` by exactly 1.
- **Append-only audit log.** Updates and deletes are blocked even for the owner role.
- **Composite foreign keys.** A row can never point at another workspace's client, site or ticket.

The API will repeat these checks itself. RLS is the second layer, not the only one.

## Acceptance criteria evidenced by tests

| AC | Where |
| --- | --- |
| AC-ACC-003.1 cross-tenant read returns nothing | `db/test/rls.test.mjs` |
| AC-ACC-005.1 client owner sees only their sites | `db/test/rls.test.mjs` |
| AC-ACC-006.2 technician can't read other client's tickets | `db/test/rls.test.mjs` |
| AC-ACC-007.1 audit log is append-only | `db/test/rls.test.mjs` |
| AC-AST-001.2 private/metadata/encoded IPs rejected | `packages/core/test/url-guard.test.ts` |
| AC-AST-001.3 apex and www stay distinct | core + db tests |
| AC-BRD-001.1 critical site makes the client Critical | core + web smoke test |
| AC-BRD-003.1 Resolved needs passed verification | core test (UI rule; DB enforcement lands with M7 verification runs) |
| AC-BRD-003.2 Investigating → Ready for work blocked | core + db tests |
| AC-BRD-005.1 no backup connector shows "Not connected" | core + web smoke test |
| AC-MON-005.1 / .3 debounce counts cycles, not retries | `packages/core/test/incidents-tickets.test.ts` |
| AC-MON-007.1 stale after 3× interval shows Unknown | core test |
| AC-MON-008.1 resolves after 2 OK cycles with timestamps | core test |
| AC-SVC-001.1 stale ticket version rejected | db test |
| AC-SVC-003.2 / .3 / .4 approval expiry, ₹0 approval, payer rule | core + db tests |
| D-17 healthy + unknown rolls up to Unknown | core test |

A parity test checks that the UI's transition table and the database's `ticket_transition_allowed()` agree on all 81 state pairs.

## Known gaps (next steps)

- **Laravel API:** needs `repo.packagist.org`, `packagist.org`, `api.github.com` and `codeload.github.com` allowed in the build environment. Its migrations will execute the SQL files in `db/migrations/` so there is one schema source.
- **Connect-time SSRF check:** `isBlockedAddress()` must be called by the probe worker on every resolved IP and redirect hop (M3). Intake validation alone doesn't stop DNS rebinding.
- **Visual review:** the browser download for screenshots was blocked, so the web shell is verified by typecheck, build and a render test only. Review it in a browser.
- **Work queue:** moves use a keyboard-accessible *Move to* control. Drag-and-drop comes later and will call the same validated transition.
- **Not yet built:** auth/MFA, invitations, ownership-claim verification, the probe worker, and connectors (M4, which needs pilot test accounts).
