-- Security hardening (Task 5): tamper-evident audit, MFA lockout, password reset, workspace soft close.
-- FRD ACC-002, ACC-007, NFR-14, D-12 (retention still open), SECURITY.md known gaps.

-- ================================================================ audit chain
-- Every audit row carries the SHA-256 of (previous row hash || its own content). Changing, inserting
-- or deleting any row breaks every later hash. One global chain, serialised by an advisory lock
-- (fine at MVP volume). Anchors (the latest hash, copied OUTSIDE the database) make even a full
-- rewrite by a database owner detectable.
ALTER TABLE audit_events ADD COLUMN prev_hash text, ADD COLUMN row_hash text;

CREATE FUNCTION audit_row_hash(p_prev text, r audit_events) RETURNS text
LANGUAGE sql IMMUTABLE AS $$
  SELECT encode(sha256(convert_to(concat_ws('|', 'upfixy-audit-v1', coalesce(p_prev, 'genesis'), r.id, r.workspace_id, r.actor_id, r.action,
    r.target_type, r.target_id, r.outcome, host(r.ip), r.trace_id, r.detail::text,
    to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')), 'UTF8')), 'hex')
$$;

-- Backfill existing rows in order.
DO $$
DECLARE r audit_events; prev text := NULL;
BEGIN
  ALTER TABLE audit_events DISABLE TRIGGER USER;
  FOR r IN SELECT * FROM audit_events ORDER BY id LOOP
    UPDATE audit_events SET prev_hash = prev, row_hash = audit_row_hash(prev, r) WHERE id = r.id;
    prev := audit_row_hash(prev, r);
  END LOOP;
  ALTER TABLE audit_events ENABLE TRIGGER USER;
END $$;
ALTER TABLE audit_events ALTER COLUMN row_hash SET NOT NULL;

-- ids are assigned under the same lock as the chain link, so id order and chain order always agree.
ALTER TABLE audit_events ALTER COLUMN id DROP IDENTITY IF EXISTS;
CREATE SEQUENCE audit_events_id_seq OWNED BY audit_events.id;
SELECT setval('audit_events_id_seq', coalesce((SELECT max(id) FROM audit_events), 0) + 1, false);
CREATE FUNCTION audit_chain_link() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  PERFORM pg_advisory_xact_lock(7420191);           -- one writer at a time extends the chain
  NEW.id := nextval('audit_events_id_seq');
  NEW.created_at := clock_timestamp();
  SELECT row_hash INTO NEW.prev_hash FROM audit_events ORDER BY id DESC LIMIT 1;
  NEW.row_hash := audit_row_hash(NEW.prev_hash, NEW);
  RETURN NEW;
END $$;
CREATE TRIGGER audit_chain_link BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION audit_chain_link();
ALTER TABLE audit_events ALTER COLUMN id SET DEFAULT 0;   -- placeholder; the trigger assigns the real id under the lock

-- External anchors: the newest hash at a point in time. The anchor job also writes it outside the DB.
CREATE TABLE audit_anchors (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  last_id    bigint NOT NULL,
  last_hash  text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER audit_anchors_immutable BEFORE UPDATE OR DELETE ON audit_anchors FOR EACH ROW EXECUTE FUNCTION append_only();

-- Verify the chain. Returns the first broken row (NULL = intact).
CREATE FUNCTION audit_verify(p_from bigint DEFAULT 0) RETURNS TABLE(broken_id bigint, reason text, checked bigint)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r audit_events; prev text; n bigint := 0; first boolean := true;
BEGIN
  IF session_user NOT IN ('upfixy_owner', 'upfixy_worker') AND NOT coalesce((SELECT rolsuper FROM pg_roles WHERE rolname = session_user), false) THEN
    RAISE EXCEPTION 'maintenance only' USING ERRCODE = '42501';
  END IF;
  SELECT row_hash INTO prev FROM audit_events WHERE id < p_from ORDER BY id DESC LIMIT 1;
  FOR r IN SELECT * FROM audit_events WHERE id >= p_from ORDER BY id LOOP
    n := n + 1;
    IF r.prev_hash IS DISTINCT FROM prev AND NOT (first AND p_from > 0 AND prev IS NULL) THEN
      broken_id := r.id; reason := 'link to previous row does not match (a row was removed, inserted or reordered)'; checked := n; RETURN NEXT; RETURN;
    END IF;
    IF r.row_hash <> audit_row_hash(r.prev_hash, r) THEN
      broken_id := r.id; reason := 'row content does not match its hash (the row was changed)'; checked := n; RETURN NEXT; RETURN;
    END IF;
    prev := r.row_hash; first := false;
  END LOOP;
  broken_id := NULL; reason := NULL; checked := n; RETURN NEXT;
END $$;

CREATE FUNCTION audit_anchor() RETURNS TABLE(last_id bigint, last_hash text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF session_user NOT IN ('upfixy_owner', 'upfixy_worker') AND NOT coalesce((SELECT rolsuper FROM pg_roles WHERE rolname = session_user), false) THEN
    RAISE EXCEPTION 'maintenance only' USING ERRCODE = '42501';
  END IF;
  RETURN QUERY INSERT INTO audit_anchors(last_id, last_hash)
    SELECT a.id, a.row_hash FROM audit_events a ORDER BY a.id DESC LIMIT 1
    RETURNING audit_anchors.last_id, audit_anchors.last_hash;
END $$;
REVOKE ALL ON FUNCTION audit_verify(bigint), audit_anchor() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION audit_verify(bigint), audit_anchor() TO upfixy_worker;
-- The app may insert audit rows but never choose their id, hash or time.
REVOKE INSERT ON audit_events FROM upfixy_app;
GRANT INSERT (workspace_id, actor_id, action, target_type, target_id, outcome, ip, trace_id, detail) ON audit_events TO upfixy_app;

-- ================================================================== MFA lockout
-- 5 wrong codes in a row lock MFA for 15 minutes (doubling for repeat lockouts, max 24 h).
-- Applies across all sessions of the user. Recorded outside the failing request's transaction.
ALTER TABLE users ADD COLUMN mfa_failures integer NOT NULL DEFAULT 0,
                  ADD COLUMN mfa_lockouts integer NOT NULL DEFAULT 0,
                  ADD COLUMN mfa_locked_until timestamptz;
GRANT SELECT (mfa_locked_until) ON users TO upfixy_app;

CREATE FUNCTION auth_mfa_failure(p_user uuid) RETURNS timestamptz
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE f integer; l integer; until timestamptz;
BEGIN
  IF p_user IS DISTINCT FROM app_user_id() THEN RAISE EXCEPTION 'own account only' USING ERRCODE = '42501'; END IF;
  UPDATE users SET mfa_failures = mfa_failures + 1 WHERE id = p_user RETURNING mfa_failures, mfa_lockouts INTO f, l;
  IF f >= 5 THEN
    until := now() + least(interval '24 hours', interval '15 minutes' * power(2, least(l, 7))::integer);
    UPDATE users SET mfa_failures = 0, mfa_lockouts = mfa_lockouts + 1, mfa_locked_until = until WHERE id = p_user;
    -- Sessions waiting for an MFA step are useless now; drop them.
    DELETE FROM personal_access_tokens WHERE tokenable_id = p_user AND abilities::text LIKE '%mfa:challenge%';
    RETURN until;
  END IF;
  RETURN NULL;
END $$;

CREATE FUNCTION auth_mfa_success() RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  UPDATE users SET mfa_failures = 0, mfa_lockouts = 0, mfa_locked_until = NULL WHERE id = app_user_id() AND (mfa_failures > 0 OR mfa_lockouts > 0)
$$;
REVOKE ALL ON FUNCTION auth_mfa_failure(uuid), auth_mfa_success() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION auth_mfa_failure(uuid), auth_mfa_success() TO upfixy_app;

-- =============================================================== password reset
CREATE TABLE password_reset_tokens (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash  text NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
  created_at  timestamptz NOT NULL DEFAULT now(),
  expires_at  timestamptz NOT NULL,
  used_at     timestamptz
);
ALTER TABLE password_reset_tokens ENABLE ROW LEVEL SECURITY;   -- no policies: functions only

-- Returns the email to send to, or NULL (the API answers the same either way: no account enumeration).
CREATE FUNCTION issue_password_reset(p_email citext, p_hash text, p_minutes integer) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE uid uuid; recent integer;
BEGIN
  IF p_minutes NOT BETWEEN 5 AND 120 THEN RAISE EXCEPTION 'invalid lifetime'; END IF;
  SELECT id INTO uid FROM users WHERE email = p_email;
  IF uid IS NULL THEN RETURN NULL; END IF;
  -- At most 3 links per hour per account (mailbox flooding).
  SELECT count(*) INTO recent FROM password_reset_tokens WHERE user_id = uid AND created_at > now() - interval '1 hour';
  IF recent >= 3 THEN RETURN NULL; END IF;
  UPDATE password_reset_tokens SET used_at = now() WHERE user_id = uid AND used_at IS NULL;   -- only the newest link works
  INSERT INTO password_reset_tokens(user_id, token_hash, expires_at) VALUES (uid, p_hash, now() + make_interval(mins => p_minutes));
  RETURN uid;
END $$;

-- One use, unexpired. Sets the new bcrypt hash and signs the account out everywhere.
CREATE FUNCTION consume_password_reset(p_hash text, p_new_password_hash text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE uid uuid;
BEGIN
  IF p_new_password_hash IS NULL OR p_new_password_hash !~ '^\$2[aby]\$' THEN RAISE EXCEPTION 'password must be a bcrypt hash'; END IF;
  UPDATE password_reset_tokens SET used_at = now()
   WHERE token_hash = p_hash AND used_at IS NULL AND expires_at > now()
  RETURNING user_id INTO uid;
  IF uid IS NULL THEN RETURN NULL; END IF;
  UPDATE users SET password_hash = p_new_password_hash WHERE id = uid;
  DELETE FROM personal_access_tokens WHERE tokenable_id = uid;
  RETURN uid;
END $$;
REVOKE ALL ON FUNCTION issue_password_reset(citext, text, integer), consume_password_reset(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION issue_password_reset(citext, text, integer), consume_password_reset(text, text) TO upfixy_app;

CREATE FUNCTION user_email(p_user uuid) RETURNS citext
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT email FROM users WHERE id = p_user AND p_user = app_user_id()
$$;
REVOKE ALL ON FUNCTION user_email(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION user_email(uuid) TO upfixy_app;

-- ========================================================== workspace soft close
-- NFR-14: a workspace with financial records can't be deleted (quotes, approvals, payments and
-- receipts are append-only). Closing stops all activity and access but keeps those records.
-- Final deletion/anonymisation waits for the retention decision (D-12).
ALTER TABLE workspaces ADD COLUMN closed_at timestamptz, ADD COLUMN closed_by uuid, ADD COLUMN close_reason text;
GRANT SELECT (closed_at) ON workspaces TO upfixy_app;

-- A closed workspace has no roles: every role-based policy and check fails closed.
CREATE OR REPLACE FUNCTION app_role() RETURNS member_role
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT m.role FROM memberships m JOIN workspaces w ON w.id = m.workspace_id AND w.closed_at IS NULL
   WHERE m.workspace_id = app_workspace_id()
     AND m.user_id = app_user_id()
     AND m.suspended_at IS NULL
$$;

-- Is this workspace closed (for members, to show a clear message instead of "not found")?
CREATE FUNCTION workspace_closed_at(p_ws uuid) RETURNS timestamptz
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT w.closed_at FROM workspaces w
   WHERE w.id = p_ws AND EXISTS (SELECT 1 FROM memberships m WHERE m.workspace_id = w.id AND m.user_id = app_user_id())
$$;

CREATE FUNCTION close_workspace(p_reason text) RETURNS timestamptz
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE ws uuid := app_workspace_id(); t timestamptz := now();
BEGIN
  IF app_role() IS DISTINCT FROM 'owner' THEN RAISE EXCEPTION 'only the owner can close a workspace' USING ERRCODE = '42501'; END IF;
  -- Stop everything that runs on its own.
  UPDATE monitor_targets SET state = 'suspended', state_reason = 'workspace closed', lease_until = NULL WHERE workspace_id = ws AND state <> 'suspended';
  UPDATE connectors SET revoked_at = t, revoked_by = app_user_id(), secret_sealed = 'revoked', secret_version = secret_version + 1,
         status = 'revoked', verified_capabilities = '{}' WHERE workspace_id = ws AND revoked_at IS NULL;
  UPDATE dns_connections SET revoked_at = t, revoked_by = app_user_id(), secret_version = secret_version + 1, secret_sealed = 'revoked'
   WHERE workspace_id = ws AND revoked_at IS NULL;
  UPDATE repair_controls SET paused = true, reason = 'workspace closed', changed_at = now(), changed_by = app_user_id() WHERE workspace_id = ws;
  IF NOT FOUND THEN INSERT INTO repair_controls(workspace_id, paused, reason, changed_by) VALUES (ws, true, 'workspace closed', app_user_id()); END IF;
  UPDATE quotes SET voided_at = t, void_reason = 'workspace closed' WHERE workspace_id = ws AND voided_at IS NULL;
  UPDATE invitations SET expires_at = least(expires_at, t) WHERE workspace_id = ws AND accepted_at IS NULL;
  UPDATE delegations SET revoked_at = t WHERE workspace_id = ws AND revoked_at IS NULL;
  UPDATE ownership_claims SET revoked_at = t WHERE workspace_id = ws AND revoked_at IS NULL;
  UPDATE workspaces SET closed_at = t, closed_by = app_user_id(), close_reason = left(p_reason, 300) WHERE id = ws;
  RETURN t;
END $$;

-- The owner can reopen within 30 days. Credentials, quotes and proofs stay revoked: they are redone.
CREATE FUNCTION reopen_workspace(p_ws uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM memberships m JOIN workspaces w ON w.id = m.workspace_id
                  WHERE m.workspace_id = p_ws AND m.user_id = app_user_id() AND m.role = 'owner' AND m.suspended_at IS NULL
                    AND w.closed_at IS NOT NULL AND w.closed_at > now() - interval '30 days') THEN
    RAISE EXCEPTION 'only the owner can reopen, within 30 days of closing' USING ERRCODE = '42501';
  END IF;
  UPDATE workspaces SET closed_at = NULL, closed_by = NULL, close_reason = NULL WHERE id = p_ws;
  UPDATE monitor_targets SET state = 'pending_validation', state_reason = NULL, next_check_at = now()
   WHERE workspace_id = p_ws AND state = 'suspended' AND state_reason = 'workspace closed';
END $$;
REVOKE ALL ON FUNCTION close_workspace(text), reopen_workspace(uuid), workspace_closed_at(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION close_workspace(text), reopen_workspace(uuid), workspace_closed_at(uuid) TO upfixy_app;
