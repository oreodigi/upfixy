# Security

Threat model per FRD §20 and §25.5. This file records the controls in place, review findings and known gaps. No penetration test has been done.

## Boundaries

- **Tenant:** RLS on every tenant table, plus composite FKs. A cross-tenant sweep calls every API route as another tenant (963 assertions).
- **Roles:** four database roles with least privilege. Runtime roles can't bypass RLS, own tables or read password hashes or MFA secrets.
- **Public vs private (D-07):** public HTTP/TLS/DNS checks need no proof. Private readings, diagnostics with private evidence and repairs need current DNS/HTML proof or a scoped delegation.
- **No root WHM (D-04):** no WHM code exists yet (M4). Root-like tokens must be rejected when it does.
- **SSRF (MON-006):**
  - Every resolved IP and redirect hop goes through the blocked-address guard.
  - curl is pinned to the vetted IP.
  - Redirects are followed manually (max 10) on allowed ports only (80/443/8080/8443).
  - The DNS client talks to fixed public resolvers.
- **Secrets:**
  - DNS tokens are sealed to the executor key.
  - MFA secrets are encrypted.
  - The OpenAI key is env-only.
  - Nothing secret is in the repo, logs, audit rows or AI payloads.

## M1/M2 review findings (this review)

| ID | Severity | Finding | Fix | Test |
| --- | --- | --- | --- | --- |
| S-01 | High | App role could update any workspace column (e.g. plan, owner fields) | UPDATE limited to `name`; policy gets `WITH CHECK` | hardening.test.mjs "M1 audit fixes" |
| S-02 | High | Client assignments were updatable, allowing staff to re-point access | UPDATE revoked; delete + insert only | same |
| S-03 | High | Worker could update or delete observations (evidence tampering) | Observations append-only by trigger; worker select/insert only | same |
| S-04 | Medium | Any member could trigger an ownership check for any site in the workspace | Owner/admin, or the assigned client owner only; denied attempts audited | OwnershipTest |
| S-05 | Medium | `site_private_access()` answered for other workspaces when called directly | Answers only for the caller's workspace (or the owner) | hardening.test.mjs |
| S-05b | Low | App role could delete tickets (audit trail loss) | DELETE revoked | hardening.test.mjs |
| S-06 | High | A staff member could hand-write a quote with automated-repair params | DB trigger requires a matching executor-read draft | RepairsTest |
| S-07 | High | AI budget check ran outside the tenant context, so the cap never applied | Evaluated inside the job's RLS transaction | DiagnosticsTest |
| S-08 | High | Repair kill switch failed **open** when the global row was missing | Missing row = paused | `test_global_switch_starts_paused_and_fails_closed` |

PR #1 was not mergeable because of a README conflict with the FRD v0.3 commits on `main`. That merge is resolved and CI is green.

## Known gaps

| Gap | Risk | Plan |
| --- | --- | --- |
| Audit log is append-only but not hash-chained | A DB owner could rewrite history unnoticed | Hash chain plus an external anchor before pilot |
| `trace_id` is client-supplied | Log confusion, not access | Generate server-side |
| No account lockout or alerting on MFA failures (rate-limited only) | Slow brute force | Lockout + notify before pilot |
| No password reset or email change | Support load; risky manual resets | Build with one-time tokens |
| RLS can't stop arbitrary SQL run as `upfixy_app` | SQL injection would bypass tenant context | Parameterised queries only (enforced by review) |
| Real Cloudflare and OpenAI not exercised | Adapter mismatch | Test-zone and model runs after approval |
| Workspace closure with financial records | Can't delete (NFR-14) | Soft close (D-12) |
| Mail is log-only locally | — | Choose a provider at deployment |

## Rules for anyone working on this repo

- Never connect to an existing production server, a customer's DNS, or a payment gateway without written founder approval for that exact system.
- Never paste a real token into code, tests, issues or chat. Use synthetic values; tests use `93.184.216.34/.35` with a fake provider.
- Changing a migration's grants or policies needs a matching test in `db/test/` or the cross-tenant sweep.
