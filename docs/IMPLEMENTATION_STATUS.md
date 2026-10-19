# Implementation status

Requirements: [FRD v0.3](FRD.md). Updated after every completed feature. Nothing is deployed or connected to a real provider, payment gateway or server.

**States**

| State | Meaning |
| --- | --- |
| Not started | No code |
| In progress | Code being written; not on a PR yet |
| Implemented | Code on a PR; tests incomplete or some ACs not covered |
| Tests passed | Code and automated tests for the listed ACs pass locally and in CI. **Not** a milestone exit: real-provider, staging and UAT evidence may still be missing |
| Blocked | Cannot progress without a named founder decision, account or approval |
| Founder accepted | Founder reviewed and accepted against the exit gate |

`main` = `f8bd759` (M1, M2, M3, M5, M7, via #1 and #7). Open, stacked: **#8** M4 (base `main`) → **#10** M6 (base `m4-connectors`) → **#11** hardening + AI evaluation (base `m6-payments`).

## Milestones

| Milestone | State | PRs | What blocks the exit gate |
| --- | --- | --- | --- |
| M1 Tenant foundation | Tests passed | #1 (merged) | Founder acceptance |
| M2 Boards and UX | Tests passed | #2, #7 (merged) | Real phone + screen reader check |
| M3 Public monitoring | Tests passed | #7 (merged) | Staging egress and load test (D-22) |
| M4 Read-only connectors | Tests passed (mocked providers) | #8 | **Blocked:** negative-scope tests on pilot test accounts need founder-authorised test credentials (Larihost, Peak99, SKClouds) |
| M5 Diagnostics + AI | Tests passed (fake model); eval harness ready | #7 (merged), #11 | **Blocked:** live model comparison and §25.8 model/region/retention/budget decisions |
| M6 Commercial support | Tests passed (scripted Razorpay, test mode only) | #10 | **Blocked:** D-05, D-06, D-08, D-13, D-14, D-18, D-23; Razorpay sandbox account |
| M7 Controlled repairs | Tests passed (fake provider); disabled | #7 (merged), #11 | **Blocked:** test-zone run, C-1…C-5, restore drill on staging, pilot sign-off |
| Security hardening | Tests passed | #11 | Off-host anchors, webhook service split (see SECURITY.md) |
| UI redesign v1 (UX-01…08) | Tests passed; awaiting founder review | #12 | Founder review of phase-2 screenshots; real phone + screen reader check; payment/integration screens are fully live only once #8/#10 merge ([UI_REDESIGN_STATUS.md](UI_REDESIGN_STATUS.md)) |

## Features

| FRD ID | Feature | State | ACs evidenced | Implementation | Tests | PR |
| --- | --- | --- | --- | --- | --- | --- |
| ACC-001/005 | Signup, verification, invitations | Tests passed | AC-ACC-001.* | `AuthController`, `InvitationController` | AccountSecurityTest, e2e | #1 |
| ACC-002 | MFA, step-up, **lockout**, **password reset** | Tests passed | ACC-002 + S-11/S-12 | `Mfa`, 0016 | AccountSecurityTest, SecurityHardeningTest | #1, #11 |
| ACC-003/004/006 | Workspaces, switcher, clients | Tests passed | IDOR sweep | 0001, 0004 | rls.test.mjs, CrossTenantSweepTest | #1 |
| ACC-007 | Audit, **hash chain + anchors** | Tests passed | S-10 | 0016, `upfixy:audit` | SecurityHardeningTest | #1, #11 |
| NFR-14 | Workspace soft close / reopen | Tests passed | — | `close_workspace()`, `WorkspaceClose.tsx` | SecurityHardeningTest | #11 |
| AST-001/002/003 | Add by URL, ownership proof | Tests passed | AC-AST-002.2…002.7 | 0008 | OwnershipTest | #1 |
| AST-004 | Asset graph per website | Tests passed | AC-AST-004.1 | `website_provider_links()` | ConnectorsTest | #8 |
| BRD-001…006 | Boards, lists, mobile, keyboard | Tests passed | AC-BRD-* | 0010, web | BoardsM2Test, screens, keyboard | #2 |
| MON-001…003, 005…008 | Probes, incidents, SSRF, freshness | Tests passed | AC-MON-* | `app/Monitoring` | ProbesTest, TransportsTest, MonitoringTest | #7 |
| ALR-001, KB-001…003 | Alerts, help library | Tests passed | — | 0011, `issues.json` | MonitoringTest | #7 |
| INT-001 | Connector framework | Tests passed | AC-INT-001.1, .2 | 0014, `app/Connectors` | ConnectorsTest | #8 |
| INT-002 | WHM reseller | Tests passed (mocked) | AC-INT-002.1–.4 | `WhmResellerAdapter` | ConnectorsTest | #8 |
| INT-003 | cPanel account | Tests passed (mocked) | AC-INT-003.1 | `CpanelAdapter` | ConnectorsTest | #8 |
| INT-004 | Cloudflare read-only | Tests passed (mocked) | AC-INT-004.1 | `CloudflareReadAdapter` | ConnectorsTest | #8 |
| INT-005 | Registrar | Blocked (D-03) | — | — | — | — |
| INT-006 | Import preview, selective import | Tests passed | AC-INT-006.1, .2 | `ConnectorController::import` | ConnectorsTest | #8 |
| DX-001…004 | Diagnosis, rules, report | Tests passed | AC-DX-* | 0012 | DiagnosticsTest | #7 |
| DX-005/006 | AI analysis, gateway, **eval harness** | Tests passed (fake model); off | AC-DX-005.1–5, 006.1–2 | `Diagnostics/Ai`, `Diagnostics/Eval` | DiagnosticsTest, OpenAiClientTest, AiEvalTest | #7, #11 |
| SVC-001 | Tickets | Tests passed | AC-SVC-001.1 | 0003 | BoardAndTicketTest | #1 |
| SVC-002 | Catalogue ₹0/₹100/₹200/₹300 + custom; **₹0 needs coverage** | Tests passed | AC-SVC-002.1, 003.3 | 0009, 0015 coverage | PaymentsTest, QuoteApprovalTest | #1, #10 |
| SVC-003 | Exact-resource approvals | Tests passed | AC-SVC-003.1–.4 | 0009 | QuoteApprovalTest | #1 |
| SVC-004 | Razorpay checkout + webhooks + reconciliation | Tests passed (scripted, test mode) | AC-SVC-004.1–.3 | 0015, `app/Payments`, webhook | PaymentsTest | #10 |
| SVC-005 | Receipts and refunds (**not GST invoices**) | Implemented | AC-SVC-005.1 partly (GST blocked by D-14) | `payment_receipts`, `refunds` | PaymentsTest | #10 |
| SVC-006 | Subscriptions | Not started (P1) | — | — | — | — |
| FIX-001/002 | Technician fix, verification | Implemented | — | Ticket stages, recheck | MonitoringTest | #7 |
| FIX-003…005 | Automated DNS fix | Tests passed (fake provider); disabled | AC-FIX-003.2–5, 004.1–4, 005.1–4 | 0013, `Repairs` | RepairsTest, RepairPolicyTest | #7, #11 |
| NFR backup | Encrypted backup + restore drill | Tests passed (local drill) | — | `ops/` | restore-drill log | #11 |

Details: [Architecture](ARCHITECTURE.md), [AI diagnostics](AI_DIAGNOSTICS.md), [AI model evaluation](AI_MODEL_EVALUATION.md), [Repair engine](REPAIR_ENGINE.md), [Payments](PAYMENTS.md), [Security](SECURITY.md), [Backup](BACKUP_RECOVERY.md), [Test results](TEST_RESULTS.md).

## M4 connector capability matrix (what the code asks for and calls)

| Connector | Credential | Calls (GET only) | Declared capabilities | Refused when |
| --- | --- | --- | --- | --- |
| Cloudflare | API token (Bearer) | `/user/tokens/verify`, `/zones`, `/zones/{id}/dns_records`, `/zones/{id}/dnssec` | zones.read, dns.read, dnssec.read | Token inactive or refused; no zones. Warns when it sees several zones |
| WHM reseller | `whm user:token`, :2087 | `myprivs`, `listaccts` (chunked), `fetch_ssl_vhosts` | accounts.read, domains.read, ssl.read | `all` privilege; no `list-accts`; any account or vhost not owned by this reseller (every sync, every page) |
| cPanel | `cpanel user:token`, :2083 | `DomainInfo/list_domains`, `SSL/installed_hosts` only | domains.read, ssl.read | Token refused; no main domain |

All response shapes are **EXT, unverified** until run against pilot test accounts (FRD §7 S12–S14).

## Where the code differs from the FRD (needs founder confirmation)

| # | FRD says | Code does |
| --- | --- | --- |
| C-1 | §25.4(8) separate rollback approval | Approving the quote pre-approves rollback of that one change |
| C-2 | ₹0 needs proven coverage | Manual ₹0 work: needs an active coverage contract, named and fingerprinted in the quote. **Automated** ₹0 repairs: still refused |
| C-3 | Failed verification is indeterminate | Roll back only when the failure is attributable to our change |
| C-4 | AC-FIX-004.1 | Provider error after write → re-read; roll back if landed, else one retry |
| C-5 | Short-lived DNS-edit capability | Zone-scoped token sealed to the executor; revocable, not short-lived |
| C-7 | SCR-12 tax and named merchant | Merchant name shown; receipts state "not a GST tax invoice" until D-14 |
| C-8 | NFR-14 deletion | Soft close + 30-day reopen; final deletion waits for D-12 |
| C-9 | §14 connector ≠ domain control | Import never creates ownership; provider facts need proof |
| C-10 | §19.1 merchant identity on quotes | Recommendation D-06 implemented as "each agency is its own Razorpay merchant"; platform commission (D-05) not implemented |

## UI branch additions (#12)

- SVC-001: a fix request can link the diagnosis it came from (`tickets.diagnostic_run_id`, migration 0018, same-website trigger). `POST tickets` accepts `diagnostic_run_id`; `GET tickets/{id}` and `GET diagnostics/{id}` return the link. Tests: DiagnosticsTest (2 new).
- AC-UI-12: `is_demo` workspace flag (migration 0017) drives the Demonstration data banner.
- `GET boards/metrics` (read-only aggregation) for dashboard charts. Test: MetricsTest.
