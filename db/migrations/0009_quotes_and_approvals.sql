-- Quote-bound approvals. FRD refs: SVC-002/003, §19.1 quote invariants,
-- FIX-A, FIX-B, D-07 ("any repair additionally requires exact-action
-- approval"), AC-SVC-003.1–.4, AC-AST-002.7.
--
-- Before: an approval carried a free-text action_fingerprint and amount chosen
-- by the caller. Now:
--   * staff issue a QUOTE for one exact monitor target and one allowlisted
--     action; price comes from the catalogue, not the request;
--   * the database computes a SHA-256 fingerprint over every term of the quote;
--   * quotes are immutable (they can only be voided); a new quote on the same
--     ticket voids the old one and its approvals;
--   * the payer approves a quote by echoing the fingerprint they were shown, so
--     an approval is bound to exactly that version;
--   * work can start only with a live approval of a live quote AND verified
--     ownership or delegation for the quoted host.

-- -------------------------------------------------------- action catalogue
-- DRAFT prices and scopes: D-08 (scope per tier) is still open.
CREATE TABLE action_catalog (
  code        text PRIMARY KEY CHECK (code ~ '^[a-z]+(\.[a-z_]+)+$'),
  label       text NOT NULL,
  tier        text NOT NULL CHECK (tier IN ('provider_covered', 'domain_dns', 'cpanel', 'code', 'custom')),
  price_paise bigint CHECK (price_paise >= 0),   -- NULL: amount set per quote (custom work)
  permission  text NOT NULL DEFAULT 'repair' CHECK (permission IN ('repair')),
  active      boolean NOT NULL DEFAULT true
);
INSERT INTO action_catalog(code, label, tier, price_paise) VALUES
  ('provider.covered_fix', 'Provider-covered fix under an existing service contract', 'provider_covered', 0),
  ('dns.record_fix',       'Correct one DNS record in an approved zone',              'domain_dns',       10000),
  ('cpanel.ssl_fix',       'Fix SSL / AutoSSL for one cPanel account',                'cpanel',           20000),
  ('cpanel.setting_fix',   'Correct one cPanel account setting',                      'cpanel',           20000),
  ('code.small_fix',       'Fix one small, reproducible code issue in agreed files',  'code',             30000),
  ('custom.quoted_work',   'Custom quoted work (written scope)',                       'custom',           NULL);
GRANT SELECT ON action_catalog TO upfixy_app;

-- ------------------------------------------------------------------ quotes
CREATE TABLE quotes (
  id                uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id      uuid NOT NULL,
  ticket_id         uuid NOT NULL,
  monitor_target_id uuid NOT NULL,
  action_code       text NOT NULL REFERENCES action_catalog(code),
  params            jsonb NOT NULL DEFAULT '{}'::jsonb
                    CHECK (jsonb_typeof(params) = 'object' AND length(params::text) <= 4000),
  description       text NOT NULL CHECK (length(description) BETWEEN 1 AND 1000),
  amount_paise      bigint NOT NULL CHECK (amount_paise >= 0 AND amount_paise <= 100000000),
  currency          char(3) NOT NULL DEFAULT 'INR' CHECK (currency = 'INR'),
  issued_by         uuid NOT NULL,
  issued_at         timestamptz NOT NULL DEFAULT now(),
  expires_at        timestamptz NOT NULL,
  fingerprint       text NOT NULL CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
  voided_at         timestamptz,
  void_reason       text,
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  FOREIGN KEY (workspace_id, ticket_id) REFERENCES tickets(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, monitor_target_id) REFERENCES monitor_targets(workspace_id, id),
  FOREIGN KEY (workspace_id, issued_by) REFERENCES memberships(workspace_id, user_id)
);
CREATE INDEX quotes_ticket_idx ON quotes(ticket_id, issued_at DESC);

CREATE FUNCTION quote_fingerprint(q quotes, t monitor_targets) RETURNS text
LANGUAGE sql IMMUTABLE AS $$
  SELECT encode(sha256(convert_to(concat_ws('|', 'upfixy-quote-v1',
    q.id, q.workspace_id, q.ticket_id, q.monitor_target_id,
    t.scheme, t.host, t.port, t.path,
    q.action_code, q.params::text, q.description, q.amount_paise, q.currency,
    to_char(q.expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')), 'UTF8')), 'hex')
$$;

CREATE FUNCTION quotes_insert_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE cat action_catalog; tgt monitor_targets; tk tickets;
BEGIN
  SELECT * INTO tk FROM tickets WHERE id = NEW.ticket_id AND workspace_id = NEW.workspace_id;
  IF NOT FOUND THEN RAISE EXCEPTION 'ticket not found' USING ERRCODE = '23503'; END IF;
  IF tk.state NOT IN ('investigating', 'waiting_for_customer', 'quote_pending') THEN
    RAISE EXCEPTION 'quotes can only be issued while a ticket is being investigated or awaiting a quote'
      USING ERRCODE = '23514';
  END IF;
  SELECT * INTO tgt FROM monitor_targets
   WHERE id = NEW.monitor_target_id AND workspace_id = NEW.workspace_id AND website_id = tk.website_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'the quoted address must belong to the ticket''s website' USING ERRCODE = '23514';
  END IF;
  SELECT * INTO cat FROM action_catalog WHERE code = NEW.action_code AND active;
  IF NOT FOUND THEN RAISE EXCEPTION 'action is not in the catalogue' USING ERRCODE = '23514'; END IF;

  -- Server-controlled terms; anything the caller sent for these is ignored.
  IF app_user_id() IS NOT NULL THEN NEW.issued_by := app_user_id(); END IF;
  IF cat.price_paise IS NOT NULL THEN
    NEW.amount_paise := cat.price_paise;
  ELSIF NEW.amount_paise IS NULL OR NEW.amount_paise <= 0 THEN
    RAISE EXCEPTION 'custom work needs a positive amount' USING ERRCODE = '23514';
  END IF;
  NEW.issued_at := now();
  NEW.expires_at := date_trunc('second', least(coalesce(NEW.expires_at, now() + interval '7 days'),
                                                 now() + interval '14 days'));
  IF NEW.expires_at <= now() THEN RAISE EXCEPTION 'quote must expire in the future' USING ERRCODE = '23514'; END IF;
  NEW.voided_at := NULL; NEW.void_reason := NULL;
  NEW.fingerprint := quote_fingerprint(NEW, tgt);
  RETURN NEW;
END $$;
CREATE TRIGGER quotes_insert_guard BEFORE INSERT ON quotes
  FOR EACH ROW EXECUTE FUNCTION quotes_insert_guard();

-- A new quote replaces every open quote on the ticket (AC-SVC-003.1).
CREATE FUNCTION quotes_supersede() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  UPDATE quotes SET voided_at = now(), void_reason = 'superseded by a new quote'
   WHERE ticket_id = NEW.ticket_id AND id <> NEW.id AND voided_at IS NULL;
  RETURN NEW;
END $$;
CREATE TRIGGER quotes_supersede AFTER INSERT ON quotes
  FOR EACH ROW EXECUTE FUNCTION quotes_supersede();

-- Immutable except for voiding once.
CREATE FUNCTION quotes_update_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.voided_at IS NOT NULL THEN RAISE EXCEPTION 'quote is already void' USING ERRCODE = '23514'; END IF;
  IF (NEW.id, NEW.workspace_id, NEW.ticket_id, NEW.monitor_target_id, NEW.action_code, NEW.params,
      NEW.description, NEW.amount_paise, NEW.currency, NEW.issued_by, NEW.issued_at, NEW.expires_at, NEW.fingerprint)
     IS DISTINCT FROM
     (OLD.id, OLD.workspace_id, OLD.ticket_id, OLD.monitor_target_id, OLD.action_code, OLD.params,
      OLD.description, OLD.amount_paise, OLD.currency, OLD.issued_by, OLD.issued_at, OLD.expires_at, OLD.fingerprint)
     OR NEW.voided_at IS NULL THEN
    RAISE EXCEPTION 'quotes cannot be edited; issue a new quote instead' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER quotes_update_guard BEFORE UPDATE ON quotes
  FOR EACH ROW EXECUTE FUNCTION quotes_update_guard();
CREATE TRIGGER quotes_no_delete BEFORE DELETE ON quotes
  FOR EACH ROW EXECUTE FUNCTION audit_events_immutable();

ALTER TABLE quotes ENABLE ROW LEVEL SECURITY;
CREATE POLICY q_select ON quotes FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id));
CREATE POLICY q_insert ON quotes FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'technician')
              AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id));
CREATE POLICY q_void ON quotes FOR UPDATE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'technician')
         AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id))
  WITH CHECK (workspace_id = app_workspace_id());
GRANT SELECT, INSERT ON quotes TO upfixy_app;
GRANT UPDATE (voided_at, void_reason) ON quotes TO upfixy_app;

-- --------------------------------------------------------------- approvals
ALTER TABLE approvals
  ADD COLUMN quote_id    uuid,
  ADD COLUMN fingerprint text;
ALTER TABLE approvals
  ADD CONSTRAINT approvals_quote_fk FOREIGN KEY (workspace_id, quote_id) REFERENCES quotes(workspace_id, id),
  ADD CONSTRAINT approvals_quote_required CHECK (quote_id IS NOT NULL AND fingerprint IS NOT NULL) NOT VALID;
CREATE UNIQUE INDEX approvals_one_live_per_quote ON approvals(quote_id) WHERE voided_at IS NULL;

CREATE OR REPLACE FUNCTION approvals_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE q quotes; c uuid; st ticket_state;
BEGIN
  SELECT * INTO q FROM quotes WHERE id = NEW.quote_id AND workspace_id = NEW.workspace_id AND ticket_id = NEW.ticket_id;
  SELECT t.state, w.client_id INTO st, c FROM tickets t JOIN websites w ON w.id = t.website_id WHERE t.id = NEW.ticket_id;
  -- Same answer for "doesn't exist" and "not yours", so nothing leaks across clients.
  IF q.id IS NULL OR NEW.workspace_id <> app_workspace_id() OR NOT app_can_see_client(c) THEN
    RAISE EXCEPTION 'quote not found' USING ERRCODE = '23503';
  END IF;
  IF q.voided_at IS NOT NULL OR q.expires_at <= now() THEN
    RAISE EXCEPTION 'this quote is no longer valid' USING ERRCODE = '23514';
  END IF;
  IF NEW.fingerprint IS DISTINCT FROM q.fingerprint THEN
    RAISE EXCEPTION 'quote fingerprint mismatch' USING ERRCODE = '23514';
  END IF;
  IF st <> 'quote_pending' THEN
    RAISE EXCEPTION 'approvals are only taken while a ticket is awaiting a quote decision' USING ERRCODE = '23514';
  END IF;
  -- FIX-B payer rule.
  IF NEW.approved_by <> app_user_id() OR NOT app_can_approve(c) THEN
    RAISE EXCEPTION 'caller is not the authorised payer' USING ERRCODE = '42501';
  END IF;
  -- D-07: repairs need proof of control (or delegation) for the exact host.
  IF NOT site_private_access(q.workspace_id, q.monitor_target_id, 'repair') THEN
    RAISE EXCEPTION 'ownership of this address is not verified' USING ERRCODE = '42501';
  END IF;
  -- Terms come from the quote, never from the request.
  NEW.amount_paise := q.amount_paise;
  NEW.action_fingerprint := q.fingerprint;
  NEW.expires_at := q.expires_at;
  NEW.payment_verified := false;   -- only the verified payment webhook may set this (M6)
  NEW.voided_at := NULL;
  RETURN NEW;
END $$;

-- Voiding a quote voids its approvals.
CREATE FUNCTION quotes_void_approvals() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.voided_at IS NOT NULL AND OLD.voided_at IS NULL THEN
    UPDATE approvals SET voided_at = now() WHERE quote_id = NEW.id AND voided_at IS NULL;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER quotes_void_approvals AFTER UPDATE ON quotes
  FOR EACH ROW EXECUTE FUNCTION quotes_void_approvals();

-- --------------------------------------------------------- start-work gate
CREATE OR REPLACE FUNCTION tickets_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.workspace_id <> OLD.workspace_id OR NEW.website_id <> OLD.website_id THEN
    RAISE EXCEPTION 'ticket workspace/website is immutable';
  END IF;
  IF NEW.version <> OLD.version + 1 THEN
    RAISE EXCEPTION 'stale ticket version' USING ERRCODE = '40001';
  END IF;
  IF NEW.state <> OLD.state THEN
    IF NOT ticket_transition_allowed(OLD.state, NEW.state) THEN
      RAISE EXCEPTION 'transition % -> % not allowed', OLD.state, NEW.state USING ERRCODE = '23514';
    END IF;
    -- FIX-A + D-07: a live approval of the live quote, paid if chargeable,
    -- and current proof of control for the quoted host.
    IF OLD.state = 'quote_pending' AND NEW.state = 'ready_for_work' THEN
      IF NOT EXISTS (
        SELECT 1 FROM approvals a JOIN quotes q ON q.id = a.quote_id
         WHERE a.ticket_id = NEW.id AND a.voided_at IS NULL AND a.expires_at > now()
           AND q.voided_at IS NULL AND q.expires_at > now() AND a.fingerprint = q.fingerprint
           AND (q.amount_paise = 0 OR a.payment_verified)
      ) THEN
        RAISE EXCEPTION 'no valid approval for this ticket' USING ERRCODE = '23514';
      END IF;
      IF NOT EXISTS (
        SELECT 1 FROM approvals a JOIN quotes q ON q.id = a.quote_id
         WHERE a.ticket_id = NEW.id AND a.voided_at IS NULL AND q.voided_at IS NULL
           AND site_private_access(q.workspace_id, q.monitor_target_id, 'repair')
      ) THEN
        RAISE EXCEPTION 'ownership of this address is not verified' USING ERRCODE = '42501';
      END IF;
    END IF;
  END IF;
  NEW.updated_at := now();
  RETURN NEW;
END $$;

-- Approvals can be voided by the system only (via quote voiding).
REVOKE UPDATE ON approvals FROM upfixy_app;
