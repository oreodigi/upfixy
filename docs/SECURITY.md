# Security

Threat model per FRD §20 and §25.5. This file records the controls in place, review findings and known gaps. No external penetration test has been done.

## Boundaries

- **Tenant:** RLS on every tenant table, plus composite FKs. A cross-tenant sweep calls **every** API route as another tenant and as an under-privileged role in the same tenant (2,217 assertions), and checks that no row changes and nothing leaks.
- **Roles:** four database roles with least privilege. Runtime roles can't bypass RLS, own tables or read password hashes, MFA secrets, sealed tokens or merchant secrets.
  - `upfixy_app`: API.
  - `upfixy_worker`: monitor, connector sync, payment events.
  - `upfixy_executor`: repairs.
  - `upfixy_owner`: migrations only.
- **Public vs private (D-07):** public checks need no proof. Private readings, provider facts on a website, diagnostics with private evidence, approvals and repairs need current proof or delegation. Importing from a connector never grants ownership.
- **No root WHM (D-04):** WHM tokens with the `all` privilege, or that can see any account not owned by the reseller (on any page, in any sync), are refused, wiped and nothing they returned is kept.
- **SSRF (MON-006):** applies to probes **and** provider hosts.
  - Every resolved IP is checked; connections are pinned to the vetted address.
  - No redirects; allowed ports only.
  - Connector transport is GET-only by type.
- **Secrets (none readable by the API that stores them):**

| Secret | Sealed or encrypted to | Readable by |
| --- | --- | --- |
| Provider read tokens | Connector worker key | Connector worker only |
| DNS-edit tokens | Repair executor key | Repair executor only |
| MFA secrets, merchant secrets | `APP_KEY` | Only through narrow functions |

  Nothing secret is in the repo, logs, audit rows, AI payloads or API responses (tested).
- **Payments:** only a signature-verified webhook or server-side reconciliation marks a payment verified, through worker-role functions the API role cannot call. Live charging is blocked three ways, and the database lock fails closed.

## Findings and fixes

### Earlier round (M1–M7)

| ID | Severity | Finding | Fix |
| --- | --- | --- | --- |
| S-01 | High | App role could update any workspace column | UPDATE limited to `name` |
| S-02 | High | Client assignments updatable | UPDATE revoked |
| S-03 | High | Worker could rewrite observations | Append-only |
| S-04 | Medium | Any member could trigger ownership checks | Owner/admin/assigned client owner |
| S-05 | Medium | `site_private_access()` answered for other workspaces | Caller's workspace only |
| S-05b | Low | App could delete tickets | Revoked |
| S-06 | High | Hand-written automated-repair quotes | DB trigger requires an executor-read draft |
| S-07 | High | AI budget ignored (read outside tenant context) | Read inside the job's RLS transaction |
| S-08 | High | Repair kill switch failed open with no control row | Missing row = paused |

### This round (M4, M6, hardening)

| ID | Severity | Finding | Fix | Test |
| --- | --- | --- | --- | --- |
| S-09 | High | Payment live-mode guard failed **open** with no control row (`NOT NULL` is NULL) | `coalesce(…, false)` | PaymentsTest live test, db grants test |
| S-10 | Medium | Audit trail append-only but rewritable by a DB owner; client-chosen `trace_id` | SHA-256 hash chain, ids under one lock, external anchors, server trace ids; app can't set id/hash/time | SecurityHardeningTest (2) |
| S-11 | Medium | MFA codes only rate-limited per IP (6/min), no account lockout | 5 failures → 15 min lock (doubling, max 24 h), all sessions, pending challenge tokens dropped, email | SecurityHardeningTest (2), AccountSecurityTest |
| S-12 | Medium | No password reset (risky manual resets) | Hashed one-time 30-min links, newest only, 3/h, no enumeration, signs out everywhere, MFA still required | SecurityHardeningTest (2) |
| S-13 | Medium | Workspaces with financial records could not be closed; access and background work continued | Soft close: `app_role()` is NULL for closed workspaces (every check fails closed); work stopped; credentials destroyed | SecurityHardeningTest |
| S-14 | Low | Repair policy accepted whitespace-padded and IPv4-mapped AAAA values (API trimmed first, so not exploitable) | Exact canonical values only; native IPv6 only | RepairPolicyTest (30) |
| S-15 | Low | Deleting an imported website broke the asset invariant (500) | Trigger returns the asset to "discovered" | ConnectorsTest |
| S-16 | Low | Billing role could not see orders it must refund | Policy includes billing | PaymentsTest |

## Known gaps

| Gap | Risk | Plan |
| --- | --- | --- |
| Webhook handler runs in the API process with worker DB credentials | A compromised API process could call payment functions | Split into a small separate service at deployment |
| Password sign-in has per-IP limits but no per-account lockout | Slow distributed guessing | Per-account progressive delay, weighed against lockout-DoS |
| Anchors are only written to a local file | A host compromise could rewrite both | Ship anchors off-host daily (email/object storage) |
| Real Cloudflare, WHM, cPanel, Razorpay and OpenAI APIs not exercised | Adapter mismatch | Sandbox/test-account runs after approval |
| Mail is log-only locally | — | Provider at deployment; queue security emails |
| No WAF, CSP report or dependency scanning in CI | Supply-chain/browser risks | Add before pilot |
| Final deletion of closed workspaces | Retention undefined | D-12 |

## Rules for anyone working on this repo

- Never connect to an existing production server, a customer's DNS, a provider account or a payment gateway without written founder approval for that exact system.
- Never paste a real token into code, tests, issues or chat.
- Changing grants or policies needs a matching test in `db/test/` or the cross-tenant sweep. The sweep fails on any new route that isn't mapped.
