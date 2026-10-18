# Test results

Run on 19 Oct 2026 (IST) on branch `hardening`, which contains `main` + M4 + M6 + hardening. Run locally against PostgreSQL 16, PHP 8.3 and Node 22. CI runs the same suites on every push.

**Passing tests are not readiness.** Nothing here was run against:
- a real Cloudflare, WHM or cPanel account;
- Razorpay (sandbox or live);
- an OpenAI model;
- a staging host;
- a real phone.

| Suite | Command | Result |
| --- | --- | --- |
| Shared rules (TS) | `npm test -w packages/core` | **180 passed** |
| Database / RLS / grants | `npm test -w db` | **86 passed** |
| Web unit | `npm test -w web` | **23 passed** |
| Typecheck web + core | `npm run typecheck` | pass |
| API (PHPUnit) | `npm run test:api` | **314 passed, 4,470 assertions** |
| End to end (running servers) | `python3 scripts/e2e.py` | **40/40 passed** |
| Screens + axe WCAG 2.1 AA | `node scripts/screens.mjs` | **26 screens × 3 widths: 78/78 clean, 0 overflow** |
| Keyboard only | `node scripts/keyboard.mjs` | **31 PASS** |
| Restore drill | `ops/restore-drill.sh` | **passed** (see [BACKUP_RECOVERY.md](BACKUP_RECOVERY.md)) |
| AI model comparison | `php artisan upfixy:ai-eval` | **not run**: needs an approved API key ([AI_MODEL_EVALUATION.md](AI_MODEL_EVALUATION.md)) |

## API tests by area

| Class | Tests | Covers |
| --- | --- | --- |
| SharedVectorsTest | 58 | PHP matches the TS rules |
| RepairPolicyTest | 30 | Allowlist edge values, scope, plan tampering |
| ProbesTest | 30 | DNS/HTTP/TLS codes, redirects, SSRF |
| RepairsTest | 26 | FIX-003…005 |
| ConnectorsTest | 18 | INT-001…004/006, AST-004, AC-INT-* |
| DiagnosticsTest | 18 | DX-001…006, AC-DX-*; AI crash fallback |
| MonitoringTest | 15 | Claims, incidents, alerts |
| PaymentsTest | 14 | SVC-002…005, AC-SVC-002.1, 003.3, 004.1–3, 005.1, live lock |
| AccountSecurityTest | 12 | Signup, verification, MFA, rate limits |
| TransportsTest | 11 | Real sockets against local servers |
| QuoteApprovalTest | 11 | Fingerprint approvals, payer rule |
| BoardsM2Test | 11 | Boards |
| SecurityHardeningTest | 9 | Audit chain, MFA lockout, password reset, soft close, failure scenarios |
| OwnershipTest | 9 | Proof, claims |
| AiEvalTest | 8 | Model-comparison scorer |
| TenancyTest, BoardAndTicketTest | 8 + 8 | Roles, transitions |
| OnboardingTest | 7 | Add by URL |
| OpenAiClientTest | 4 | Strict schema, no tools, `store:false` |
| CrossTenantSweepTest | 3 | Every route × other tenant / other client (2,217 assertions) |
| Other | 4 | Transactions, parity, capacity |

## Failure scenarios covered

| Failure | Tested behaviour |
| --- | --- |
| Provider outage | Exponential backoff; only that connector's data goes stale; monitoring untouched |
| Revoked or rotated credential mid-job | The in-flight result is refused |
| Crashed worker | Lease expires, the work is picked up again, and retries are counted |
| AI timeout, 5xx, breaker or unexpected crash | Measured report kept; internal error text never shown |
| Razorpay down at checkout | Nothing half-made; retry works |
| Webhook duplicates, out of order, mismatched, unsigned, or failing mid-way | One order; payment never undone; alert; 401; nothing recorded and the retry succeeds |
| Repair failures | Record changed; uncertain write; crash after write; rollback conflict; verification failure |

## Not yet tested

- Real provider sandboxes and a model run (both need founder approval).
- Staging deployment and load.
- Multi-region probes.
- Real devices with screen readers.
- Scheduled backups and a production-size restore.
