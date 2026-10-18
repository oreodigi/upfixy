# AI diagnostics (DX-001…006)

The design has three layers. Only the first two can produce a *Confirmed* cause.

| Layer | Code | Role |
| --- | --- | --- |
| A Evidence | `SnapshotBuilder` | Freezes the latest reading per check into an immutable snapshot with evidence IDs, times and freshness. Stale readings are kept but never cited as current. A snapshot hash makes repeat clicks reuse one run (60 min). |
| B Rules | `RuleEngine` (`rules-2026-10-18.1`) | Deterministic findings with confidence `confirmed / likely / possible / unknown`, responsibility, missing evidence and skipped checks. Example: an HTTP 503 with no hosting telemetry gives cause **unknown**, never "PHP" or "hosting". |
| C AI | `Ai/Gateway` → `OpenAiResponsesClient` → `Validator` | Optional plain-language explanation and hypotheses. Output is rejected unless it passes the validator. |

## AI contract

- **API:** OpenAI Responses with `text.format = json_schema`, `strict: true`, `store: false`, **no tools**. Prompt `dx-prompt-2026-10-18.1`; the schema version is recorded on every row.
- **Input (`PayloadBuilder`):** one website's fresh evidence only. Hostnames become `site-A`, `site-B`…; IP addresses become `[ip]`. Certificate names are reduced to "ours / wildcard / other". Website-controlled text (issuer, content type) is cut to 60 printable characters, stripped of markup and placed under `untrusted_text`. No tokens, emails, client names, account IDs or other websites. The exact payload and its hash are stored (AI-SEC-10).
- **Validator (fail closed):**
  - Every cited evidence ID must exist in *this* snapshot.
  - Confidence can never exceed the rule engine's confidence for the same evidence; *confirmed* is allowed only where a rule confirmed it.
  - Operations must come from the allowlist (`cloudflare.dns.update_address_record`). Any other operation, URL or command-like text rejects the output.
  - Unknown keys, hosts that aren't pseudonyms, and over-length text are rejected.
  - A rejected output stores the reasons, and the report shows rule findings only.
- **Controls:**
  - Per-workspace daily limit (20) and monthly cost cap (US$5), checked inside the tenant transaction.
  - Circuit breaker: 3 failures open it for 300 s.
  - 30 s timeout.
  - No retry storms.
- **Fallback:** "AI unavailable: measured findings only" whenever the key or model is missing, the budget is exceeded, the breaker is open, the call times out or the output is rejected.
- **Visibility:** model, prompt and rule versions are shown only in the staff audit view. Customers see the redacted report.
- **No repair path:** the AI can *name* an allowlisted operation as a suggestion. It cannot create drafts, quotes or attempts. There is no "Fix now" on the report.

## Acceptance criteria

| AC | Test | Result |
| --- | --- | --- |
| AC-DX-005.1 503 without telemetry | DiagnosticsTest: rules give cause `unknown`; an AI output asserting a hosting cause as confirmed is rejected | Pass |
| AC-DX-005.2 foreign evidence ID | DiagnosticsTest: an ID from another workspace or snapshot is rejected | Pass |
| AC-DX-005.3 prompt injection | DiagnosticsTest: hostile issuer text is neutralised in the payload; output proposing a non-allowlisted operation is rejected | Pass |
| AC-DX-005.4 outage | DiagnosticsTest/OpenAiClientTest: timeout, 5xx and breaker produce `unavailable` with rule findings intact | Pass |
| AC-DX-005.5 leakage | DiagnosticsTest: payload contains no real hostnames, IPs, emails, tokens or other sites | Pass |
| AC-DX-006.1 invalid output | DiagnosticsTest: malformed or uncited output is stored as `rejected` with versions | Pass |
| AC-DX-006.2 caps and duplicates | DiagnosticsTest: same snapshot reuses the run; daily limit and cost cap give `budget_exceeded` | Pass |

All of these use a fake AI client. **No call has been made to a real OpenAI model.**

## Model comparison

See [AI_MODEL_EVALUATION.md](AI_MODEL_EVALUATION.md): a fixed synthetic case set, a scorer that also catches hallucinations which pass the validator, and official prices for gpt-6-luna, gpt-6.1-sol and gpt-6-astra. Live run pending an approved key.

## Open (founder, §25.8)

- Model, region and data-processing terms, retention (we send `store: false`), per-analysis budget and plan quotas.
- Whether customers may see AI text or only staff (currently: both, redacted for customers).
- Token prices (`UPFIXY_AI_PRICE_IN/OUT`) must be set before the cost cap means anything; they default to 0.
