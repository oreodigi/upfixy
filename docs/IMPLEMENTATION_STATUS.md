# Implementation status

Requirements: [FRD v0.3](FRD.md). Updated after every completed feature. Nothing is deployed or connected to a real provider, payment gateway or server.

**States**

| State | Meaning |
| --- | --- |
| Not started | No code |
| In progress | Code being written; not on a PR yet |
| Implemented | Code on a PR; tests incomplete or not all ACs covered |
| Tests passed | Code and automated tests for the listed ACs pass locally and in CI. **Not** a milestone exit: real-provider, staging and UAT evidence may still be missing |
| Blocked | Cannot progress without a founder decision, an account or an approval (named) |
| Founder accepted | Founder has reviewed and accepted against the exit gate |

`main` at the time of writing: `f8bd759` (M1, M2, M3, M5, M7 consolidated through #7).

## Milestones

| Milestone | State | PRs | What blocks the exit gate |
| --- | --- | --- | --- |
| M1 Tenant foundation | Tests passed | #1 | Founder acceptance |
| M2 Boards and UX | Tests passed | #2, #7 | Real phone + screen reader check |
| M3 Public monitoring | Tests passed | #7 | Staging egress and load test (D-22) |
| M4 Read-only connectors | Tests passed (mocked providers) | #8 | **Blocked:** negative-scope tests on pilot test accounts (Larihost, Peak99, SKClouds) need founder-authorised test credentials |
| M5 Diagnostics + AI | Tests passed (fake model) | #7 | **Blocked:** model, region, retention, budget (§25.8) |
| M6 Commercial support | In progress | — | D-05, D-06, D-14, D-18, D-23 |
| M7 Controlled repairs | Tests passed (fake provider); disabled | #7 | **Blocked:** test-zone run, M6 payments, restore drill, C-1…C-5 |

## Features

| FRD ID | Feature | State | ACs evidenced | Implementation | Tests | PR |
| --- | --- | --- | --- | --- | --- | --- |
| ACC-001/002/005 | Signup, verification, MFA, invitations | Tests passed | AC-ACC-* (README list) | `AuthController`, `MfaController`, `InvitationController`, 0006/0007 | AccountSecurityTest, TenancyTest, e2e.py | #1 |
| ACC-003/004/006 | Workspaces, switcher, clients | Tests passed | IDOR sweep | 0001, 0004, `WorkspaceController`, `ClientController` | rls.test.mjs, CrossTenantSweepTest | #1 |
| ACC-007 | Immutable audit | Implemented | — | `audit_events` append-only | hardening.test.mjs | #1 (hash chain: not started) |
| AST-001/002/003 | Add by URL, ownership proof, duplicate claims | Tests passed | AC-AST-002.2…002.7 | 0008, `OwnershipController`, `ClaimController` | OwnershipTest | #1 |
| AST-004 | Asset graph per website | Tests passed | AC-AST-004.1 (zone + hosting shown separately) | `website_provider_links()`, `ProviderPanel` | ConnectorsTest `test_website_shows_provider_links_but_facts_need_ownership_proof` | #8 |
| BRD-001…006 | Boards, lists, icons, mobile, keyboard | Tests passed | AC-BRD-* | 0010, `BoardController`, web pages | BoardsM2Test, screens.mjs, keyboard.mjs | #2 |
| MON-001…003, 005…008 | Probes, scheduler, SSRF, freshness, incidents | Tests passed | AC-MON-* | `app/Monitoring/*`, 0011 | ProbesTest, TransportsTest, MonitoringTest | #7 |
| ALR-001 | Email + in-app alerts | Tests passed | — | `IncidentMail`, `NotificationController` | MonitoringTest | #7 |
| KB-001…003 | Help library (46), Check now | Tests passed | — | `packages/core/issues` | vectors, keyboard.mjs | #7 |
| INT-001 | Connector framework | Tests passed | AC-INT-001.1, AC-INT-001.2 | 0014, `app/Connectors/*`, `SyncRunner`, `upfixy:connectors` | ConnectorsTest (revocation, rotation, outage) | #8 |
| INT-002 | WHM reseller connector | Tests passed (mocked) | AC-INT-002.1, .2, .3, .4 | `WhmResellerAdapter` | ConnectorsTest (root token, cross-reseller, pagination, vhost scope, re-check on sync) | #8 |
| INT-003 | cPanel account connector | Tests passed (mocked) | AC-INT-003.1 | `CpanelAdapter` (allowlist) | ConnectorsTest `test_cpanel_account_reads_only_allowlisted_functions` | #8 |
| INT-004 | Cloudflare read-only connector | Tests passed (mocked) | AC-INT-004.1 | `CloudflareReadAdapter` | ConnectorsTest | #8 |
| INT-005 | Registrar connector | Not started | — | — | — | Blocked on D-03 |
| INT-006 | Import preview, selective import | Tests passed | AC-INT-006.1, AC-INT-006.2 | `ConnectorController::import` | ConnectorsTest `test_import_needs_explicit_selection_and_flags_ambiguous_client_mapping` | #8 |
| DX-001…004 | Analyze, rules, report, responsibility | Tests passed | AC-DX-* | 0012, `Diagnostics/*` | DiagnosticsTest | #7 |
| DX-005/006 | AI analysis + gateway | Tests passed (fake model); off | AC-DX-005.1…5, AC-DX-006.1/2 | `Diagnostics/Ai/*` | DiagnosticsTest, OpenAiClientTest | #7 |
| SVC-001…003 | Tickets, catalogue, quote approvals | Tests passed | AC-SVC-003.* | 0003, 0009, `QuoteController` | QuoteApprovalTest | #1 |
| SVC-004/005 | Razorpay, invoices, refunds | In progress | — | — | — | — |
| FIX-001/002 | Technician fix, post-fix verification | Implemented | — | Ticket stages, recheck | MonitoringTest | #7 |
| FIX-003…005 | Automated DNS fix | Tests passed (fake provider); disabled | AC-FIX-003.2…5, 004.1…4, 005.1…4 (003.1 needs a real test zone) | 0013, `Repairs/*` | RepairsTest (26) | #7 |

Details: [AI_DIAGNOSTICS.md](AI_DIAGNOSTICS.md), [REPAIR_ENGINE.md](REPAIR_ENGINE.md), [SECURITY.md](SECURITY.md), [TEST_RESULTS.md](TEST_RESULTS.md).

## M4 connector capability matrix (what the code asks for and calls)

| Connector | Credential | Calls (GET only) | Declared capabilities | Refused when |
| --- | --- | --- | --- | --- |
| Cloudflare | API token (Bearer) | `/user/tokens/verify`, `/zones`, `/zones/{id}/dns_records`, `/zones/{id}/dnssec` | zones.read, dns.read, dnssec.read | Token inactive or refused; no zones visible. Warns when it sees several zones |
| WHM reseller | `whm user:token`, port 2087 | `myprivs`, `listaccts` (chunked), `fetch_ssl_vhosts` | accounts.read, domains.read, ssl.read | `all` privilege (root-equivalent); no `list-accts`; any account or vhost not owned by this reseller (checked on **every** sync, every page) |
| cPanel | `cpanel user:token`, port 2083 | `DomainInfo/list_domains`, `SSL/installed_hosts` only | domains.read, ssl.read | Token refused; no main domain |

All response shapes are **EXT, unverified** until run against pilot test accounts (FRD §7 S12–S14).

## Where the code differs from the FRD (needs founder confirmation)

| # | FRD says | Code does |
| --- | --- | --- |
| C-1 | §25.4(8) separate rollback approval | Approving the quote pre-approves rollback of that one change |
| C-2 | ₹0 needs proven coverage | Executor refuses all ₹0 automated repairs until coverage records exist (M6) |
| C-3 | Failed verification is indeterminate | Roll back only when the failure is attributable to our change |
| C-4 | AC-FIX-004.1 | Provider error after write → re-read; roll back if landed, else one retry |
| C-5 | Short-lived DNS-edit capability | Zone-scoped token sealed to the executor; revocable, not short-lived |
| C-6 | §7: Cloudflare read and edit tokens separate | Done: read connector (M4) and repair DNS-edit connection (M7) are separate tables, keys and processes |
| C-7 | SCR-12 tax/merchant | Not shown until D-05/D-06/D-14 |
| C-8 | NFR-14 workspace deletion | Needs soft close (D-12) |
| C-9 | §14: connector alone is not domain control | Import never creates ownership; provider facts on a website need ownership proof or delegation |
