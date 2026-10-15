# Upfixy

Website diagnostics and resolution SaaS for solo owners, agencies and hosting providers. This repository is the **local development build of milestone M1** (tenant foundation): a Laravel API on PostgreSQL row-level security, shared health and workflow rules, and a React web app.

Requirements: *Upfixy MVP FRD v0.2.1*. Nothing here is deployed or connected to any real server, provider account or payment gateway. This repo is independent of Gods Eye: it has its own database, roles and code, and holds no credentials.

## What's here

| Path | What it is | Tests |
| --- | --- | --- |
| `db/migrations/` | PostgreSQL 16 schema: the single source of truth. Covers workspaces, memberships, clients, websites, exact monitor targets, claims, delegations, observations, tickets, approvals, append-only audit, API tokens and **row-level security** | 23 (as the restricted app role) |
| `api/` | Laravel 12 API. Auth (Sanctum tokens), workspaces, members, clients, websites, health boards, tickets, approvals | 79 |
| `packages/core/` | Shared rules in TypeScript: URL/SSRF guard, health and client roll-up, incident debounce, ticket state machine | 169 |
| `packages/core/vectors/` | JSON test cases that **both** the TypeScript and PHP implementations must pass | (used by both) |
| `web/` | React + TypeScript (Vite). Sign up or sign in, add websites and clients, client and website boards, list view, work queue, *Help me understand*. Add `?demo` to the URL for the synthetic demo board | 4 |

## Run it locally

Needs Node 22+, PHP 8.3 with `pdo_pgsql` and `intl`, Composer, and PostgreSQL 16 (local, or `docker compose up -d`).

```bash
npm install
(cd api && composer install && cp .env.example .env && php artisan key:generate)
npm run api:migrate     # applies db/migrations as the owner role
npm run api             # API on http://127.0.0.1:8000
npm run dev             # web app on http://127.0.0.1:5173 (proxies /api to :8000)
npm test                # everything: core, database, web, API
```

The API **connects as `upfixy_app`**, which is not the table owner, so Postgres row-level security applies to every query. Migrations run separately as `upfixy_owner`. Both runners (`npm run db:migrate` and `php artisan migrate --database=pgsql_owner`) record into the same `schema_migrations` table, so either can be used.

## API (v1)

All workspace routes take an `X-Workspace-Id` header. A workspace you don't belong to gets the same 404 as one that doesn't exist.

| Method and path | Purpose | FRD |
| --- | --- | --- |
| `POST auth/register`, `auth/login`, `auth/logout` | "For myself" / "For my clients" signup, token login | ACC-001 |
| `GET/POST workspaces` | Workspace switcher list, create workspace | ACC-003/004 |
| `POST members` | Add an existing user with a role (owner/admin only) | ACC-005 |
| `GET/POST clients`, `POST clients/{id}/assignments` | Client profiles and staff/client-user assignment | ACC-006 |
| `GET/POST websites`, `GET websites/{id}` | Add by URL with no credentials; exact target kept | AST-001 |
| `GET boards/websites`, `GET boards/clients` | Health lanes computed on the server, never stored | BRD-001/002, §15.2 |
| `GET/POST tickets`, `GET tickets/{id}`, `POST tickets/{id}/transition` | Work queue with versioned, validated moves | SVC-001, BRD-003, §18.2 |
| `POST tickets/{id}/approvals` | Exact-action approval by the authorised payer | SVC-003, FIX-B |

## How the guarantees are enforced

Each request runs in **one transaction** with `app.user_id` and `app.workspace_id` set. It commits only on a success response. Anything that fails part-way leaves no writes behind (`RequestTransactionTest` proves this, and fails against the earlier middleware).

The database enforces, independent of the API code:

- **Tenant and client scoping.** Technicians and client users see only their assigned clients. Billing sees no sites.
- **Ticket transitions.** *Quote pending* → *Ready for work* needs a live approval, including for ₹0 work. Paid work also needs a verified payment, which no API path can set yet.
- **Payer rule.** For client work, only the client owner can approve, or the agency where the client contract allows it (`clients.agency_may_pay`).
- **Optimistic locking.** A stale ticket version gets 409.
- **Audit log.** Append-only, even for the owner role. Denied actions are recorded in a separate transaction so the request's rollback can't erase them.
- **Composite foreign keys.** A row can never point at another workspace's records.

**Parity checks:** the ticket transition table is compared across the database, PHP and TypeScript (all 81 state pairs). URL-guard and health behaviour is checked by the shared JSON vectors in both languages.

## Acceptance criteria evidenced by tests

| AC | Evidence |
| --- | --- |
| AC-ACC-001.2 unverified email can't approve | `api/tests/Feature/BoardAndTicketTest` |
| AC-ACC-003.1 cross-tenant → 404, audited in the caller's own tenant only | `TenancyTest`, `db/test` |
| AC-ACC-005.1 client owner sees only their sites (list and board) | `TenancyTest`, `db/test` |
| AC-ACC-006.2 technician can't read other clients' tickets | `TenancyTest`, `db/test` |
| AC-ACC-007.1 audit append-only | `BoardAndTicketTest`, `db/test` |
| AC-AST-001.2 metadata/private/encoded IPs rejected with a plain reason | `OnboardingTest`, shared vectors |
| AC-AST-001.3 apex ≠ www; exact duplicate → 409 | `OnboardingTest`, `db/test` |
| AC-BRD-001.1 one critical site → client Critical | `BoardAndTicketTest`, core, web |
| AC-BRD-003.1 / .2 Resolved needs verification; Investigating ↛ Ready | `BoardAndTicketTest`, core, `db/test` |
| AC-BRD-005.1 backup "Not connected", never green | core, web |
| AC-MON-005.1 / .3, AC-MON-008.1 debounce by cycle; 2-cycle recovery | core |
| AC-MON-007.1 stale (> 3× interval) → Unknown, no "last verified" | `BoardAndTicketTest`, core, web |
| AC-SVC-001.1 stale version → 409 | `BoardAndTicketTest`, `db/test` |
| AC-SVC-003.2 / .3 / .4 approval expiry, ₹0 approval, payer rule | `BoardAndTicketTest`, core, `db/test` |
| D-17 healthy + unknown → Unknown | shared vectors |

## Known gaps (next steps)

- **Monitoring worker (M3).** Nothing produces observations yet, so new sites show *Needs setup*. The worker must call `isBlockedAddress()` on every resolved IP and redirect hop.
- **Auth hardening.** Email verification sending, MFA (ACC-002), invitations by email and password reset aren't built. The approval endpoint already refuses unverified emails.
- **`users` table has no RLS.** The login lookup needs it before any tenant context exists. Move lookups to a narrow `SECURITY DEFINER` function before pilot.
- **Ownership claims (D-07).** The DNS TXT/HTML challenge flow isn't built. The tables exist.
- **Connectors (M4).** Need pilot test accounts.
- **Visual review.** Screenshots weren't possible in the build environment. Review the web app in a browser, including on a phone.
