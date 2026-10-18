-- M6 commercial workflow: coverage for ₹0 work, Razorpay test-mode payments, payment receipts,
-- refunds. FRD SVC-002…005, §19, AC-SVC-002.1, 003.3, 004.1…3, 005.1.
--
-- LIVE CHARGING IS IMPOSSIBLE until the founder resolves D-05, D-06, D-13, D-14, D-18, D-23:
--  1. payment_controls (global row, schema owner only) starts with live_allowed = false;
--  2. a merchant profile can be in 'live' mode only while that row allows it (trigger);
--  3. the API config flag UPFIXY_PAYMENTS_LIVE defaults to false and the client refuses rzp_live_ keys.
-- Payment receipts are NOT tax invoices: no GST is computed until D-14 (invoicing entity) is decided.
--
-- Trust boundary: only a signature-verified Razorpay webhook (or a server-side reconciliation that
-- re-reads the order from Razorpay) can mark a payment verified. Those writes run as upfixy_worker
-- through payment_* functions; the API role cannot set payment_verified.

-- --------------------------------------------------------------- controls
CREATE TABLE payment_controls (
  id            boolean PRIMARY KEY DEFAULT true CHECK (id),
  live_allowed  boolean NOT NULL DEFAULT false,
  reason        text NOT NULL,
  changed_at    timestamptz NOT NULL DEFAULT now()
);
INSERT INTO payment_controls(live_allowed, reason)
VALUES (false, 'Live charging is off until D-05, D-06, D-13, D-14, D-18 and D-23 are signed off.');
GRANT SELECT ON payment_controls TO upfixy_app, upfixy_worker;

-- ------------------------------------------------------- merchant profile
-- D-06 recommendation: the partner (agency) bills its own clients with its own Razorpay account.
-- This is a recommendation, not a decision; the profile records who would be the merchant.
CREATE TABLE merchant_profiles (
  workspace_id        uuid PRIMARY KEY REFERENCES workspaces(id) ON DELETE CASCADE,
  legal_name          text NOT NULL CHECK (length(legal_name) BETWEEN 2 AND 160),
  gstin               text CHECK (gstin IS NULL OR gstin ~ '^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$'),
  state_code          text CHECK (state_code IS NULL OR state_code ~ '^[0-9]{2}$'),
  receipt_prefix      text NOT NULL DEFAULT 'UPX' CHECK (receipt_prefix ~ '^[A-Z0-9]{2,8}$'),
  receipt_seq         bigint NOT NULL DEFAULT 0,
  mode                text NOT NULL DEFAULT 'test' CHECK (mode IN ('test', 'live')),
  razorpay_key_id     text NOT NULL CHECK (razorpay_key_id ~ '^rzp_(test|live)_[A-Za-z0-9]{8,32}$'),
  key_secret_enc      text NOT NULL,          -- encrypted by the API (APP_KEY); never returned
  webhook_secret_enc  text NOT NULL,
  secret_version      integer NOT NULL DEFAULT 1,
  updated_by          uuid NOT NULL,
  updated_at          timestamptz NOT NULL DEFAULT now(),
  CHECK ((mode = 'test') = (razorpay_key_id LIKE 'rzp_test_%')),
  FOREIGN KEY (workspace_id, updated_by) REFERENCES memberships(workspace_id, user_id) DEFERRABLE INITIALLY DEFERRED
);

CREATE FUNCTION merchant_profiles_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.mode = 'live' AND NOT coalesce((SELECT live_allowed FROM payment_controls), false) THEN  -- missing row = off (fail closed)
    RAISE EXCEPTION 'live payments are not enabled on this platform' USING ERRCODE = '42501';
  END IF;
  IF TG_OP = 'UPDATE' AND (NEW.key_secret_enc IS DISTINCT FROM OLD.key_secret_enc OR NEW.webhook_secret_enc IS DISTINCT FROM OLD.webhook_secret_enc) THEN
    NEW.secret_version := OLD.secret_version + 1;
  END IF;
  IF TG_OP = 'UPDATE' THEN NEW.receipt_seq := greatest(NEW.receipt_seq, OLD.receipt_seq); END IF;  -- numbering never goes back
  NEW.updated_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER merchant_profiles_guard BEFORE INSERT OR UPDATE ON merchant_profiles
  FOR EACH ROW EXECUTE FUNCTION merchant_profiles_guard();

ALTER TABLE merchant_profiles ENABLE ROW LEVEL SECURITY;
CREATE POLICY mp_select ON merchant_profiles FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'billing'));
CREATE POLICY mp_write ON merchant_profiles FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() = 'owner')
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() = 'owner' AND updated_by = app_user_id());
GRANT SELECT (workspace_id, legal_name, gstin, state_code, receipt_prefix, mode, razorpay_key_id, secret_version, updated_at)
  ON merchant_profiles TO upfixy_app;
GRANT INSERT (workspace_id, legal_name, gstin, state_code, receipt_prefix, mode, razorpay_key_id, key_secret_enc, webhook_secret_enc, updated_by),
      UPDATE (legal_name, gstin, state_code, receipt_prefix, mode, razorpay_key_id, key_secret_enc, webhook_secret_enc, updated_by)
  ON merchant_profiles TO upfixy_app;

-- The API needs the key secret to create an order; it gets it only through this function,
-- only for its own workspace, only for a caller who may pay or administer billing.
CREATE FUNCTION merchant_order_credentials() RETURNS TABLE(razorpay_key_id text, key_secret_enc text, mode text, legal_name text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT m.razorpay_key_id, m.key_secret_enc, m.mode, m.legal_name FROM merchant_profiles m
   WHERE m.workspace_id = app_workspace_id() AND app_role() IS NOT NULL
$$;
REVOKE ALL ON FUNCTION merchant_order_credentials() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION merchant_order_credentials() TO upfixy_app;

-- ------------------------------------------------------ coverage (₹0 work)
CREATE TABLE coverage_contracts (
  id            uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id  uuid NOT NULL,
  client_id     uuid,                       -- NULL: covers the workspace's own websites
  website_id    uuid,                       -- NULL: every website of the client
  reference     text NOT NULL CHECK (length(reference) BETWEEN 1 AND 120),   -- contract / plan number
  scope         text NOT NULL CHECK (length(scope) BETWEEN 3 AND 1000),
  valid_from    date NOT NULL,
  valid_until   date NOT NULL CHECK (valid_until >= valid_from),
  created_by    uuid NOT NULL,
  created_at    timestamptz NOT NULL DEFAULT now(),
  revoked_at    timestamptz,
  revoked_by    uuid,
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  FOREIGN KEY (workspace_id, client_id) REFERENCES clients(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, created_by) REFERENCES memberships(workspace_id, user_id) DEFERRABLE INITIALLY DEFERRED
);
ALTER TABLE coverage_contracts ENABLE ROW LEVEL SECURITY;
CREATE POLICY cov_select ON coverage_contracts FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND (app_role() IN ('owner', 'admin', 'billing', 'technician') OR app_can_see_client(client_id)));
CREATE POLICY cov_insert ON coverage_contracts FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin') AND created_by = app_user_id());
CREATE POLICY cov_revoke ON coverage_contracts FOR UPDATE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (workspace_id = app_workspace_id());
GRANT SELECT, INSERT ON coverage_contracts TO upfixy_app;
GRANT UPDATE (revoked_at, revoked_by) ON coverage_contracts TO upfixy_app;

-- Does an active contract cover this website today?
CREATE FUNCTION coverage_active(p_coverage uuid, p_website uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT EXISTS (
    SELECT 1 FROM coverage_contracts c JOIN websites w ON w.id = p_website AND w.workspace_id = c.workspace_id
     WHERE c.id = p_coverage AND c.revoked_at IS NULL
       AND current_date BETWEEN c.valid_from AND c.valid_until
       AND (c.website_id = w.id OR (c.website_id IS NULL AND c.client_id IS NOT DISTINCT FROM w.client_id)))
$$;

-- ₹0 provider-covered quotes must name a contract that covers the website (§19.1 "coverage must be proven").
-- The coverage id is part of params, so it is fingerprinted and approved with the quote.
CREATE FUNCTION quotes_coverage_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE tier text; site uuid; cov uuid;
BEGIN
  SELECT a.tier INTO tier FROM action_catalog a WHERE a.code = NEW.action_code;
  IF tier = 'provider_covered' THEN
    SELECT t.website_id INTO site FROM tickets t WHERE t.id = NEW.ticket_id;
    BEGIN
      cov := (NEW.params->>'coverage_id')::uuid;
    EXCEPTION WHEN others THEN cov := NULL;
    END;
    IF cov IS NULL OR NOT coverage_active(cov, site) THEN
      RAISE EXCEPTION 'a provider-covered (free) fix needs an active coverage contract for this website' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
-- Runs before the fingerprint is computed (trigger names sort alphabetically: "a…" before "quotes_insert_guard").
CREATE TRIGGER a_quotes_coverage_guard BEFORE INSERT ON quotes FOR EACH ROW EXECUTE FUNCTION quotes_coverage_guard();

-- ---------------------------------------------------------------- orders
CREATE TYPE payment_status AS ENUM ('created', 'pending', 'paid_verified', 'failed', 'refunded', 'partially_refunded');

CREATE TABLE payment_orders (
  id                  uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id        uuid NOT NULL,
  ticket_id           uuid NOT NULL,
  quote_id            uuid NOT NULL,
  approval_id         uuid NOT NULL,
  amount_paise        bigint NOT NULL CHECK (amount_paise > 0),
  currency            char(3) NOT NULL DEFAULT 'INR' CHECK (currency = 'INR'),
  mode                text NOT NULL CHECK (mode IN ('test', 'live')),
  merchant_key_id     text NOT NULL,
  merchant_name       text NOT NULL,
  provider_order_id   text UNIQUE CHECK (provider_order_id ~ '^order_[A-Za-z0-9]{6,40}$'),
  provider_payment_id text,
  status              payment_status NOT NULL DEFAULT 'created',
  status_reason       text,
  client_reported_at  timestamptz,           -- browser said "paid": informational only (AC-SVC-004.2)
  refunded_paise      bigint NOT NULL DEFAULT 0 CHECK (refunded_paise >= 0),
  idempotency_key     text NOT NULL CHECK (length(idempotency_key) BETWEEN 8 AND 80),
  created_by          uuid NOT NULL,
  created_at          timestamptz NOT NULL DEFAULT now(),
  paid_at             timestamptz,
  updated_at          timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  UNIQUE (workspace_id, idempotency_key),
  FOREIGN KEY (workspace_id, ticket_id) REFERENCES tickets(workspace_id, id),
  FOREIGN KEY (workspace_id, quote_id) REFERENCES quotes(workspace_id, id),
  FOREIGN KEY (workspace_id, created_by) REFERENCES memberships(workspace_id, user_id) DEFERRABLE INITIALLY DEFERRED,
  CHECK (refunded_paise <= amount_paise)
);
-- One open or paid order per approval: a second click returns the same order.
CREATE UNIQUE INDEX payment_orders_one_per_approval ON payment_orders(approval_id) WHERE status NOT IN ('failed');

CREATE TABLE payment_receipts (
  id               uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id     uuid NOT NULL,
  order_id         uuid NOT NULL UNIQUE,
  number           text NOT NULL,
  seller_name      text NOT NULL,
  seller_gstin     text,
  buyer_name       text,
  description      text NOT NULL,
  action_code      text NOT NULL,
  amount_paise     bigint NOT NULL,
  currency         char(3) NOT NULL,
  is_tax_invoice   boolean NOT NULL DEFAULT false CHECK (NOT is_tax_invoice),   -- D-14 open: never a tax invoice yet
  tax_note         text NOT NULL,
  mode             text NOT NULL,
  provider_payment_id text NOT NULL,
  issued_at        timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  UNIQUE (workspace_id, number),
  FOREIGN KEY (workspace_id, order_id) REFERENCES payment_orders(workspace_id, id)
);

CREATE TABLE refunds (
  id                  uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id        uuid NOT NULL,
  order_id            uuid NOT NULL,
  amount_paise        bigint NOT NULL CHECK (amount_paise > 0),
  reason              text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
  status              text NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'processed', 'failed')),
  provider_refund_id  text UNIQUE,
  requested_by        uuid,
  requested_at        timestamptz NOT NULL DEFAULT now(),
  processed_at        timestamptz,
  PRIMARY KEY (id),
  FOREIGN KEY (workspace_id, order_id) REFERENCES payment_orders(workspace_id, id)
);

-- Every webhook delivery, deduplicated by Razorpay's event id (AC-SVC-004.1).
CREATE TABLE payment_webhook_inbox (
  event_id      text PRIMARY KEY CHECK (length(event_id) BETWEEN 6 AND 100),
  workspace_id  uuid NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  event_type    text NOT NULL,
  payload_hash  text NOT NULL,
  received_at   timestamptz NOT NULL DEFAULT now(),
  outcome       text NOT NULL,
  order_id      uuid
);

-- Payment problems for people to look at (AC-SVC-004.3 "an alert fires").
CREATE TABLE payment_alerts (
  id            uuid NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  workspace_id  uuid NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  order_id      uuid,
  kind          text NOT NULL,
  detail        jsonb NOT NULL DEFAULT '{}',
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TRIGGER payment_receipts_immutable BEFORE UPDATE OR DELETE ON payment_receipts FOR EACH ROW EXECUTE FUNCTION append_only();
CREATE TRIGGER payment_webhook_inbox_immutable BEFORE UPDATE OR DELETE ON payment_webhook_inbox FOR EACH ROW EXECUTE FUNCTION append_only();
CREATE TRIGGER payment_alerts_immutable BEFORE UPDATE OR DELETE ON payment_alerts FOR EACH ROW EXECUTE FUNCTION append_only();
CREATE TRIGGER payment_orders_no_delete BEFORE DELETE ON payment_orders FOR EACH ROW EXECUTE FUNCTION append_only();

ALTER TABLE payment_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_receipts ENABLE ROW LEVEL SECURITY;
ALTER TABLE refunds ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_webhook_inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_alerts ENABLE ROW LEVEL SECURITY;

-- Orders, receipts and refunds follow ticket visibility (the payer sees theirs); alerts are for billing staff.
CREATE POLICY po_select ON payment_orders FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND (app_role() IN ('owner', 'admin', 'billing') OR EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id)));
CREATE POLICY pr_select ON payment_receipts FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM payment_orders o WHERE o.id = order_id));
CREATE POLICY rf_select ON refunds FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM payment_orders o WHERE o.id = order_id));
CREATE POLICY pa_select ON payment_alerts FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'billing'));
GRANT SELECT ON payment_orders, payment_receipts, refunds, payment_alerts TO upfixy_app;

-- ------------------------------------------------------ app functions
-- Create (or return) the order for a live, unpaid approval. Payer only (FIX-B). The provider order id
-- is attached afterwards by payment_attach_provider_order() once Razorpay answered.
CREATE FUNCTION payment_open_order(p_approval uuid, p_idempotency text, p_key_id text, p_mode text, p_merchant text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE a approvals; q quotes; c uuid; oid uuid;
BEGIN
  SELECT * INTO a FROM approvals WHERE id = p_approval AND workspace_id = app_workspace_id();
  SELECT w.client_id INTO c FROM tickets t JOIN websites w ON w.id = t.website_id WHERE t.id = a.ticket_id;
  IF a.id IS NULL OR NOT app_can_see_client(c) AND NOT app_can_approve(c) THEN RAISE EXCEPTION 'approval not found' USING ERRCODE = 'P0002'; END IF;
  IF NOT app_can_approve(c) THEN RAISE EXCEPTION 'only the payer can pay for this work' USING ERRCODE = '42501'; END IF;
  SELECT * INTO q FROM quotes WHERE id = a.quote_id;
  IF a.voided_at IS NOT NULL OR a.expires_at <= now() OR q.voided_at IS NOT NULL OR q.expires_at <= now() OR a.fingerprint <> q.fingerprint THEN
    RAISE EXCEPTION 'this approval is no longer valid' USING ERRCODE = '23514';
  END IF;
  IF q.amount_paise = 0 THEN RAISE EXCEPTION 'nothing to pay: this work is free' USING ERRCODE = '23514'; END IF;
  IF a.payment_verified THEN RAISE EXCEPTION 'already paid' USING ERRCODE = '23514'; END IF;
  IF p_mode = 'live' AND NOT coalesce((SELECT live_allowed FROM payment_controls), false) THEN  -- missing row = off (fail closed)
    RAISE EXCEPTION 'live payments are not enabled on this platform' USING ERRCODE = '42501';
  END IF;
  SELECT id INTO oid FROM payment_orders WHERE approval_id = p_approval AND status <> 'failed';
  IF oid IS NOT NULL THEN RETURN oid; END IF;
  SELECT id INTO oid FROM payment_orders WHERE workspace_id = app_workspace_id() AND idempotency_key = p_idempotency;
  IF oid IS NOT NULL THEN RETURN oid; END IF;
  INSERT INTO payment_orders(workspace_id, ticket_id, quote_id, approval_id, amount_paise, mode, merchant_key_id, merchant_name, idempotency_key, created_by)
  VALUES (app_workspace_id(), a.ticket_id, q.id, a.id, q.amount_paise, p_mode, p_key_id, p_merchant, p_idempotency, app_user_id())
  RETURNING id INTO oid;
  RETURN oid;
END $$;

CREATE FUNCTION payment_attach_provider_order(p_order uuid, p_provider_order text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  UPDATE payment_orders SET provider_order_id = p_provider_order, status = 'pending', updated_at = now()
   WHERE id = p_order AND workspace_id = app_workspace_id() AND provider_order_id IS NULL AND status = 'created';
  IF NOT FOUND THEN RAISE EXCEPTION 'order not found' USING ERRCODE = 'P0002'; END IF;
END $$;

-- The browser reported success. Recorded, never trusted (AC-SVC-004.2).
CREATE FUNCTION payment_client_reported(p_order uuid) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  UPDATE payment_orders SET client_reported_at = coalesce(client_reported_at, now()), updated_at = now()
   WHERE id = p_order AND workspace_id = app_workspace_id() AND status IN ('created', 'pending')
$$;

CREATE FUNCTION payment_request_refund(p_order uuid, p_amount bigint, p_reason text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE o payment_orders; rid uuid; pending bigint;
BEGIN
  IF app_role() NOT IN ('owner', 'billing') THEN RAISE EXCEPTION 'only owners and billing can refund' USING ERRCODE = '42501'; END IF;
  SELECT * INTO o FROM payment_orders WHERE id = p_order AND workspace_id = app_workspace_id() FOR UPDATE;
  IF o.id IS NULL THEN RAISE EXCEPTION 'order not found' USING ERRCODE = 'P0002'; END IF;
  IF o.status NOT IN ('paid_verified', 'partially_refunded') THEN RAISE EXCEPTION 'only verified payments can be refunded' USING ERRCODE = '23514'; END IF;
  SELECT coalesce(sum(amount_paise), 0) INTO pending FROM refunds WHERE order_id = p_order AND status = 'requested';
  IF p_amount <= 0 OR p_amount + o.refunded_paise + pending > o.amount_paise THEN
    RAISE EXCEPTION 'refund is more than what remains' USING ERRCODE = '23514';
  END IF;
  INSERT INTO refunds(workspace_id, order_id, amount_paise, reason, requested_by) VALUES (o.workspace_id, o.id, p_amount, p_reason, app_user_id())
  RETURNING id INTO rid;
  RETURN rid;
END $$;

CREATE FUNCTION payment_attach_refund(p_refund uuid, p_provider_refund text) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  UPDATE refunds SET provider_refund_id = p_provider_refund WHERE id = p_refund AND workspace_id = app_workspace_id() AND provider_refund_id IS NULL
$$;

REVOKE ALL ON FUNCTION payment_open_order(uuid, text, text, text, text), payment_attach_provider_order(uuid, text), payment_client_reported(uuid),
  payment_request_refund(uuid, bigint, text), payment_attach_refund(uuid, text), coverage_active(uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION payment_open_order(uuid, text, text, text, text), payment_attach_provider_order(uuid, text), payment_client_reported(uuid),
  payment_request_refund(uuid, bigint, text), payment_attach_refund(uuid, text), coverage_active(uuid, uuid) TO upfixy_app;

-- --------------------------------------------- verified-event functions (worker role)
CREATE FUNCTION payment_webhook_secret(p_workspace uuid) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  RETURN (SELECT webhook_secret_enc FROM merchant_profiles WHERE workspace_id = p_workspace);
END $$;

CREATE FUNCTION payment_merchant_key(p_workspace uuid) RETURNS TABLE(razorpay_key_id text, key_secret_enc text, mode text)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT m.razorpay_key_id, m.key_secret_enc, m.mode FROM merchant_profiles m WHERE m.workspace_id = p_workspace;
END $$;

-- Record a delivery once. Returns false for a duplicate (already processed) event.
CREATE FUNCTION payment_inbox(p_event text, p_workspace uuid, p_type text, p_hash text, p_outcome text, p_order uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  INSERT INTO payment_webhook_inbox(event_id, workspace_id, event_type, payload_hash, outcome, order_id)
  VALUES (p_event, p_workspace, p_type, p_hash, p_outcome, p_order) ON CONFLICT (event_id) DO NOTHING;
  RETURN FOUND;
END $$;

CREATE FUNCTION payment_alert(p_workspace uuid, p_order uuid, p_kind text, p_detail jsonb) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  INSERT INTO payment_alerts(workspace_id, order_id, kind, detail) VALUES (p_workspace, p_order, p_kind, p_detail);
END $$;

-- A captured payment, already signature-verified by the caller. Re-checks everything against the
-- stored order; any mismatch leaves the order unfulfilled and raises an alert (AC-SVC-004.3).
CREATE FUNCTION payment_captured(p_workspace uuid, p_provider_order text, p_provider_payment text, p_amount bigint, p_currency text,
                                 p_key_id text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE o payment_orders; m merchant_profiles; q quotes; buyer text; num text;
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  SELECT * INTO o FROM payment_orders WHERE provider_order_id = p_provider_order FOR UPDATE;
  IF o.id IS NULL OR o.workspace_id <> p_workspace THEN
    PERFORM payment_alert(p_workspace, NULL, 'unknown_order', jsonb_build_object('provider_order_id', left(p_provider_order, 60)));
    RETURN 'unknown_order';
  END IF;
  IF o.status IN ('paid_verified', 'refunded', 'partially_refunded') THEN RETURN 'already_paid'; END IF;
  SELECT * INTO m FROM merchant_profiles WHERE workspace_id = o.workspace_id;
  IF p_amount IS DISTINCT FROM o.amount_paise OR p_currency IS DISTINCT FROM o.currency OR p_key_id IS DISTINCT FROM o.merchant_key_id
     OR m.razorpay_key_id IS DISTINCT FROM o.merchant_key_id THEN
    UPDATE payment_orders SET status_reason = 'Payment details did not match the order; not fulfilled', updated_at = now() WHERE id = o.id;
    PERFORM payment_alert(o.workspace_id, o.id, 'mismatch', jsonb_build_object('amount', p_amount, 'expected_amount', o.amount_paise,
      'currency', p_currency, 'merchant_matches', p_key_id IS NOT DISTINCT FROM o.merchant_key_id));
    RETURN 'mismatch';
  END IF;
  UPDATE payment_orders SET status = 'paid_verified', provider_payment_id = p_provider_payment, paid_at = now(), status_reason = NULL, updated_at = now()
   WHERE id = o.id;
  UPDATE approvals SET payment_verified = true WHERE id = o.approval_id AND voided_at IS NULL;
  -- Payment receipt (not a tax invoice; D-14 open). Numbered per merchant without gaps.
  UPDATE merchant_profiles SET receipt_seq = receipt_seq + 1 WHERE workspace_id = o.workspace_id RETURNING * INTO m;
  num := m.receipt_prefix || '-' || to_char(now(), 'YYYY') || '-' || lpad(m.receipt_seq::text, 6, '0');
  SELECT * INTO q FROM quotes WHERE id = o.quote_id;
  SELECT c.name INTO buyer FROM tickets t JOIN websites w ON w.id = t.website_id LEFT JOIN clients c ON c.id = w.client_id WHERE t.id = o.ticket_id;
  INSERT INTO payment_receipts(workspace_id, order_id, number, seller_name, seller_gstin, buyer_name, description, action_code, amount_paise, currency,
                               tax_note, mode, provider_payment_id)
  VALUES (o.workspace_id, o.id, num, m.legal_name, m.gstin, buyer, q.description, q.action_code, o.amount_paise, o.currency,
          'Payment receipt only. Not a GST tax invoice: the invoicing entity and GST treatment are pending (D-14).', o.mode, p_provider_payment);
  RETURN 'paid';
END $$;

CREATE FUNCTION payment_failed(p_workspace uuid, p_provider_order text, p_reason text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE o payment_orders;
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  SELECT * INTO o FROM payment_orders WHERE provider_order_id = p_provider_order AND workspace_id = p_workspace FOR UPDATE;
  IF o.id IS NULL THEN RETURN 'unknown_order'; END IF;
  -- Out of order: a failure after a verified capture never undoes the payment.
  IF o.status NOT IN ('created', 'pending') THEN RETURN 'ignored'; END IF;
  UPDATE payment_orders SET status = 'failed', status_reason = left(p_reason, 200), updated_at = now() WHERE id = o.id;
  RETURN 'failed';
END $$;

CREATE FUNCTION payment_refunded(p_workspace uuid, p_provider_payment text, p_provider_refund text, p_amount bigint, p_status text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE o payment_orders; r refunds; total bigint;
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  SELECT * INTO o FROM payment_orders WHERE provider_payment_id = p_provider_payment AND workspace_id = p_workspace FOR UPDATE;
  IF o.id IS NULL THEN RETURN 'unknown_payment'; END IF;
  SELECT * INTO r FROM refunds WHERE provider_refund_id = p_provider_refund;
  IF r.id IS NULL THEN
    -- A refund made in the Razorpay dashboard: record it too.
    INSERT INTO refunds(workspace_id, order_id, amount_paise, reason, status, provider_refund_id)
    VALUES (o.workspace_id, o.id, p_amount, 'Refund made outside Upfixy', 'requested', p_provider_refund) RETURNING * INTO r;
  END IF;
  IF r.status = 'processed' THEN RETURN 'already_processed'; END IF;
  IF p_status = 'failed' THEN
    UPDATE refunds SET status = 'failed', processed_at = now() WHERE id = r.id;
    RETURN 'refund_failed';
  END IF;
  UPDATE refunds SET status = 'processed', processed_at = now() WHERE id = r.id;
  SELECT coalesce(sum(amount_paise), 0) INTO total FROM refunds WHERE order_id = o.id AND status = 'processed';
  UPDATE payment_orders SET refunded_paise = least(total, amount_paise),
         status = CASE WHEN total >= amount_paise THEN 'refunded'::payment_status ELSE 'partially_refunded'::payment_status END, updated_at = now()
   WHERE id = o.id;
  RETURN 'refunded';
END $$;

-- Orders waiting on Razorpay for reconciliation.
CREATE FUNCTION payment_pending_orders(p_older_than_seconds integer, p_limit integer)
RETURNS TABLE(id uuid, workspace_id uuid, provider_order_id text, amount_paise bigint)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT o.id, o.workspace_id, o.provider_order_id, o.amount_paise FROM payment_orders o
   WHERE o.status = 'pending' AND o.provider_order_id IS NOT NULL AND o.created_at < now() - make_interval(secs => p_older_than_seconds)
   ORDER BY o.created_at LIMIT p_limit;
END $$;

REVOKE ALL ON FUNCTION payment_webhook_secret(uuid), payment_merchant_key(uuid), payment_inbox(text, uuid, text, text, text, uuid),
  payment_alert(uuid, uuid, text, jsonb), payment_captured(uuid, text, text, bigint, text, text), payment_failed(uuid, text, text),
  payment_refunded(uuid, text, text, bigint, text), payment_pending_orders(integer, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION payment_webhook_secret(uuid), payment_merchant_key(uuid), payment_inbox(text, uuid, text, text, text, uuid),
  payment_alert(uuid, uuid, text, jsonb), payment_captured(uuid, text, text, bigint, text, text), payment_failed(uuid, text, text),
  payment_refunded(uuid, text, text, bigint, text), payment_pending_orders(integer, integer) TO upfixy_worker;

-- ------------------------------------- start-work gate: coverage must still hold
-- ₹0 work also needs its coverage contract to be active at the moment work starts.
CREATE FUNCTION tickets_coverage_gate() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF OLD.state = 'quote_pending' AND NEW.state = 'ready_for_work' THEN
    IF EXISTS (
      SELECT 1 FROM approvals a JOIN quotes q ON q.id = a.quote_id JOIN action_catalog c ON c.code = q.action_code
       WHERE a.ticket_id = NEW.id AND a.voided_at IS NULL AND q.voided_at IS NULL AND c.tier = 'provider_covered'
         AND NOT coverage_active(CASE WHEN q.params ? 'coverage_id' THEN (q.params->>'coverage_id')::uuid END, NEW.website_id)
    ) THEN
      RAISE EXCEPTION 'the coverage contract for this free fix is no longer active' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER tickets_coverage_gate BEFORE UPDATE OF state ON tickets FOR EACH ROW EXECUTE FUNCTION tickets_coverage_gate();
