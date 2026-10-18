# Upfixy

> **Current product specification:** [MVP FRD v0.3](docs/FRD.md) — founder-approved AI diagnostics, narrowly scoped automated DNS fixes, security gates, acceptance tests, and decision log. This FRD is the product scope; features described in it are **not necessarily implemented**. Refer to the implementation status below and tests before claiming readiness.

Website diagnostics and resolution SaaS for solo owners, agencies and hosting providers. This repository is the **local development build through milestone M2** (hardened tenant foundation, boards and UX): a Laravel API on PostgreSQL row-level security, shared health and workflow rules, and a React web app.

Requirements: [Upfixy MVP FRD v0.3](docs/FRD.md) (M1 was built against v0.2.1; v0.3 adds AI diagnostics and controlled DNS repairs in M5 and M7). Nothing here is deployed or connected to any real server, provider account or payment gateway. This repo is independent of Gods Eye: it has its own database, roles and code, and holds no credentials.

## Documentation

| Doc | What it answers |
| --- | --- |
| [FRD v0.3](docs/FRD.md) | What the product must do (founder-approved scope) |
| [Implementation status](docs/IMPLEMENTATION_STATUS.md) | **Start here.** State of every feature, traceability FRD → code → tests → PR, differences from the FRD |
| [Architecture](docs/ARCHITECTURE.md) | Processes, database roles, isolation, key decisions |
| [Security](docs/SECURITY.md) | Controls, findings S-01…S-16, known gaps |
| [AI diagnostics](docs/AI_DIAGNOSTICS.md) · [AI model evaluation](docs/AI_MODEL_EVALUATION.md) | Evidence → rules → AI contract; how candidate models are compared |
| [Repair engine](docs/REPAIR_ENGINE.md) | Allowlisted Cloudflare DNS fix: gates, controls, release checklist |
| [Payments](docs/PAYMENTS.md) | Coverage, Razorpay test-mode flow, webhooks, live lock, receipts, refunds |
| [Backup and recovery](docs/BACKUP_RECOVERY.md) | Encrypted backups, restore drill, proposed RPO/RTO |
| [Test results](docs/TEST_RESULTS.md) | Latest exact results and what is not yet tested |

## What's here

| Path | What it is | Tests |
| --- | --- | --- |
| `db/migrations/` | PostgreSQL 16 schema, the single source of truth: tenancy, websites, ownership proof, delegations, tickets, quotes, approvals, invitations, audit and **row-level security** on every tenant table | 86 (as the runtime roles) |
| `db/bootstrap/roles.sql` | Creates the four database roles with least privilege: owner (migrations), app (API), worker (monitor, connectors, payment events), executor (repairs) | |
| `api/` | Laravel 12 API: auth with MFA, invitations, team, clients, websites, ownership proof, boards, tickets, quotes, approvals; monitor worker (`upfixy:monitor`), read-only connectors (`upfixy:connectors`), payments (Razorpay test mode, webhooks, `upfixy:payments`), diagnostics with optional AI (`upfixy:ai-eval`), repair executor (`upfixy:repairs`), audit chain (`upfixy:audit`) | 314 |
| `packages/core/` | Shared rules in TypeScript: URL/SSRF guard, health and client roll-up, incident debounce, ticket state machine, 46-guide issue library | 180 |
| `packages/core/vectors/` | JSON test cases that **both** the TypeScript and PHP implementations must pass | (used by both) |
| `web/` | React + TypeScript (Vite): real URLs for every screen; overview, client and website boards with search, lane filters and saved views, client detail, website detail (8 tabs, reading history), work queue (drag or keyboard), assignment, sign-in with MFA, team, ownership proof, quotes. Add `?demo` for the synthetic demo board | 23 |
| `scripts/e2e.py` | 40 end-to-end checks against the running servers | |
| `ops/backup.sh`, `ops/restore-drill.sh` | Encrypted database backup and a verified restore drill | drill passed |
| `scripts/seed-dev.mjs` | Dev-only demo data (refuses non-local databases). `--sites 200` adds a load-test workspace | |
| `scripts/screens.mjs` | Screenshots at 320, 390 and 1440 px, page-overflow check and axe WCAG 2.1 AA audit; exits 1 on serious findings | 26 screens × 3 |
| `scripts/keyboard.mjs` | Keyboard-only checks: skip link, navigation, moving and opening requests, dialogs, filters, Back button, deep links, Check now, Analyze, incidents | 31 |
| `scripts/perf.mjs` | API median/p95 and board render time on throttled phone (Fast 4G, 4× CPU) | |
| `.github/workflows/ci.yml` | Every suite on push and PR, with a throwaway PostgreSQL | |

## Run it locally

Needs Node 22+, PHP 8.3 with `pdo_pgsql` and `intl`, Composer, and PostgreSQL 16 (local, or `docker compose up -d`).

```bash
# 1. Database roles (once, as a Postgres superuser; Docker does this for you)
psql -v owner_pw=devonly -v app_pw=devonly -v worker_pw=devonly -v executor_pw=devonly -v db=upfixy_dev -f db/bootstrap/roles.sql

# 2. App
npm install
(cd api && composer install && cp .env.example .env && php artisan key:generate)
npm run api:migrate     # applies any pending db/migrations/*.sql as the owner role
npm run api             # API on http://127.0.0.1:8000
npm run dev             # web app on http://127.0.0.1:5173 (proxies /api to :8000)
npm test                # everything: core, database, web, API
python3 -I scripts/e2e.py   # optional: 38 end-to-end checks against the running servers
node scripts/seed-dev.mjs   # demo data: owner@ / tech@ / client@demo.upfixy.test, password demo-password-123
node scripts/screens.mjs /tmp/shots && node scripts/keyboard.mjs   # visual, accessibility and keyboard checks
```

Email goes to `api/storage/logs/laravel.log` in development (`MAIL_MAILER=log`).

### Three database roles

| Role | Used by | Can |
| --- | --- | --- |
| `upfixy_owner` | migrations only | own the schema. Never used at runtime |
| `upfixy_app` | the API | read and write through row-level security and narrow functions. Cannot read password hashes, MFA secrets or token hashes; cannot mark ownership verified |
| `upfixy_worker` | background jobs | report what it observed (for example the ownership token it found). In production the web server's `.env` must not contain this password |

All three: no superuser, no `BYPASSRLS`, cannot create objects. `upfixy_app` and `upfixy_worker` have statement and idle-transaction timeouts. No database function is executable by `PUBLIC`.

## API (v1)

All workspace routes take an `X-Workspace-Id` header. A workspace you don't belong to gets the same 404 as one that doesn't exist. **Step-up** means MFA must be on and a code entered on this session in the last 15 minutes.

| Method and path | Purpose | Notes |
| --- | --- | --- |
| `POST auth/register`, `auth/login`, `auth/mfa/login`, `auth/logout`, `auth/logout-all` | Sign-up, two-step sign-in, sign out | Login with MFA returns a 5-minute challenge token that can only call `auth/mfa/login` |
| `POST auth/verify-email`, `auth/verify-email/resend` | One-time, 24-hour email links | Only the SHA-256 is stored |
| `POST mfa/setup`, `mfa/confirm`, `mfa/verify`, `mfa/disable` | TOTP enrolment, step-up, removal | 8 one-time recovery codes; each time step accepted once; 6 tries/min per endpoint |
| `GET/POST workspaces` | Switcher list, create | |
| `GET members`, `PATCH members/{user}` | Team list; change role, remove or restore access | Step-up. Can't remove the last owner; only owners change owners |
| `GET/POST invitations`, `POST invitations/{id}/revoke`, `POST invitations/accept` | Email invitations | Step-up. Never reveals whether an email has an account. Accepting needs the same, verified email |
| `GET/POST clients`, `GET/PATCH clients/{id}`, `POST clients/{id}/assignments`, `DELETE clients/{id}/assignments/{user}` | Clients, detail (staff, client users, settings), assignments | Changing `agency_may_pay` needs the owner, with step-up. Removing a technician unassigns their open requests for that client |
| `GET/POST websites`, `GET websites/{id}`, `GET websites/{id}/history` | Add by URL; detail; reading history | Public monitoring needs no proof (D-07). Private readings stay hidden until ownership is proven |
| `GET/POST websites/{id}/claims`, `POST claims/{id}/check`, `POST claims/{id}/revoke` | DNS TXT / HTML-file ownership proof | Checked by the worker; revoke needs step-up |
| `GET boards/websites`, `GET boards/clients`, `GET overview` | Health lanes computed on the server; what needs attention now | |
| `GET/POST views`, `PATCH/DELETE views/{id}` | Saved board filters | Personal to each user; up to 20 per board |
| `GET actions` | Service catalogue (draft prices, D-08 open) | |
| `GET/POST tickets`, `GET tickets/{id}`, `POST tickets/{id}/transition`, `GET tickets/{id}/assignable`, `PATCH tickets/{id}/assignee` | Work queue, versioned moves, assignment | Technicians only take or drop their own; assignees must be active staff on that client (enforced in the database) |
| `POST tickets/{id}/quotes`, `POST quotes/{id}/void` | Immutable quote for one address and one allowlisted action | Staff only; price from the catalogue |
| `POST tickets/{id}/approvals` | Payer approves `{quote_id, fingerprint}` | Paid quotes need step-up |

## How the guarantees are enforced

Each request runs in **one transaction** with `app.user_id` and `app.workspace_id` set. It commits only on a success response. Denied actions are audited in a separate transaction, so a rollback can't erase them.

The database enforces, independent of the API code:

- **Tenant and client scoping.** Technicians and client users see only their assigned clients. Billing sees no sites.
- **Identity.** The app role can't select password hashes, MFA secrets, or token or invitation hashes. Login, token lookup and MFA checks go through narrow functions for one user. Expired tokens are never returned. Every token must expire (7 days).
- **Ownership (D-07).** A DNS TXT or HTML-file challenge expires after 7 days. Only the worker role can verify it, and only by reporting the exact token it saw. Proof lasts 90 days, covers one exact host, and can be revoked. Delegations can be requested and revoked by the app but granted only by the worker (M4). Private readings disappear the moment proof lapses.
- **Quotes and approvals.** Quotes are immutable, priced from the catalogue, and fingerprinted (SHA-256 over every term, including the exact host). A new quote voids the old one and its approval. An approval must echo the fingerprint the payer saw. Its terms are copied from the quote, never taken from the request.
- **Starting work** needs a live approval of the live quote, verified payment if chargeable (M6), and current ownership proof for the quoted host. This applies even to ₹0 work.
- **Payer rule.** For client work, only the client owner can approve, or the agency where the owner has set `agency_may_pay`.
- **Memberships.** They are created only by workspace creation or an accepted invitation. A workspace always keeps one owner. Suspension removes client assignments, ticket assignments and the member's pending invitations at once.
- **Narrowed writes.** Monitor state, claim status, delegation grants, approval and payment flags are not writable by the app.
- **Audit log.** Append-only, even for the owner role.

**Parity checks:** the ticket transition table is compared across the database, PHP and TypeScript (all 81 state pairs). URL-guard and health behaviour is checked by the shared JSON vectors in both languages.

**Isolation sweep:** `CrossTenantSweepTest` finds every API route with an ID parameter and calls it from another workspace (and from another client in the same workspace). It uses the victim's real IDs, valid bodies and fresh MFA. Every call must answer 404 and leave every table byte-identical. A new route with an unmapped parameter fails the test. Planting a deliberate leak in one route makes it fail.

**Outbound checks** (the ownership HTML-file check now, probes in M3) resolve the host and refuse if any address is private or reserved. They connect only to a checked address, never follow redirects, and read at most 1 KB within 5 seconds.

## Acceptance criteria evidenced by tests

| AC | Evidence |
| --- | --- |
| AC-ACC-001.2 unverified email can't approve or accept invitations | `QuoteApprovalTest`, `AccountSecurityTest`, `db/test` |
| AC-ACC-002.1 MFA required before sensitive actions; step-up | `AccountSecurityTest`, `QuoteApprovalTest`, web `api.test.ts` |
| AC-AST-002.2–.7 ownership proof, delegation scope, revocation, repair gate | `OwnershipTest`, `QuoteApprovalTest`, `db/test/hardening` |
| AC-SVC-003.1 a changed quote voids approval | `QuoteApprovalTest`, `db/test/hardening` |
| NFR-12 every ID route isolated | `CrossTenantSweepTest` |
| AC-ACC-003.1 cross-tenant → 404, audited in the caller's own tenant only | `TenancyTest`, `db/test` |
| AC-ACC-005.1 client owner sees only their sites (list and board) | `TenancyTest`, `db/test` |
| AC-ACC-006.2 technician can't read other clients' tickets | `TenancyTest`, `db/test` |
| AC-ACC-007.1 audit append-only | `BoardAndTicketTest`, `db/test` |
| AC-AST-001.2 metadata/private/encoded IPs rejected with a plain reason | `OnboardingTest`, shared vectors |
| AC-AST-001.3 apex ≠ www; exact duplicate → 409 | `OnboardingTest`, `db/test` |
| AC-BRD-001.1 one critical site → client Critical | `BoardAndTicketTest`, core, web |
| AC-BRD-003.1 / .2 Resolved needs verification; Investigating ↛ Ready | `QuoteApprovalTest`, `BoardAndTicketTest`, core, `db/test` |
| AC-BRD-005.1 backup "Not connected", never green | core, web |
| AC-MON-005.1 / .3, AC-MON-008.1 debounce by cycle; 2-cycle recovery | core |
| AC-MON-007.1 stale (> 3× interval) → Unknown, no "last verified" | `BoardAndTicketTest`, core, web |
| AC-SVC-001.1 stale version → 409 | `BoardAndTicketTest`, `db/test` |
| AC-SVC-003.2 / .3 / .4 approval expiry, ₹0 approval, payer rule | `QuoteApprovalTest`, core, `db/test/hardening` |
| D-17 healthy + unknown → Unknown | shared vectors |
| AC-BRD-002.1 a one-site workspace gets a single-site view, not empty lanes | `scripts/screens.mjs` (solo) |
| AC-BRD-004.1 search never surfaces names the user can't see (filters run on RLS-filtered data) | web `router-filters.test.ts`, `BoardsM2Test` |
| M2 exit: health separate from work; mobile and keyboard verified | `scripts/screens.mjs` (0 px overflow, 0 serious axe findings), `scripts/keyboard.mjs` |

## Known gaps (next steps)

Full list with risks: [Implementation status](docs/IMPLEMENTATION_STATUS.md) and [Security](docs/SECURITY.md).

- **Open PRs:** #8 (M4) → #10 (M6) → #11 (hardening, AI evaluation) await founder review.
- **Real accounts needed:** pilot WHM/cPanel/Cloudflare test accounts (M4), a Razorpay sandbox (M6), an OpenAI key for the model comparison (M5), a Cloudflare test zone (M7). Nothing has been run against a real provider.
- **Off by design:** live payments (three locks), production AI (`UPFIXY_AI_MODEL` unset), automated repairs (flag off, global switch paused).
- **Founder decisions:** D-01, D-02, D-03, D-05, D-06, D-08, D-12, D-13, D-14, D-18, D-23, C-1…C-5, §25.8.
- **Before pilot:** staging server, scheduled backups with off-site storage, off-host audit anchors, webhook service split, real-device accessibility check.
