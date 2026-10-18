-- M4 read-only provider connectors (INT-001…004, INT-006, AST-004).
-- FRD §7, §16 "Provider isolation", §17.3 adapter contract, D-04 (reseller-only WHM), D-07.
--
-- Rules enforced here:
--  * Tokens are sealed to the sync worker's key; the API can store but never read them.
--    `secret_sealed` is not granted to any runtime role; the worker gets it only through
--    worker_claim_connectors().
--  * Capabilities are declared by the database per connector kind, never by the client.
--    Verified capabilities are always a subset of declared ones.
--  * A connector whose scope cannot be proven is REJECTED: its token is wiped and nothing
--    it returned is kept (AC-INT-002.3).
--  * Revoking or rotating bumps secret_version; in-flight work holding the old version is
--    refused (AC-INT-001.2).
--  * Nothing is imported until a person selects it (AC-INT-006.1). Ambiguous client mapping
--    is flagged "needs review" and not linked (AC-INT-006.2).
--  * The Cloudflare DNS-EDIT credential for repairs (dns_connections, M7) is a different table
--    and role; nothing here can reach it.

CREATE TYPE connector_kind AS ENUM ('cloudflare', 'whm_reseller', 'cpanel');
CREATE TYPE connector_status AS ENUM ('verifying', 'active', 'rejected', 'error', 'revoked');

CREATE TABLE connectors (
  id                     uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id           uuid NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  kind                   connector_kind NOT NULL,
  label                  text NOT NULL CHECK (length(label) BETWEEN 1 AND 80),
  base_host              text,
  username               text,
  secret_sealed          text NOT NULL,
  secret_version         integer NOT NULL DEFAULT 1,
  status                 connector_status NOT NULL DEFAULT 'verifying',
  status_reason          text,
  declared_capabilities  text[] NOT NULL,
  verified_capabilities  text[] NOT NULL DEFAULT '{}',
  resource_scope         jsonb NOT NULL DEFAULT '{}',
  warnings               text[] NOT NULL DEFAULT '{}',
  sync_interval_seconds  integer NOT NULL DEFAULT 3600 CHECK (sync_interval_seconds BETWEEN 300 AND 86400),
  sync_no                bigint NOT NULL DEFAULT 0,
  next_sync_at           timestamptz NOT NULL DEFAULT now(),
  lease_until            timestamptz,
  consecutive_failures   integer NOT NULL DEFAULT 0,
  error_class            text,
  last_attempt_at        timestamptz,
  last_success_at        timestamptz,
  created_by             uuid NOT NULL,
  created_at             timestamptz NOT NULL DEFAULT now(),
  revoked_at             timestamptz,
  revoked_by             uuid,
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  FOREIGN KEY (workspace_id, created_by) REFERENCES memberships(workspace_id, user_id) DEFERRABLE INITIALLY DEFERRED,
  -- Panels are addressed by hostname only; usernames are plain account names (no path tricks).
  CHECK ((kind = 'cloudflare' AND base_host IS NULL AND username IS NULL)
      OR (kind <> 'cloudflare'
          AND base_host ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$' AND length(base_host) <= 253
          AND username ~ '^[a-z][a-z0-9_.-]{0,31}$')),
  CHECK (verified_capabilities <@ declared_capabilities)
);
-- One live connection per panel account. (Cloudflare accounts can't be told apart before
-- verification, so several Cloudflare connections per workspace are allowed.)
CREATE UNIQUE INDEX connectors_live ON connectors(workspace_id, kind, base_host, username)
  WHERE revoked_at IS NULL AND status <> 'rejected' AND kind <> 'cloudflare';
CREATE INDEX connectors_due_idx ON connectors(next_sync_at) WHERE revoked_at IS NULL;

-- What a connector reported. Read-only metadata; never a source of ownership.
CREATE TABLE connector_assets (
  id                 uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id       uuid NOT NULL,
  connector_id       uuid NOT NULL,
  kind               text NOT NULL CHECK (kind IN ('zone', 'account', 'domain')),
  external_id        text NOT NULL CHECK (length(external_id) BETWEEN 1 AND 255),
  name               text NOT NULL CHECK (name = lower(name) AND length(name) BETWEEN 1 AND 253),
  parent_external_id text,
  facts              jsonb NOT NULL DEFAULT '{}',
  first_seen_at      timestamptz NOT NULL DEFAULT now(),
  last_seen_at       timestamptz NOT NULL DEFAULT now(),
  last_seen_sync     bigint NOT NULL,
  gone_at            timestamptz,
  import_state       text NOT NULL DEFAULT 'discovered' CHECK (import_state IN ('discovered', 'imported', 'ignored', 'needs_review')),
  review_reason      text,
  website_id         uuid,
  PRIMARY KEY (id),
  UNIQUE (connector_id, kind, external_id),
  FOREIGN KEY (workspace_id, connector_id) REFERENCES connectors(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE SET NULL (website_id),
  CHECK ((import_state = 'imported') = (website_id IS NOT NULL))
);
CREATE INDEX connector_assets_site_idx ON connector_assets(website_id) WHERE website_id IS NOT NULL;

-- When an imported website is deleted (FK sets website_id to NULL), the asset returns to the
-- preview list instead of breaking the imported ⇔ linked invariant.
CREATE FUNCTION connector_assets_unlink() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.website_id IS NULL AND NEW.import_state = 'imported' THEN NEW.import_state := 'discovered'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER connector_assets_unlink BEFORE UPDATE OF website_id ON connector_assets
  FOR EACH ROW EXECUTE FUNCTION connector_assets_unlink();

-- Append-only connector history (ACC-007).
CREATE TABLE connector_events (
  id            bigserial PRIMARY KEY,
  workspace_id  uuid NOT NULL,
  connector_id  uuid NOT NULL,
  kind          text NOT NULL,
  detail        jsonb NOT NULL DEFAULT '{}',
  at            timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (workspace_id, connector_id) REFERENCES connectors(workspace_id, id) ON DELETE CASCADE
);
CREATE TRIGGER connector_events_immutable BEFORE UPDATE OR DELETE ON connector_events
  FOR EACH ROW EXECUTE FUNCTION append_only();

-- ----------------------------------------------------------------- RLS
ALTER TABLE connectors ENABLE ROW LEVEL SECURITY;
ALTER TABLE connector_assets ENABLE ROW LEVEL SECURITY;
ALTER TABLE connector_events ENABLE ROW LEVEL SECURITY;

-- Integrations are an owner/admin screen (SCR-09). Everyone else reaches connector facts only
-- through website_provider_links(): websites they can see, facts only with ownership proof.
CREATE POLICY con_select ON connectors FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));
CREATE POLICY cev_select ON connector_events FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));
CREATE POLICY cas_select ON connector_assets FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));
CREATE POLICY cas_import ON connector_assets FOR UPDATE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (workspace_id = app_workspace_id());

GRANT SELECT (id, workspace_id, kind, label, base_host, username, secret_version, status, status_reason,
              declared_capabilities, verified_capabilities, resource_scope, warnings, sync_interval_seconds,
              sync_no, next_sync_at, consecutive_failures, error_class, last_attempt_at, last_success_at,
              created_by, created_at, revoked_at, revoked_by) ON connectors TO upfixy_app;
GRANT SELECT ON connector_assets, connector_events TO upfixy_app;
GRANT UPDATE (import_state, review_reason, website_id) ON connector_assets TO upfixy_app;

-- ------------------------------------------------------- app functions
CREATE FUNCTION connector_declared(p_kind connector_kind) RETURNS text[]
LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'cloudflare'   THEN ARRAY['zones.read', 'dns.read', 'dnssec.read']
    WHEN 'whm_reseller' THEN ARRAY['accounts.read', 'domains.read', 'ssl.read']
    WHEN 'cpanel'       THEN ARRAY['domains.read', 'ssl.read']
  END
$$;

CREATE FUNCTION create_connector(p_kind connector_kind, p_label text, p_host text, p_user text, p_sealed text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE cid uuid;
BEGIN
  IF app_workspace_id() IS NULL OR app_role() NOT IN ('owner', 'admin') THEN
    RAISE EXCEPTION 'only owners and admins can connect providers' USING ERRCODE = '42501';
  END IF;
  INSERT INTO connectors(workspace_id, kind, label, base_host, username, secret_sealed, declared_capabilities, created_by)
  VALUES (app_workspace_id(), p_kind, p_label, lower(p_host), lower(p_user), p_sealed, connector_declared(p_kind), app_user_id())
  RETURNING id INTO cid;
  INSERT INTO connector_events(workspace_id, connector_id, kind, detail)
  VALUES (app_workspace_id(), cid, 'created', jsonb_build_object('by', app_user_id()));
  RETURN cid;
END $$;

CREATE FUNCTION rotate_connector(p_id uuid, p_sealed text) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v integer;
BEGIN
  IF app_role() NOT IN ('owner', 'admin') THEN RAISE EXCEPTION 'only owners and admins can rotate connectors' USING ERRCODE = '42501'; END IF;
  UPDATE connectors SET secret_sealed = p_sealed, secret_version = secret_version + 1, status = 'verifying', status_reason = NULL,
         verified_capabilities = '{}', next_sync_at = now(), lease_until = NULL, consecutive_failures = 0, error_class = NULL
   WHERE id = p_id AND workspace_id = app_workspace_id() AND revoked_at IS NULL
  RETURNING secret_version INTO v;
  IF v IS NULL THEN RAISE EXCEPTION 'connector not found' USING ERRCODE = 'P0002'; END IF;
  INSERT INTO connector_events(workspace_id, connector_id, kind, detail)
  VALUES (app_workspace_id(), p_id, 'rotated', jsonb_build_object('by', app_user_id(), 'version', v));
  RETURN v;
END $$;

-- Revoke: the token is destroyed, private sync stops at once, reported data becomes stale.
-- Public monitoring of imported websites is unaffected (AC-INT-001.1).
CREATE FUNCTION revoke_connector(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF app_role() NOT IN ('owner', 'admin') THEN RAISE EXCEPTION 'only owners and admins can revoke connectors' USING ERRCODE = '42501'; END IF;
  UPDATE connectors SET revoked_at = now(), revoked_by = app_user_id(), secret_sealed = 'revoked', secret_version = secret_version + 1,
         status = 'revoked', verified_capabilities = '{}', lease_until = NULL
   WHERE id = p_id AND workspace_id = app_workspace_id() AND revoked_at IS NULL;
  IF NOT FOUND THEN RAISE EXCEPTION 'connector not found' USING ERRCODE = 'P0002'; END IF;
  INSERT INTO connector_events(workspace_id, connector_id, kind, detail)
  VALUES (app_workspace_id(), p_id, 'revoked', jsonb_build_object('by', app_user_id()));
END $$;

REVOKE ALL ON FUNCTION create_connector(connector_kind, text, text, text, text), rotate_connector(uuid, text), revoke_connector(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION create_connector(connector_kind, text, text, text, text), rotate_connector(uuid, text), revoke_connector(uuid) TO upfixy_app;

-- ---------------------------------------------------- worker functions
-- Claimed across workspaces; each result call carries the secret version it was claimed with.
CREATE FUNCTION worker_claim_connectors(p_limit integer, p_lease_seconds integer)
RETURNS TABLE(id uuid, workspace_id uuid, kind connector_kind, base_host text, username text, secret_sealed text,
              secret_version integer, status connector_status, resource_scope jsonb, sync_no bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
#variable_conflict use_column
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  RETURN QUERY
  WITH due AS (
    SELECT c.id FROM connectors c
     WHERE c.revoked_at IS NULL AND c.status IN ('verifying', 'active', 'error')
       AND c.next_sync_at <= now() AND (c.lease_until IS NULL OR c.lease_until < now())
     ORDER BY c.next_sync_at LIMIT p_limit
     FOR UPDATE SKIP LOCKED
  )
  UPDATE connectors c SET lease_until = now() + make_interval(secs => p_lease_seconds), last_attempt_at = now()
    FROM due WHERE c.id = due.id
  RETURNING c.id, c.workspace_id, c.kind, c.base_host, c.username, c.secret_sealed, c.secret_version, c.status, c.resource_scope, c.sync_no;
END $$;

-- Is the claim still valid? (not revoked, not rotated since)
CREATE FUNCTION connector_claim_current(p_id uuid, p_version integer) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM connectors WHERE id = p_id AND secret_version = p_version AND revoked_at IS NULL AND status <> 'rejected')
$$;

-- Verification outcome. A failed scope proof wipes the token and keeps nothing it returned.
CREATE FUNCTION worker_connector_verified(p_id uuid, p_version integer, p_ok boolean, p_verified text[], p_scope jsonb,
                                          p_warnings text[], p_reason text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE ws uuid;
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  IF NOT connector_claim_current(p_id, p_version) THEN RETURN false; END IF;
  SELECT workspace_id INTO ws FROM connectors WHERE id = p_id FOR UPDATE;
  IF p_ok THEN
    UPDATE connectors SET status = 'active', status_reason = NULL,
           verified_capabilities = ARRAY(SELECT d.cap FROM unnest(declared_capabilities) WITH ORDINALITY AS d(cap, i)
                                          WHERE d.cap = ANY (p_verified) ORDER BY d.i),
           resource_scope = coalesce(p_scope, '{}'), warnings = coalesce(p_warnings, '{}'),
           last_success_at = now(), consecutive_failures = 0, error_class = NULL, next_sync_at = now(), lease_until = NULL
     WHERE id = p_id;
    INSERT INTO connector_events(workspace_id, connector_id, kind, detail)
    VALUES (ws, p_id, 'verified', jsonb_build_object('capabilities', p_verified, 'warnings', p_warnings));
  ELSE
    UPDATE connectors SET status = 'rejected', status_reason = left(p_reason, 300), secret_sealed = 'rejected',
           secret_version = secret_version + 1, verified_capabilities = '{}', resource_scope = '{}', lease_until = NULL
     WHERE id = p_id;
    UPDATE connector_assets SET gone_at = coalesce(gone_at, now()), facts = '{}' WHERE connector_id = p_id;
    INSERT INTO connector_events(workspace_id, connector_id, kind, detail)
    VALUES (ws, p_id, 'rejected', jsonb_build_object('reason', left(p_reason, 300)));
  END IF;
  RETURN true;
END $$;

-- Sync result: upsert what was seen, mark the rest gone.
CREATE FUNCTION worker_connector_synced(p_id uuid, p_version integer, p_assets jsonb) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE ws uuid; n bigint; a jsonb;
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  IF NOT connector_claim_current(p_id, p_version) THEN RETURN false; END IF;
  UPDATE connectors SET sync_no = sync_no + 1 WHERE id = p_id RETURNING workspace_id, sync_no INTO ws, n;
  FOR a IN SELECT * FROM jsonb_array_elements(p_assets) LOOP
    INSERT INTO connector_assets(workspace_id, connector_id, kind, external_id, name, parent_external_id, facts, last_seen_sync)
    VALUES (ws, p_id, a->>'kind', a->>'external_id', lower(a->>'name'), a->>'parent', coalesce(a->'facts', '{}'), n)
    ON CONFLICT (connector_id, kind, external_id) DO UPDATE
      SET name = EXCLUDED.name, parent_external_id = EXCLUDED.parent_external_id, facts = EXCLUDED.facts,
          last_seen_at = now(), last_seen_sync = n, gone_at = NULL;
  END LOOP;
  UPDATE connector_assets SET gone_at = now() WHERE connector_id = p_id AND last_seen_sync < n AND gone_at IS NULL;
  UPDATE connectors SET status = 'active', status_reason = NULL, last_success_at = now(), consecutive_failures = 0, error_class = NULL,
         next_sync_at = now() + make_interval(secs => sync_interval_seconds), lease_until = NULL
   WHERE id = p_id;
  INSERT INTO connector_events(workspace_id, connector_id, kind, detail)
  VALUES (ws, p_id, 'synced', jsonb_build_object('sync', n, 'assets', jsonb_array_length(p_assets)));
  RETURN true;
END $$;

-- Failure: capped exponential backoff. Only this connector's data goes stale; monitoring is untouched.
CREATE FUNCTION worker_connector_failed(p_id uuid, p_version integer, p_error_class text, p_reason text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE ws uuid; f integer;
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN RAISE EXCEPTION 'workers only' USING ERRCODE = '42501'; END IF;
  IF NOT connector_claim_current(p_id, p_version) THEN RETURN false; END IF;
  UPDATE connectors SET consecutive_failures = consecutive_failures + 1, error_class = left(p_error_class, 40),
         status = CASE WHEN status = 'verifying' THEN 'verifying'::connector_status ELSE 'error'::connector_status END,
         status_reason = left(p_reason, 300),
         next_sync_at = now() + make_interval(secs => least(21600, 60 * power(2, least(consecutive_failures, 10))::integer)),
         lease_until = NULL
   WHERE id = p_id RETURNING workspace_id, consecutive_failures INTO ws, f;
  INSERT INTO connector_events(workspace_id, connector_id, kind, detail)
  VALUES (ws, p_id, 'failed', jsonb_build_object('error_class', p_error_class, 'failures', f));
  RETURN true;
END $$;

REVOKE ALL ON FUNCTION worker_claim_connectors(integer, integer), connector_claim_current(uuid, integer),
  worker_connector_verified(uuid, integer, boolean, text[], jsonb, text[], text), worker_connector_synced(uuid, integer, jsonb),
  worker_connector_failed(uuid, integer, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION worker_claim_connectors(integer, integer), connector_claim_current(uuid, integer),
  worker_connector_verified(uuid, integer, boolean, text[], jsonb, text[], text), worker_connector_synced(uuid, integer, jsonb),
  worker_connector_failed(uuid, integer, text, text) TO upfixy_worker;

-- --------------------------------------------- website asset graph (AST-004)
-- Provider relationships of one website. Anyone who can see the website sees THAT a provider is
-- linked; the provider's facts need current ownership proof or a delegation (D-07), because a
-- connector alone never proves control of a domain. Facts from a revoked, rejected or overdue
-- connector are flagged stale, never shown as current.
CREATE FUNCTION website_provider_links(p_website uuid)
RETURNS TABLE(asset_id uuid, provider connector_kind, asset_kind text, name text, facts jsonb, private_access boolean,
              last_seen_at timestamptz, stale boolean, stale_reason text)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE ws uuid := app_workspace_id(); allowed boolean;
BEGIN
  IF NOT EXISTS (SELECT 1 FROM websites w WHERE w.id = p_website AND w.workspace_id = ws AND app_can_see_client(w.client_id)) THEN
    RETURN;
  END IF;
  allowed := EXISTS (SELECT 1 FROM monitor_targets t WHERE t.website_id = p_website AND site_private_access(ws, t.id, 'read'));
  RETURN QUERY
  SELECT a.id, c.kind, a.kind, a.name,
         CASE WHEN allowed THEN a.facts ELSE NULL END,
         allowed, a.last_seen_at,
         (c.revoked_at IS NOT NULL OR c.status <> 'active' OR a.gone_at IS NOT NULL
          OR c.last_success_at IS NULL OR c.last_success_at < now() - make_interval(secs => 2 * c.sync_interval_seconds)),
         CASE WHEN c.revoked_at IS NOT NULL THEN 'Connection revoked'
              WHEN c.status = 'rejected' THEN 'Connection rejected'
              WHEN a.gone_at IS NOT NULL THEN 'No longer reported by the provider'
              WHEN c.status = 'error' THEN 'Provider not reachable'
              WHEN c.last_success_at IS NULL OR c.last_success_at < now() - make_interval(secs => 2 * c.sync_interval_seconds) THEN 'Not synced recently'
         END
    FROM connector_assets a JOIN connectors c ON c.id = a.connector_id
   WHERE a.website_id = p_website AND a.workspace_id = ws
   ORDER BY c.kind, a.kind, a.name;
END $$;
REVOKE ALL ON FUNCTION website_provider_links(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION website_provider_links(uuid) TO upfixy_app;
