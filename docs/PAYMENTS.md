# Payments and service workflow (M6, SVC-002…005)

**Readiness: works end to end against a scripted Razorpay in TEST mode only. Live charging is impossible.** It stays that way until the founder signs D-05, D-06, D-08, D-13, D-14, D-18 and D-23.

## Services

| Tier | Price | Rule |
| --- | --- | --- |
| Provider-covered | ₹0 | Needs an **active coverage contract** for the website or client (owner/admin + MFA). The quote names the contract, and its id is part of the fingerprint the customer approves. Revoking the contract blocks work from starting. Customer approval is still required (AC-SVC-003.3). No checkout. |
| Domain/DNS | ₹100 | Price from the catalogue; the request can't change it |
| cPanel | ₹200 | Same |
| Code fix | ₹300 | Same; a bigger job needs a new quote and a new approval (AC-SVC-002.1) |
| Custom | Quoted | Positive amount required |

The scope of each tier (D-08) is still a draft.

## Flow

```
quote (fingerprint) → payer approves (MFA) → checkout (payer only, idempotent) → Razorpay Checkout
   → browser "success" recorded but NOT trusted (AC-SVC-004.2)
   → signed webhook  ─┐  checks amount, currency, merchant, order → paid_verified → receipt
   → reconciliation ──┘  (server asks Razorpay for still-pending orders)
   → approval.payment_verified → staff can start work
```

## Payment states

`created → pending → paid_verified | failed`. After payment: `→ refunded | partially_refunded`.

- A failed payment allows a new order.
- A late `payment.failed` never undoes a verified payment.

## Webhooks

- **Endpoint:** `POST /api/v1/webhooks/razorpay/{workspace_id}`. Each workspace (merchant) has its own secret.
- **Authentication:** only the HMAC-SHA256 over the raw body. A bad signature and an unknown workspace get the same 401.
- **Inbox-first:** each event id is stored before processing. Duplicates do nothing (AC-SVC-004.1), and concurrent copies serialise on the key.
- **Mismatches:** any amount, currency or merchant mismatch leaves the order unfulfilled and raises a billing alert (AC-SVC-004.3).
- **Database access:** writes run as `upfixy_worker` functions. The API role cannot set `payment_verified`.
- **Events handled:** `payment.captured`, `order.paid`, `payment.failed`, `refund.processed`, `refund.failed`.

## Merchant (D-06 recommendation, not a decision)

Each agency workspace is its own merchant with its own Razorpay test keys.
- The owner enters the keys with fresh MFA.
- The key secret and webhook secret are encrypted with `APP_KEY` and never returned.

The platform commission (D-05) and Razorpay Route are **not** implemented.

## Live lock (three independent locks)

1. `UPFIXY_PAYMENTS_LIVE=false` (config).
2. `payment_controls.live_allowed = false`, changeable only by the schema owner. A missing row counts as off (S-09).
3. Key-prefix checks: `rzp_live_` keys are refused by the API, by the database constraint and by the HTTP client before any network call.

## Receipts and refunds

- **Receipts:** gap-free numbering per merchant (`UPX-YYYY-000001`). Seller, buyer, scope and amount are copied from the quote. Every receipt says **"Payment receipt only. Not a GST tax invoice"** until D-14 (invoicing entity, GSTIN, place of supply) is decided with a CA.
- **Refunds:** requested by owner or billing (MFA). A request is never marked refunded until Razorpay confirms it. Refunds made in the Razorpay dashboard are recorded too. When a refund is *owed* is D-13.

## Founder decisions needed before any real charge

D-05 (commission), D-06/D-18 (merchant of record), D-08 (tier scopes), D-13 (refund policy), D-14 (GST/invoicing entity), D-23 (verification and dispute window). Plus a Razorpay **sandbox** account to run the flow against the real API.
