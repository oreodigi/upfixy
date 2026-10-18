-- Identity and token hardening.
-- Before this migration the app role could SELECT every user's password hash
-- and every API token hash, and UPDATE any user row. Now:
--   * users and tokens have row-level security;
--   * secret columns (password hash, MFA material) are not selectable at all;
--   * login, registration and token lookup go through narrow SECURITY DEFINER
--     functions that return only what that step needs;
--   * no function is executable by PUBLIC unless granted explicitly.
--
-- Threat model note: RLS context (app.user_id / app.workspace_id) is set by the
-- API with set_config(). It protects against application logic bugs (IDOR,
-- missing filters, wrong joins). It does not protect against arbitrary SQL
-- execution as upfixy_app, which could set its own context; parameterised
-- queries are the control for that.

-- ------------------------------------------------------------ function EXECUTE
-- PostgreSQL grants EXECUTE on new functions to PUBLIC by default. Remove that
-- for everything, now and in future migrations, then grant per role.
-- Note: a per-schema "ALTER DEFAULT PRIVILEGES IN SCHEMA ... REVOKE" cannot
-- remove the GLOBAL default grant to PUBLIC; it has to be revoked for the role
-- that creates the functions. The hardening tests check no function is open.
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA public FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE upfixy_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

-- Helpers evaluated inside RLS policies run with the querying role's
-- privileges, so both runtime roles need EXECUTE on them.
GRANT EXECUTE ON FUNCTION app_user_id(), app_workspace_id(), app_role(), app_is_member(uuid),
  app_is_assigned(uuid), app_can_see_client(uuid), app_can_approve(uuid),
  ticket_transition_allowed(ticket_state, ticket_state)
  TO upfixy_app, upfixy_worker;
GRANT EXECUTE ON FUNCTION create_workspace(text, workspace_kind) TO upfixy_app;

-- -------------------------------------------------------------------- users
ALTER TABLE users
  ADD COLUMN mfa_secret_enc         text,      -- encrypted by the API with APP_KEY
  ADD COLUMN mfa_pending_secret_enc text,      -- set during enrolment, until confirmed
  ADD COLUMN mfa_recovery_hashes    text[],    -- HMAC-SHA256 of one-time recovery codes
  ADD COLUMN mfa_last_step          bigint;    -- last accepted TOTP time step (replay guard)

-- Co-members of the current workspace are visible to that workspace's
-- owner/admin (team page). Everyone sees their own row.
CREATE FUNCTION app_can_see_user(u uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT u = app_user_id()
      OR (app_role() IN ('owner', 'admin') AND EXISTS (
            SELECT 1 FROM memberships m WHERE m.workspace_id = app_workspace_id() AND m.user_id = u))
$$;
GRANT EXECUTE ON FUNCTION app_can_see_user(uuid) TO upfixy_app;

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
CREATE POLICY users_select ON users FOR SELECT TO upfixy_app USING (app_can_see_user(id));
CREATE POLICY users_update ON users FOR UPDATE TO upfixy_app
  USING (id = app_user_id()) WITH CHECK (id = app_user_id());

REVOKE ALL ON users FROM upfixy_app;
-- Non-secret columns only. password_hash and MFA material are never selectable.
GRANT SELECT (id, email, email_verified_at, mfa_enabled_at, created_at) ON users TO upfixy_app;
-- Self-service writes; email and email_verified_at change only through functions.
GRANT UPDATE (password_hash, mfa_secret_enc, mfa_pending_secret_enc, mfa_enabled_at,
              mfa_recovery_hashes, mfa_last_step) ON users TO upfixy_app;  -- direct writes: set pending secret, clear on disable

-- Registration. Unique violation (23505) is mapped to a friendly API error.
CREATE FUNCTION auth_register(p_email citext, p_password_hash text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE uid uuid;
BEGIN
  IF p_password_hash IS NULL OR p_password_hash !~ '^\$2[aby]\$' THEN
    RAISE EXCEPTION 'password must be a bcrypt hash';
  END IF;
  INSERT INTO users(email, password_hash) VALUES (p_email, p_password_hash) RETURNING id INTO uid;
  RETURN uid;
END $$;

-- Login: one row for one email, only what password + MFA checks need.
CREATE FUNCTION auth_lookup(p_email citext)
RETURNS TABLE(id uuid, password_hash text, mfa_enabled boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT u.id, u.password_hash, u.mfa_enabled_at IS NOT NULL FROM users u WHERE u.email = p_email
$$;

-- MFA material for the CURRENT user only (needed to check a TOTP code).
CREATE FUNCTION auth_my_mfa()
RETURNS TABLE(secret_enc text, pending_secret_enc text, recovery_hashes text[], last_step bigint, enabled_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT u.mfa_secret_enc, u.mfa_pending_secret_enc, u.mfa_recovery_hashes, u.mfa_last_step, u.mfa_enabled_at
    FROM users u WHERE u.id = app_user_id()
$$;

-- MFA state changes that must read secret columns run here, for the current
-- user only. (UPDATE ... SET a = b or WHERE a < x needs SELECT on those columns,
-- which the app role deliberately does not have.)
CREATE FUNCTION auth_mfa_activate(p_recovery_hashes text[], p_step bigint) RETURNS boolean
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  UPDATE users SET mfa_secret_enc = mfa_pending_secret_enc, mfa_pending_secret_enc = NULL,
                   mfa_enabled_at = now(), mfa_recovery_hashes = p_recovery_hashes, mfa_last_step = p_step
   WHERE id = app_user_id() AND mfa_pending_secret_enc IS NOT NULL
  RETURNING true
$$;

-- Replay guard: a TOTP time step is accepted at most once, even concurrently.
CREATE FUNCTION auth_mfa_use_step(p_step bigint) RETURNS boolean
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  WITH used AS (
    UPDATE users SET mfa_last_step = p_step
     WHERE id = app_user_id() AND mfa_enabled_at IS NOT NULL AND (mfa_last_step IS NULL OR mfa_last_step < p_step)
    RETURNING 1)
  SELECT EXISTS (SELECT 1 FROM used)
$$;

CREATE FUNCTION auth_mfa_use_recovery(p_hash text) RETURNS boolean
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  WITH used AS (
    UPDATE users SET mfa_recovery_hashes = array_remove(mfa_recovery_hashes, p_hash)
     WHERE id = app_user_id() AND mfa_enabled_at IS NOT NULL AND p_hash = ANY (mfa_recovery_hashes)
    RETURNING 1)
  SELECT EXISTS (SELECT 1 FROM used)
$$;

GRANT EXECUTE ON FUNCTION auth_register(citext, text), auth_lookup(citext), auth_my_mfa(),
  auth_mfa_activate(text[], bigint), auth_mfa_use_step(bigint), auth_mfa_use_recovery(text) TO upfixy_app;

-- ------------------------------------------------------------------- tokens
ALTER TABLE personal_access_tokens
  ADD COLUMN mfa_verified_at timestamp;   -- set on MFA login / step-up; drives fresh-MFA checks

-- Token lookup by id, before any request context exists. Returns the hash so
-- the API can compare in constant time, plus the minimal user fields Sanctum
-- needs. Expired tokens are never returned.
CREATE FUNCTION auth_token_lookup(p_id bigint)
RETURNS TABLE(id bigint, tokenable_type varchar, tokenable_id uuid, name text, token varchar,
              abilities text, last_used_at timestamp, expires_at timestamp, created_at timestamp,
              updated_at timestamp, mfa_verified_at timestamp,
              user_email citext, user_email_verified_at timestamptz, user_mfa_enabled_at timestamptz,
              user_created_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT t.id, t.tokenable_type, t.tokenable_id, t.name, t.token, t.abilities, t.last_used_at,
         t.expires_at, t.created_at, t.updated_at, t.mfa_verified_at,
         u.email, u.email_verified_at, u.mfa_enabled_at, u.created_at
    FROM personal_access_tokens t JOIN users u ON u.id = t.tokenable_id
   WHERE t.id = p_id AND (t.expires_at IS NULL OR t.expires_at > now() AT TIME ZONE 'UTC')
$$;
GRANT EXECUTE ON FUNCTION auth_token_lookup(bigint) TO upfixy_app;

ALTER TABLE personal_access_tokens ENABLE ROW LEVEL SECURITY;
CREATE POLICY pat_own ON personal_access_tokens FOR ALL TO upfixy_app
  USING (tokenable_id = app_user_id()) WITH CHECK (tokenable_id = app_user_id());

REVOKE ALL ON personal_access_tokens FROM upfixy_app;
GRANT SELECT (id, tokenable_type, tokenable_id, name, abilities, last_used_at, expires_at,
              created_at, updated_at, mfa_verified_at) ON personal_access_tokens TO upfixy_app;
GRANT INSERT, DELETE ON personal_access_tokens TO upfixy_app;
GRANT UPDATE (last_used_at, updated_at, mfa_verified_at) ON personal_access_tokens TO upfixy_app;
-- RETURNING id after INSERT needs SELECT on id only, already granted above.

-- Every new token must expire.
ALTER TABLE personal_access_tokens ADD CONSTRAINT pat_expires CHECK (expires_at IS NOT NULL) NOT VALID;

-- ------------------------------------------------------ narrower table writes
-- Monitor state is set by the system (validation / worker), never by a user.
CREATE FUNCTION monitor_targets_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF current_user = 'upfixy_app' THEN NEW.state := 'pending_validation'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER monitor_targets_insert_guard BEFORE INSERT ON monitor_targets
  FOR EACH ROW EXECUTE FUNCTION monitor_targets_insert_guard();
REVOKE UPDATE ON monitor_targets FROM upfixy_app;
GRANT UPDATE (interval_seconds) ON monitor_targets TO upfixy_app;

-- Workers activate targets after validation (M3).
CREATE FUNCTION worker_set_target_state(p_target uuid, p_state monitor_state) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  UPDATE monitor_targets SET state = p_state WHERE id = p_target AND workspace_id = app_workspace_id()
$$;
GRANT EXECUTE ON FUNCTION worker_set_target_state(uuid, monitor_state) TO upfixy_worker;

REVOKE UPDATE ON websites FROM upfixy_app;
GRANT UPDATE (display_name, client_id) ON websites TO upfixy_app;

REVOKE UPDATE ON tickets FROM upfixy_app;
GRANT UPDATE (title, state, version, assignee_id) ON tickets TO upfixy_app;

REVOKE UPDATE ON clients FROM upfixy_app;
GRANT UPDATE (name, agency_may_pay, client_self_service) ON clients TO upfixy_app;

-- Payment authority is a contract term: only the workspace owner may change it.
-- Runs as the caller (not SECURITY DEFINER) so current_user is the real role.
CREATE FUNCTION clients_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF current_user <> 'upfixy_owner' AND NEW.agency_may_pay IS DISTINCT FROM
     (CASE WHEN TG_OP = 'INSERT' THEN false ELSE OLD.agency_may_pay END)
     AND app_role() IS DISTINCT FROM 'owner' THEN
    RAISE EXCEPTION 'only the workspace owner can change payment authority' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER clients_guard BEFORE INSERT OR UPDATE ON clients
  FOR EACH ROW EXECUTE FUNCTION clients_guard();
