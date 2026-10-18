-- M7 controlled repairs: one allowlisted, reversible Cloudflare DNS record change per approved attempt.
-- FRD refs: FIX-003, FIX-004, FIX-005, §25.4, §25.5 (AI-SEC-05..10), AC-FIX-003.x/004.x/005.x.
--
-- Flow: Diagnose → Propose (draft, provider read by the executor) → Preview (quote with the exact
-- change in its fingerprinted params) → Approve (existing approval rules: payer, MFA, ownership,
-- expiry) → Verify payment/coverage → Preflight → Execute → Verify → Resolve / roll back / escalate.
--
-- Separation of duties:
--   upfixy_app      creates drafts and attempt REQUESTS, reads outcomes. Never sees a DNS token.
--   upfixy_executor reads sealed credentials, performs the single allowlisted provider call,
--                   records every step. Cannot create approvals, quotes or drafts.

GRANT USAGE ON SCHEMA public TO upfixy_executor;
-- RLS context readers used by the executor's policies (same as the worker).
GRANT EXECUTE ON FUNCTION app_workspace_id(), app_user_id() TO upfixy_executor;

CREATE TYPE repair_state AS ENUM ('queued', 'preflight', 'executing', 'verifying', 'resolved', 'rolled_back', 'needs_technician', 'refused');
CREATE TYPE repair_draft_state AS ENUM ('reading', 'ready', 'refused');

-- Kill switches (FIX-005). One global row (workspace_id NULL) and optional per-workspace rows.
-- The application environment flag UPFIXY_REPAIRS_ENABLED (default off) is a third, outer switch.
CREATE TABLE repair_controls (
  workspace_id uuid REFERENCES workspaces(id) ON DELETE CASCADE,
  paused       boolean NOT NULL,
  reason       text NOT NULL CHECK (length(reason) BETWEEN 1 AND 500),
  changed_by   uuid,
  changed_at   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX repair_controls_scope ON repair_controls(coalesce(workspace_id, '00000000-0000-0000-0000-000000000000'::uuid));
INSERT INTO repair_controls(workspace_id, paused, reason) VALUES (NULL, true, 'Repairs start paused until a founder-approved release (FRD §25.6 release gate).');

-- Scoped DNS-edit connection (FIX-003 support; the general connector framework is M4).
-- The token is sealed to the executor's public key: the API can store it but never read it.
CREATE TABLE dns_connections (
  id             uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id   uuid NOT NULL,
  website_id     uuid NOT NULL,
  provider       text NOT NULL CHECK (provider = 'cloudflare'),
  zone_id        text NOT NULL CHECK (zone_id ~ '^[0-9a-f]{32}$'),
  zone_name      text NOT NULL CHECK (zone_name = lower(zone_name) AND length(zone_name) BETWEEN 3 AND 253),
  secret_sealed  text NOT NULL,
  secret_version integer NOT NULL DEFAULT 1,
  created_by     uuid NOT NULL,
  created_at     timestamptz NOT NULL DEFAULT now(),
  verified_at    timestamptz,
  verify_result  text,
  revoked_at     timestamptz,
  revoked_by     uuid,
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, created_by) REFERENCES memberships(workspace_id, user_id) DEFERRABLE INITIALLY DEFERRED
);
CREATE UNIQUE INDEX dns_connections_live ON dns_connections(website_id, zone_id) WHERE revoked_at IS NULL;

-- A proposed change waiting for the executor to read the current record (preview).
CREATE TABLE repair_drafts (
  id               uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id     uuid NOT NULL,
  ticket_id        uuid NOT NULL,
  monitor_target_id uuid NOT NULL,
  connection_id    uuid NOT NULL,
  diagnostic_run_id uuid,
  record_id        text NOT NULL CHECK (record_id ~ '^[0-9a-f]{32}$'),
  proposed_content text NOT NULL CHECK (length(proposed_content) BETWEEN 2 AND 45),
  state            repair_draft_state NOT NULL DEFAULT 'reading',
  refusal          text,
  plan             jsonb,            -- canonical plan, written by the executor from the provider's answer
  requested_by     uuid NOT NULL,
  created_at       timestamptz NOT NULL DEFAULT now(),
  read_at          timestamptz,
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  FOREIGN KEY (workspace_id, ticket_id) REFERENCES tickets(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, monitor_target_id) REFERENCES monitor_targets(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, connection_id) REFERENCES dns_connections(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, diagnostic_run_id) REFERENCES diagnostic_runs(workspace_id, id) ON DELETE SET NULL (diagnostic_run_id),
  FOREIGN KEY (workspace_id, requested_by) REFERENCES memberships(workspace_id, user_id) DEFERRABLE INITIALLY DEFERRED
);

CREATE TABLE repair_attempts (
  id                        uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id              uuid NOT NULL,
  ticket_id                 uuid NOT NULL,
  quote_id                  uuid NOT NULL,
  approval_id               uuid NOT NULL UNIQUE,      -- an approval is single-use (AC-FIX-003.4)
  connection_id             uuid NOT NULL,
  connection_secret_version integer NOT NULL,           -- rotation/revocation after enqueue refuses (AC-FIX-005.2)
  zone_id                   text NOT NULL,
  record_id                 text NOT NULL,
  plan                      jsonb NOT NULL,             -- copy of the approved quote params
  plan_fingerprint          text NOT NULL,              -- the approved quote fingerprint
  idempotency_key           text NOT NULL CHECK (length(idempotency_key) BETWEEN 8 AND 100),
  state                     repair_state NOT NULL DEFAULT 'queued',
  state_reason              text,
  customer_message          text,
  requested_by              uuid NOT NULL,
  created_at                timestamptz NOT NULL DEFAULT now(),
  updated_at                timestamptz NOT NULL DEFAULT now(),
  lease_until               timestamptz,
  verify_deadline           timestamptz,
  next_verify_at            timestamptz,
  write_count               integer NOT NULL DEFAULT 0 CHECK (write_count <= 3),  -- the change, one reconciled retry, one rollback
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  UNIQUE (workspace_id, idempotency_key),
  FOREIGN KEY (workspace_id, ticket_id) REFERENCES tickets(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, quote_id) REFERENCES quotes(workspace_id, id),
  FOREIGN KEY (workspace_id, connection_id) REFERENCES dns_connections(workspace_id, id),
  FOREIGN KEY (workspace_id, requested_by) REFERENCES memberships(workspace_id, user_id) DEFERRABLE INITIALLY DEFERRED
);
-- AC-FIX-005.3: at most one in-flight attempt per DNS record, across all workspaces.
CREATE UNIQUE INDEX repair_attempts_one_active ON repair_attempts(zone_id, record_id)
  WHERE state IN ('queued', 'preflight', 'executing', 'verifying');

-- Every step, provider request and verification result (AC-FIX-005.4). Append-only.
CREATE TABLE repair_events (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  workspace_id uuid NOT NULL,
  attempt_id   uuid NOT NULL,
  kind         text NOT NULL,
  detail       jsonb NOT NULL DEFAULT '{}'::jsonb,
  at           timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (workspace_id, attempt_id) REFERENCES repair_attempts(workspace_id, id) ON DELETE CASCADE
);
CREATE INDEX repair_events_attempt ON repair_events(attempt_id, id);
CREATE TRIGGER repair_events_immutable BEFORE UPDATE OR DELETE ON repair_events FOR EACH ROW EXECUTE FUNCTION append_only();

-- ------------------------------------------------------------------- RLS
ALTER TABLE repair_controls ENABLE ROW LEVEL SECURITY;
ALTER TABLE dns_connections ENABLE ROW LEVEL SECURITY;
ALTER TABLE repair_drafts ENABLE ROW LEVEL SECURITY;
ALTER TABLE repair_attempts ENABLE ROW LEVEL SECURITY;
ALTER TABLE repair_events ENABLE ROW LEVEL SECURITY;

-- App: read
CREATE POLICY rc_select ON repair_controls FOR SELECT TO upfixy_app USING (workspace_id IS NULL OR workspace_id = app_workspace_id());
CREATE POLICY dc_select ON dns_connections FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY rd_select ON repair_drafts FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id));
CREATE POLICY ra_select ON repair_attempts FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id));
CREATE POLICY re_select ON repair_events FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM repair_attempts a WHERE a.id = attempt_id));
-- The sealed secret column is never readable by the app.
GRANT SELECT (id, workspace_id, website_id, provider, zone_id, zone_name, secret_version, created_by, created_at,
              verified_at, verify_result, revoked_at, revoked_by) ON dns_connections TO upfixy_app;
GRANT SELECT ON repair_controls, repair_drafts, repair_attempts, repair_events TO upfixy_app;

-- App: write only through the functions below (each re-checks role, assignment and MFA-gated API).

-- Executor: whole-row access, one workspace at a time (set per transaction).
CREATE POLICY x_dc ON dns_connections FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
CREATE POLICY x_dc_verify ON dns_connections FOR UPDATE TO upfixy_executor USING (workspace_id = app_workspace_id()) WITH CHECK (workspace_id = app_workspace_id());
CREATE POLICY x_rd ON repair_drafts FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
CREATE POLICY x_rd_upd ON repair_drafts FOR UPDATE TO upfixy_executor USING (workspace_id = app_workspace_id()) WITH CHECK (workspace_id = app_workspace_id());
CREATE POLICY x_ra ON repair_attempts FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
CREATE POLICY x_ra_upd ON repair_attempts FOR UPDATE TO upfixy_executor USING (workspace_id = app_workspace_id()) WITH CHECK (workspace_id = app_workspace_id());
CREATE POLICY x_re ON repair_events FOR ALL TO upfixy_executor USING (workspace_id = app_workspace_id()) WITH CHECK (workspace_id = app_workspace_id());
CREATE POLICY x_rc ON repair_controls FOR SELECT TO upfixy_executor USING (true);
GRANT SELECT ON dns_connections, repair_drafts, repair_attempts, repair_controls TO upfixy_executor;
GRANT UPDATE (verified_at, verify_result) ON dns_connections TO upfixy_executor;
GRANT UPDATE (state, refusal, plan, read_at) ON repair_drafts TO upfixy_executor;
GRANT UPDATE (state, state_reason, customer_message, updated_at, lease_until, verify_deadline, next_verify_at, write_count) ON repair_attempts TO upfixy_executor;
GRANT SELECT, INSERT ON repair_events TO upfixy_executor;
-- What the executor needs to re-check at execution time.
CREATE POLICY x_q ON quotes FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
CREATE POLICY x_ap ON approvals FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
CREATE POLICY x_tk ON tickets FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
CREATE POLICY x_mt ON monitor_targets FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
CREATE POLICY x_web ON websites FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
CREATE POLICY x_obs ON observations FOR SELECT TO upfixy_executor USING (workspace_id = app_workspace_id());
GRANT SELECT ON quotes, approvals, tickets, monitor_targets, websites, observations TO upfixy_executor;
GRANT SELECT ON action_catalog TO upfixy_executor;
GRANT EXECUTE ON FUNCTION site_private_access(uuid, uuid, text) TO upfixy_executor;
GRANT EXECUTE ON FUNCTION quote_fingerprint(quotes, monitor_targets) TO upfixy_executor;

-- ------------------------------------------------------- app functions
CREATE FUNCTION app_is_staff_for(p_website uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM websites w WHERE w.id = p_website AND w.workspace_id = app_workspace_id()
                   AND (app_role() IN ('owner', 'admin') OR (app_role() = 'technician' AND w.client_id IS NOT NULL AND app_is_assigned(w.client_id))))
$$;
REVOKE ALL ON FUNCTION app_is_staff_for(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_is_staff_for(uuid) TO upfixy_app;

-- Store a scoped DNS-edit token sealed to the executor's key. Owner/admin only; the API adds step-up MFA.
CREATE FUNCTION create_dns_connection(p_website uuid, p_zone_id text, p_zone_name text, p_sealed text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE id uuid;
BEGIN
  IF NOT EXISTS (SELECT 1 FROM websites w WHERE w.id = p_website AND w.workspace_id = app_workspace_id() AND app_can_see_client(w.client_id)) THEN
    RAISE EXCEPTION 'website not found' USING ERRCODE = 'P0002';
  END IF;
  IF app_role() NOT IN ('owner', 'admin') THEN RAISE EXCEPTION 'only owners and admins can connect DNS' USING ERRCODE = '42501'; END IF;
  INSERT INTO dns_connections(workspace_id, website_id, provider, zone_id, zone_name, secret_sealed, created_by)
  VALUES (app_workspace_id(), p_website, 'cloudflare', p_zone_id, lower(p_zone_name), p_sealed, app_user_id()) RETURNING dns_connections.id INTO id;
  RETURN id;
END $$;

-- Revoke (owner/admin). Queued work holding the old version refuses to run.
CREATE FUNCTION revoke_dns_connection(p_connection uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF app_role() NOT IN ('owner', 'admin') THEN RAISE EXCEPTION 'only owners and admins can revoke DNS connections' USING ERRCODE = '42501'; END IF;
  UPDATE dns_connections SET revoked_at = now(), revoked_by = app_user_id(), secret_version = secret_version + 1, secret_sealed = 'revoked'
   WHERE id = p_connection AND workspace_id = app_workspace_id() AND revoked_at IS NULL;
  IF NOT FOUND THEN RAISE EXCEPTION 'connection not found' USING ERRCODE = 'P0002'; END IF;
END $$;

-- Rotate: a new sealed token and a new version. Anything queued on the old one refuses.
CREATE FUNCTION rotate_dns_connection(p_connection uuid, p_sealed text) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v integer;
BEGIN
  IF app_role() NOT IN ('owner', 'admin') THEN RAISE EXCEPTION 'only owners and admins can rotate DNS connections' USING ERRCODE = '42501'; END IF;
  UPDATE dns_connections SET secret_sealed = p_sealed, secret_version = secret_version + 1, verified_at = NULL, verify_result = NULL
   WHERE id = p_connection AND workspace_id = app_workspace_id() AND revoked_at IS NULL
  RETURNING secret_version INTO v;
  IF v IS NULL THEN RAISE EXCEPTION 'connection not found' USING ERRCODE = 'P0002'; END IF;
  RETURN v;
END $$;

-- Propose: staff for this website; the executor will read the record and build the canonical plan.
CREATE FUNCTION create_repair_draft(p_ticket uuid, p_target uuid, p_connection uuid, p_record text, p_content text, p_run uuid) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE tk tickets; c dns_connections; id uuid;
BEGIN
  SELECT * INTO tk FROM tickets WHERE tickets.id = p_ticket AND workspace_id = app_workspace_id();
  IF NOT FOUND OR NOT app_is_staff_for(tk.website_id) THEN RAISE EXCEPTION 'ticket not found' USING ERRCODE = 'P0002'; END IF;
  SELECT * INTO c FROM dns_connections d WHERE d.id = p_connection AND d.workspace_id = app_workspace_id()
     AND d.website_id = tk.website_id AND d.revoked_at IS NULL;
  IF NOT FOUND THEN RAISE EXCEPTION 'connection not found' USING ERRCODE = 'P0002'; END IF;
  IF NOT EXISTS (SELECT 1 FROM monitor_targets t WHERE t.id = p_target AND t.website_id = tk.website_id) THEN
    RAISE EXCEPTION 'address not found' USING ERRCODE = 'P0002';
  END IF;
  INSERT INTO repair_drafts(workspace_id, ticket_id, monitor_target_id, connection_id, diagnostic_run_id, record_id, proposed_content, requested_by)
  VALUES (app_workspace_id(), p_ticket, p_target, p_connection, p_run, p_record, p_content, app_user_id()) RETURNING repair_drafts.id INTO id;
  RETURN id;
END $$;

-- Request execution of an APPROVED quote. Checks here are for clear errors; the executor re-checks everything.
CREATE FUNCTION request_repair(p_approval uuid, p_key text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE a approvals; q quotes; tk tickets; c dns_connections; id uuid;
BEGIN
  SELECT * INTO a FROM approvals WHERE approvals.id = p_approval AND workspace_id = app_workspace_id();
  IF NOT FOUND THEN RAISE EXCEPTION 'approval not found' USING ERRCODE = 'P0002'; END IF;
  SELECT * INTO tk FROM tickets WHERE tickets.id = a.ticket_id;
  IF NOT app_is_staff_for(tk.website_id) THEN RAISE EXCEPTION 'approval not found' USING ERRCODE = 'P0002'; END IF;
  SELECT * INTO q FROM quotes WHERE quotes.id = a.quote_id;
  IF q.action_code <> 'dns.record_fix' OR q.params->>'operation' IS DISTINCT FROM 'cloudflare.dns.update_address_record' THEN
    RAISE EXCEPTION 'this approval is not for an automated DNS repair' USING ERRCODE = '22023';
  END IF;
  -- Idempotent: the same key returns the same attempt (duplicate clicks, retried requests).
  SELECT ra.id INTO id FROM repair_attempts ra WHERE ra.workspace_id = app_workspace_id() AND ra.idempotency_key = p_key AND ra.approval_id = p_approval;
  IF FOUND THEN RETURN id; END IF;
  SELECT * INTO c FROM dns_connections WHERE dns_connections.id = (q.params->>'connection_id')::uuid AND workspace_id = app_workspace_id();
  INSERT INTO repair_attempts(workspace_id, ticket_id, quote_id, approval_id, connection_id, connection_secret_version, zone_id, record_id,
                              plan, plan_fingerprint, idempotency_key, requested_by)
  VALUES (app_workspace_id(), a.ticket_id, q.id, a.id, c.id, c.secret_version, q.params->>'zone_id', q.params->>'record_id',
          q.params, q.fingerprint, p_key, app_user_id()) RETURNING repair_attempts.id INTO id;
  RETURN id;
END $$;

-- Pause or resume repairs for the caller's workspace. Pausing is always allowed for owner/admin;
-- resuming needs the owner (the API also requires fresh MFA). The global switch is platform-only.
CREATE FUNCTION set_workspace_repairs_paused(p_paused boolean, p_reason text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF app_role() NOT IN ('owner', 'admin') OR (NOT p_paused AND app_role() <> 'owner') THEN
    RAISE EXCEPTION 'not allowed to change repair controls' USING ERRCODE = '42501';
  END IF;
  INSERT INTO repair_controls(workspace_id, paused, reason, changed_by) VALUES (app_workspace_id(), p_paused, p_reason, app_user_id())
  ON CONFLICT (coalesce(workspace_id, '00000000-0000-0000-0000-000000000000'::uuid))
  DO UPDATE SET paused = EXCLUDED.paused, reason = EXCLUDED.reason, changed_by = EXCLUDED.changed_by, changed_at = now();
END $$;

REVOKE ALL ON FUNCTION create_dns_connection(uuid, text, text, text), revoke_dns_connection(uuid), rotate_dns_connection(uuid, text),
  create_repair_draft(uuid, uuid, uuid, text, text, uuid), request_repair(uuid, text), set_workspace_repairs_paused(boolean, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION create_dns_connection(uuid, text, text, text), revoke_dns_connection(uuid), rotate_dns_connection(uuid, text),
  create_repair_draft(uuid, uuid, uuid, text, text, uuid), request_repair(uuid, text), set_workspace_repairs_paused(boolean, text) TO upfixy_app;

-- ----------------------------------------------------- executor functions
-- Work across workspaces is claimed here; everything after runs inside the claimed workspace.
CREATE FUNCTION executor_claim(p_lease_seconds integer)
RETURNS TABLE(kind text, id uuid, workspace_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
#variable_conflict use_column
BEGIN
  IF session_user NOT IN ('upfixy_executor', 'upfixy_owner') THEN RAISE EXCEPTION 'executor only' USING ERRCODE = '42501'; END IF;
  RETURN QUERY
  WITH d AS (
    SELECT rd.id, rd.workspace_id FROM repair_drafts rd WHERE rd.state = 'reading' ORDER BY rd.created_at LIMIT 1 FOR UPDATE SKIP LOCKED
  )
  SELECT 'draft'::text, d.id, d.workspace_id FROM d;
  IF FOUND THEN RETURN; END IF;
  RETURN QUERY
  WITH a AS (
    SELECT ra.id FROM repair_attempts ra
     WHERE ra.state IN ('queued', 'preflight', 'executing', 'verifying')
       AND (ra.lease_until IS NULL OR ra.lease_until < now())
       AND (ra.state <> 'verifying' OR ra.next_verify_at IS NULL OR ra.next_verify_at <= now())
     ORDER BY ra.created_at LIMIT 1 FOR UPDATE SKIP LOCKED
  ), u AS (
    UPDATE repair_attempts ra SET lease_until = now() + make_interval(secs => p_lease_seconds)
      FROM a WHERE ra.id = a.id RETURNING ra.id, ra.workspace_id
  )
  SELECT 'attempt'::text, u.id, u.workspace_id FROM u;
END $$;
REVOKE ALL ON FUNCTION executor_claim(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION executor_claim(integer) TO upfixy_executor;

-- Ticket moves made by the executor, versioned and recorded like any other transition.
CREATE FUNCTION executor_move_ticket(p_ticket uuid, p_to ticket_state, p_reason text, p_actor uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE tk tickets;
BEGIN
  SELECT * INTO tk FROM tickets WHERE id = p_ticket AND workspace_id = app_workspace_id() FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'ticket not found' USING ERRCODE = 'P0002'; END IF;
  IF tk.state = p_to THEN RETURN; END IF;
  UPDATE tickets SET state = p_to, version = version + 1 WHERE id = p_ticket;
  INSERT INTO ticket_events(workspace_id, ticket_id, actor_id, from_state, to_state, reason)
  VALUES (tk.workspace_id, p_ticket, p_actor, tk.state, p_to, p_reason);
END $$;
REVOKE ALL ON FUNCTION executor_move_ticket(uuid, ticket_state, text, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION executor_move_ticket(uuid, ticket_state, text, uuid) TO upfixy_executor;

-- The executor may read the sealed secret only for the connection of the attempt it holds.
CREATE FUNCTION executor_sealed_secret(p_connection uuid, p_version integer) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT secret_sealed FROM dns_connections
   WHERE id = p_connection AND workspace_id = app_workspace_id() AND revoked_at IS NULL AND secret_version = p_version
     AND session_user IN ('upfixy_executor', 'upfixy_owner')
$$;
REVOKE ALL ON FUNCTION executor_sealed_secret(uuid, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION executor_sealed_secret(uuid, integer) TO upfixy_executor;
REVOKE SELECT (secret_sealed) ON dns_connections FROM upfixy_executor;
REVOKE SELECT ON dns_connections FROM upfixy_executor;
GRANT SELECT (id, workspace_id, website_id, provider, zone_id, zone_name, secret_version, created_by, created_at,
              verified_at, verify_result, revoked_at, revoked_by) ON dns_connections TO upfixy_executor;

-- S-06: a quote that carries an automated-repair plan must be the EXACT plan the executor read
-- from the provider (a ready preview draft for the same ticket and address). Staff cannot hand-write
-- an automated change into a quote, and no other action may carry an operation.
CREATE FUNCTION quotes_repair_plan_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.params ? 'operation' THEN
    IF NEW.action_code <> 'dns.record_fix' OR NOT EXISTS (
      SELECT 1 FROM repair_drafts d
       WHERE d.workspace_id = NEW.workspace_id AND d.ticket_id = NEW.ticket_id AND d.monitor_target_id = NEW.monitor_target_id
         AND d.state = 'ready' AND d.plan = NEW.params) THEN
      RAISE EXCEPTION 'automated changes can only be quoted from a provider-read preview' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER quotes_repair_plan_guard BEFORE INSERT ON quotes FOR EACH ROW EXECUTE FUNCTION quotes_repair_plan_guard();
