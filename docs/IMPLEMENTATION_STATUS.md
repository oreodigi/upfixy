# Implementation status

As of 18 October 2026. Requirements: [FRD v0.3](FRD.md). Nothing is deployed, merged to `main`, or connected to a real provider, payment gateway or server.

**Status words.** *Built* means the code exists and its automated tests pass locally. It does **not** mean the milestone exit gate is met or that the feature is production-ready. *Gate met* is used only when every exit criterion in FRD §22/§25.7 is evidenced.

## Milestones

| Milestone | Branch / PR | Status | Exit gate |
| --- | --- | --- | --- |
| M0 Discovery lock | — | D-04, D-07 approved; D-01, D-02 deferred | Met for design only |
| M1 Tenant foundation | `m1-hardening` / #1 (base `main`) | Built, audited, 6 findings fixed (S-01…S-05 plus ticket delete) | Not met: needs founder review and merge |
| M2 Boards and UX | `m2-boards` / #2 (base `m1-hardening`) | Built | Not met: real-device and screen-reader check pending |
| M3 Public monitoring | `m3-monitoring` / #3 (base `m2-boards`) | Built: worker, DNS/HTTP/TLS probes, incidents, alerts, 46-guide help library | Not met: needs a staging host for a real egress and load test (D-22) |
| M4 Read-only adapters | — | **Not started**: needs pilot test accounts (WHM reseller, cPanel, Cloudflare read-only) | Not met |
| M5 Diagnostics + AI | `m5-diagnostics` / #4 (base `m3-monitoring`) | Built: evidence snapshot, rule engine, OpenAI gateway (off until a model is chosen) | Not met: model, region and retention decisions open (§25.8); no live model test |
| M6 Commercial support | — | Partly built in M1 (quotes, approvals). **No payment writer, invoices or Razorpay** | Not met: D-05, D-06, D-14 unsigned |
| M7 Controlled repairs | `m7-repairs` / #5 (base `m5-diagnostics`) | Executor built against a fake provider; platform switch off and global kill switch paused by default | **Not met**: no synthetic Cloudflare test-zone run, no M6 payments, no restore drill, no pilot sign-off |

M7 was built ahead of M4 and M6 because the brief made it P0. It cannot run end to end until M6 records verified payments: today only a database owner can set `payment_verified`, and tests do exactly that.

## Traceability (FRD ID → implementation → tests → PR)

| FRD ID | Implementation | Tests | PR | Status |
| --- | --- | --- | --- | --- |
| ACC-001/002/005 | `AuthController`, `MfaController`, `InvitationController`, migrations 0006/0007 | AccountSecurityTest, TenancyTest, e2e.py | #1 | Built |
| ACC-003/004/006 | 0001, 0004, `WorkspaceController`, `ClientController` | TenancyTest, rls.test.mjs, CrossTenantSweep | #1 | Built |
| ACC-007 | `audit_events` (0003), append-only trigger | hardening.test.mjs | #1 | Built. Not hash-chained (see SECURITY.md) |
| AST-001/002/003 | 0008, `OwnershipController`, `ClaimController` | OwnershipTest, hardening.test.mjs | #1 | Built |
| AST-004 | — | — | — | Not started (needs M4) |
| BRD-001…006 | 0010, `BoardController`, `web/src/pages/*` | BoardsM2Test, BoardAndTicketTest, screens.mjs, keyboard.mjs | #2 | Built |
| BRD-007 | — | — | — | P1, not started |
| MON-001/002/003 | `app/Monitoring/*Probe`, custom DNS client | ProbesTest (30), TransportsTest (11) | #3 | Built |
| MON-005/007/008 | 0011, `CycleRunner`, `IncidentDebounce` | MonitoringTest (15), shared incident vectors, MonitorCapacityTest | #3 | Built |
| MON-006 | `url-guard` (core) and PHP port; pinned resolve; manual redirects | url-guard.test.ts (60), ProbesTest | #1, #3 | Built |
| MON-004/009 | — | — | — | P1, not started |
| ALR-001 | `notifications`, `IncidentMail`, `NotificationController` | MonitoringTest | #3 | Built (mail driver is log-only locally) |
| KB-001/002/003 | `packages/core/issues/issues.json` (46 guides), Help panel, Check now | vectors, keyboard.mjs | #2, #3 | Built |
| INT-001…006 | — | — | — | **Not started (M4)**. Only the DNS-edit connection used by FIX-003 exists |
| DX-001/002/003/004 | 0012, `SnapshotBuilder`, `RuleEngine`, `DiagnosisService`, SCR-11 | DiagnosticsTest (17) | #4 | Built |
| DX-005/006 | `Diagnostics/Ai/*` | DiagnosticsTest, OpenAiClientTest (4) | #4 | Built; disabled until a model is configured |
| SVC-001/002/003 | 0003, 0009, `QuoteController` | QuoteApprovalTest (11) | #1 | Built |
| SVC-004/005/006 | — | — | — | **Not started (M6)** |
| FIX-001/002 | Ticket stages and recheck | MonitoringTest | #3 | Partly built (no checklist yet) |
| FIX-003/004/005 | 0013, `app/Repairs/*`, `RepairController`, `pages/Repairs.tsx` | RepairsTest (24), CrossTenantSweep | #5 | Built against a fake provider; not test-zone verified |

Acceptance-criterion detail for M5 and M7 is in [AI_DIAGNOSTICS.md](AI_DIAGNOSTICS.md) and [REPAIR_ENGINE.md](REPAIR_ENGINE.md).

## Where the code differs from the FRD (needs founder confirmation)

None of these was decided silently; each defaults to the safer reading.

| # | FRD says | Code does | Why |
| --- | --- | --- | --- |
| C-1 | §25.4(8): rollback runs through a "separately approved rollback workflow" | Rollback is described in the quote the payer approves (`plan.rollback`), so approving the quote pre-approves rollback of that one change | A second approval mid-incident would leave a broken record live. **Confirm or reject.** |
| C-2 | §25.4(4): ₹0 work needs "proven provider-side coverage" | The executor **refuses every ₹0 repair** | Coverage contracts don't exist until M6. |
| C-3 | §25.4(7): failed verification is "indeterminate" | Rollback only happens when the failure is attributable to our change (the site was healthy before and broke after, or DNS shows a different value). Otherwise the attempt goes to *needs technician* | This avoids undoing a correct fix just because the site was already down for another reason. |
| C-4 | AC-FIX-004.1: a provider error *after* mutation | The executor re-reads the record. If our write landed, it is rolled back; if it did not land, it gets exactly one retry | This is AC-FIX-004.3 reconciliation applied to errors too. |
| C-5 | §25.2 SCR-09/10: "short-lived/scoped DNS-edit capability" | A zone-scoped Cloudflare token, sealed so the API cannot read it; revocable and rotatable; not short-lived | Short-lived tokens need a broker. Open with §25.8 "who holds the token". |
| C-6 | §22 order: M4 before M7 | M7 has its own executor-only Cloudflare adapter; there is no read-only Cloudflare connector yet | Brief marked M7 P0; M4 needs pilot accounts. |
| C-7 | SCR-12 price/tax/named merchant | Price is shown; tax and merchant are not | Depends on D-05/D-06/D-14 (M6). |
| C-8 | NFR-14 workspace deletion | Workspaces with quotes cannot be deleted (financial records) | Needs a soft-close design (D-12). |
