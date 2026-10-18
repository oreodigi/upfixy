-- M1 security audit fixes (18 Oct 2026). See docs/SECURITY.md, findings S-01 to S-05.

-- S-01: the app could rewrite a workspace's id, kind, created_at and
-- provider_flag. provider_flag will decide provider-covered (₹0) eligibility,
-- so it is a platform-verified attribute, never self-service. Rename only.
REVOKE UPDATE ON workspaces FROM upfixy_app;
GRANT UPDATE (name) ON workspaces TO upfixy_app;
DROP POLICY ws_update ON workspaces;
CREATE POLICY ws_update ON workspaces FOR UPDATE TO upfixy_app
  USING (id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (id = app_workspace_id() AND app_role() IN ('owner', 'admin'));

-- S-02: assignments are created and deleted, never edited in place. An UPDATE
-- could repoint an assignment to another user or client without the
-- membership check the API performs on create.
REVOKE UPDATE ON client_assignments FROM upfixy_app;

-- S-03: evidence is append-only. Diagnostics, AI analysis and repair
-- verification all cite observation IDs; a writer that can edit or delete an
-- observation could manufacture or erase evidence (FRD §25.5 AI-SEC-10).
REVOKE UPDATE, DELETE ON observations FROM upfixy_worker;
DROP POLICY w_obs ON observations;
CREATE POLICY w_obs_select ON observations FOR SELECT TO upfixy_worker
  USING (workspace_id = app_workspace_id());
CREATE POLICY w_obs_insert ON observations FOR INSERT TO upfixy_worker
  WITH CHECK (workspace_id = app_workspace_id());

CREATE FUNCTION observations_immutable() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  -- Retention deletes run as the schema owner from a maintenance job, never
  -- from the app or the worker.
  IF current_user <> 'upfixy_owner' THEN
    RAISE EXCEPTION 'observations are append-only' USING ERRCODE = '42501';
  END IF;
  IF TG_OP = 'UPDATE' THEN
    RAISE EXCEPTION 'observations are append-only' USING ERRCODE = '42501';
  END IF;
  RETURN OLD;
END $$;
CREATE TRIGGER observations_immutable BEFORE UPDATE OR DELETE ON observations
  FOR EACH ROW EXECUTE FUNCTION observations_immutable();

-- S-05: site_private_access() is SECURITY DEFINER and took any workspace id.
-- Called directly by the app or worker it could answer "does workspace X
-- have proof for target Y". Only the caller's own workspace is answered now;
-- the schema owner (maintenance, tests) keeps full access.
CREATE OR REPLACE FUNCTION site_private_access(p_ws uuid, p_target uuid, p_permission text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT (session_user = 'upfixy_owner' OR p_ws IS NOT DISTINCT FROM app_workspace_id())
     AND (EXISTS (
           SELECT 1 FROM monitor_targets t
             JOIN ownership_claims c ON c.workspace_id = t.workspace_id AND c.website_id = t.website_id AND c.host = t.host
            WHERE t.id = p_target AND t.workspace_id = p_ws
              AND c.status = 'verified' AND c.expires_at > now() AND c.revoked_at IS NULL)
      OR EXISTS (
           SELECT 1 FROM monitor_targets t
             JOIN delegations d ON d.workspace_id = t.workspace_id AND d.website_id = t.website_id
            WHERE t.id = p_target AND t.workspace_id = p_ws
              AND d.status = 'granted' AND d.expires_at > now() AND d.revoked_at IS NULL
              AND p_permission = ANY (d.permissions)))
$$;

-- Hygiene: tickets have no DELETE policy (so a DELETE already matched no
-- rows), but the grant should not exist either. Tickets are closed, not deleted.
REVOKE DELETE ON tickets FROM upfixy_app;
