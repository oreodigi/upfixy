# Repair engine (FIX-003…005)

**Readiness: not released.** The platform flag `UPFIXY_REPAIRS_ENABLED` defaults to off, and the global kill switch row is created *paused*. The real Cloudflare adapter has **not** been run against any zone. Every test uses a fake provider.

## Scope (P0 allowlist)

The only operation is `cloudflare.dns.update_address_record`: change the content of **one existing `A` or `AAAA` record** to a validated public address. TTL and proxy status stay as they are.

`Policy` refuses all of the following, and so does the executor even if a model or a person proposes it:
- Every other record type (MX, NS, SOA, CAA, TXT, CNAME, SRV and the rest).
- Wildcard names, and names outside the website's hostnames.
- Proxy, TTL or DNSSEC changes.
- Deletions, creations and bulk edits.
- Private, reserved, loopback or documentation addresses.
- A change where the old and new values are the same.
- Any value that isn't exactly one canonical address: no whitespace, no leading zeros, lower-case shortest IPv6, and no IPv4-mapped or IPv4-compatible IPv6 in AAAA records. These edge cases are covered by `RepairPolicyTest` (30 cases).

There is no SSH, no shell, and no WHM or cPanel write.

## Flow and where each gate lives

| Step | What happens | Enforced in |
| --- | --- | --- |
| Diagnose | Staff run Analyze; findings are evidence-cited | M5 |
| Propose | Staff pick a connected zone, record ID and new address (fresh MFA) | `RepairController::draft`, `create_repair_draft()` |
| Preview | The **executor** reads the live record and writes the canonical plan: zone, record, before, after, TTL, proxy, verification and rollback | `Executor` draft step; the API never builds the plan |
| Quote | The server turns the plan into a `dns.record_fix` quote. A DB trigger (S-06) rejects any automated quote whose params don't match a ready executor-read draft | `quotes_repair_plan_guard` |
| Approve | The payer approves the exact fingerprint (fresh MFA). Changing the quote voids the approval | 0009 approvals |
| Pay / coverage | `payment_verified` (M6 webhook only). ₹0 is refused until coverage records exist | Executor gates |
| Request | Staff request execution with an idempotency key (fresh MFA). One attempt per approval; one active attempt per record | `request_repair()`, unique indexes |
| Preflight | Re-checks everything below, then reads the live record. If it differs from the approved *before* value: refuse, no write | `Executor::gates()` |
| Execute | Exactly one `PATCH /zones/{zone}/dns_records/{id}`, at most 3 writes per attempt (including rollback) | `CloudflareDnsProvider` |
| Verify | Re-read the provider, then public DNS shows the new value, then the website check runs, within a 900 s window | `Executor::verify` |
| Resolve / escalate | Resolved only when checks pass; otherwise roll back (if attributable) or *needs technician*. Ticket moves accordingly | `executor_move_ticket()` |

Gates re-checked at preflight **and again before every write**:
- Platform flag, global switch and workspace switch.
- Connection not revoked or rotated since approval.
- Ownership proof or delegation still current (D-07).
- Approval not voided or expired.
- Payment verified.
- Ticket still `ready_for_work`.
- Plan fingerprint unchanged.
- Change window.

## The ten required controls

| # | Control | Implementation | Test |
| --- | --- | --- | --- |
| 1 | Exact permissions | Owner/admin connect; staff assigned to the client draft and request; customers approve. RLS plus `app_is_staff_for` | `test_execution_needs_a_live_single_use_paid_approval`, CrossTenantSweep |
| 2 | Ownership/delegation | Checked at draft, preflight and before write | `test_expired_ownership_proof_stops_queued_work` |
| 3 | Fresh MFA | `mfa.fresh` on connect, rotate, revoke, draft, execute, approve, resume | `test_privileged_repair_steps_need_fresh_mfa` |
| 4 | Immutable fingerprint | Plan is executor-written; quote params must equal it (S-06); approval binds the fingerprint | `test_hand_written_automated_quotes_are_impossible`, `test_changing_the_quote_voids_consent` |
| 5 | Explicit approval incl. ₹0 | Single-use approval required; ₹0 refused (C-2) | `test_expired_approval_is_refused`, `test_zero_rupee_automated_repairs_cannot_run_before_coverage_exists` |
| 6 | Payment or coverage | `payment_verified` only | as above |
| 7 | Backup and rollback | Before-image in the plan and events; compare-before-rollback; never overwrite someone else's edit | `test_provider_error_after_mutation_rolls_back_to_the_exact_before_image`, `test_rollback_does_not_overwrite_a_record_someone_else_changed` |
| 8 | Concurrency and idempotency | Idempotency key; one attempt per approval; partial unique index per record; leases; `$prepared` resume reconciles instead of re-writing | `test_duplicate_requests_are_idempotent`, `test_two_attempts_on_one_record_cannot_run_together`, `test_crash_after_sending_a_write_never_causes_a_blind_second_write` |
| 9 | Reconciliation and verification | Uncertain write → re-read → roll back if landed, else one retry; DNS + HTTP verification; not visible → indeterminate | `test_timeout_with_unknown_outcome_is_reconciled_before_one_retry`, `test_change_not_visible_in_public_dns_is_indeterminate_not_success`, `test_successful_api_call_but_failing_website_stays_unresolved` |
| 10 | Audit and kill switch | Append-only `repair_events` link draft, evidence, quote, approval, payment, provider request IDs and result; three switches; resume needs owner + MFA | `test_audit_links_evidence_approval_payment_provider_and_result`, `test_kill_switches_stop_writes_even_after_approval`, `test_global_switch_starts_paused_and_fails_closed` |

## Secrets

- The API encrypts the Cloudflare token with a libsodium **sealed box** using the executor's public key (`UPFIXY_REPAIRS_PUBLIC_KEY`). The API can't decrypt it.
- Only the executor host has `UPFIXY_REPAIRS_KEYPAIR`. It gets the ciphertext through `executor_sealed_secret()`.
- `secret_sealed` is not granted to `upfixy_app` or `upfixy_executor`.
- Tokens never appear in responses, logs, events or AI payloads (tested).
- Key generation: `php artisan upfixy:repairs --keygen`.

## Operations

```
php artisan upfixy:repairs --once        # one executor pass (cron or loop)
php artisan upfixy:repairs --pause  "reason"
php artisan upfixy:repairs --resume "reason"   # global switch; owner sign-off required by policy
```

## Before release (M7 exit gate)

1. Founder decisions C-1…C-5 ([IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md)) and the §25.8 items.
2. An owned, disposable Cloudflare test zone and a zone-scoped token, approved by the founder. Then run AC-FIX-003.1 against the real API (verify the PATCH body, error shapes and rate limits).
3. M6 payment webhook, so `payment_verified` has a real writer.
4. Restore drill, staging run, pilot UAT, and explicit release of the global switch.
