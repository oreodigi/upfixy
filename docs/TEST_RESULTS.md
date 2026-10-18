# Test results

Run on 18 October 2026, branch `m7-repairs` (which contains M1–M5), locally against PostgreSQL 16, PHP 8.3 and Node 22. CI runs the same suites on every push.

**Passing tests are not readiness.** Nothing here was run against a real Cloudflare zone, OpenAI model, payment gateway, staging host or real phone.

| Suite | Command | Result |
| --- | --- | --- |
| Shared rules (TS) | `npm test -w packages/core` | **180 passed** |
| Database / RLS (as runtime roles) | `npm test -w db` | **82 passed** |
| Web unit | `npm test -w web` | **23 passed** |
| Typecheck web + core | `npm run typecheck` | pass |
| API (PHPUnit) | `npm run test:api` | **234 passed, 2,679 assertions** |
| End to end (running servers) | `python3 scripts/e2e.py` | **38/38 passed** |
| Screens + axe WCAG 2.1 AA | `node scripts/screens.mjs` | **22 screens × 3 widths (320/390/1440 px): 66/66 clean, 0 overflow** |
| Keyboard only | `node scripts/keyboard.mjs` | **31 PASS** |
| Performance (M2, throttled phone, 10 cold runs) | `node scripts/perf.mjs` | p95: Websites 1.86 s, Clients 1.89 s, Overview 1.85 s |
| Monitor capacity | `MonitorCapacityTest` | 1,000 check cycles in 3.2 s (fakes; not a network load test) |

## API tests by area

| Class | Tests | Covers |
| --- | --- | --- |
| SharedVectorsTest | 58 | PHP matches the TS rules (health, URL guard, debounce, tickets) |
| ProbesTest | 30 | DNS/HTTP/TLS result codes, redirects, SSRF, redaction |
| RepairsTest | 26 | FIX-003…005 (see [REPAIR_ENGINE.md](REPAIR_ENGINE.md)) |
| DiagnosticsTest | 17 | DX-001…006, AC-DX-005/006 (see [AI_DIAGNOSTICS.md](AI_DIAGNOSTICS.md)) |
| MonitoringTest | 15 | Claims, leases, incidents, recheck limits, alerts |
| AccountSecurityTest | 12 | Signup, verification, MFA, step-up |
| TransportsTest | 11 | Real sockets against local DNS/HTTP/TLS test servers |
| QuoteApprovalTest | 11 | Fingerprint-bound approvals, voiding, payer rules |
| BoardsM2Test | 11 | Board data, lanes, saved views |
| OwnershipTest | 9 | DNS/HTML proof, claim boundaries, S-04 |
| TenancyTest, BoardAndTicketTest | 8 + 8 | Roles, assignments, ticket transitions |
| OnboardingTest | 7 | Add-by-URL, duplicate claims |
| OpenAiClientTest | 4 | Strict schema, no tools, `store:false`, key never leaked |
| CrossTenantSweepTest | 3 | Every route as another tenant and as under-privileged roles |
| Other | 4 | Request transactions, ticket-state parity, capacity |

## Negative and failure-path coverage (required by the brief)

- **Cross-tenant:** every route, including M3/M5/M7, tested as another tenant (404, no data), plus billing and client-staff denials.
- **AI:**
  - Hallucinated cause.
  - Foreign evidence ID.
  - Prompt injection in page text.
  - Non-allowlisted operation.
  - Timeout / 5xx / breaker.
  - Leakage of hostnames, IPs or emails.
  - Malformed output.
  - Budget.
- **Repairs:**
  - Forbidden record types and operations.
  - Revoked, expired or changed approvals.
  - Record changed after approval.
  - Duplicate requests.
  - Two attempts on one record.
  - Crash after write.
  - Timeout with unknown outcome.
  - Rollback against someone else's edit.
  - Verification failure (DNS and HTTP).
  - Kill switches.
  - Revoked or rotated tokens.
  - Expired ownership.
  - Missing MFA.
  - ₹0.

## Not yet tested

- Real Cloudflare API (AC-FIX-003.1 needs an approved test zone).
- Real OpenAI model.
- Payment webhook (M6 not built).
- Staging deployment, backup/restore drill, multi-region probes.
- Real devices and screen readers.
- Load beyond the local capacity test.
