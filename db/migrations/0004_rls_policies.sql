-- Row-level security: defence in depth behind the API's own policy checks.
-- FRD refs: NFR-06, NFR-12, §6 matrix, §16, AC-ACC-003.1, AC-ACC-005.1,
-- AC-ACC-006.2, AC-BRD-003.2, AC-SVC-003.3, AC-SVC-003.4.
--
-- Request context is set per transaction by the API (and by workers):
--   SELECT set_config('app.user_id', '<uuid>', true),
--          set_config('app.workspace_id', '<uuid>', true);
-- With no context, every tenant query returns nothing.

-- Worker role for probes; sees one workspace at a time, no user identity.
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'upfixy_worker') THEN
    CREATE ROLE upfixy_worker LOGIN PASSWORD 'devonly';
  END IF;
END $$;

-- ---------------------------------------------------------------- context
CREATE FUNCTION app_user_id() RETURNS uuid LANGUAGE sql STABLE AS
$$ SELECT nullif(current_setting('app.user_id', true), '')::uuid $$;

CREATE FUNCTION app_workspace_id() RETURNS uuid LANGUAGE sql STABLE AS
$$ SELECT nullif(current_setting('app.workspace_id', true), '')::uuid $$;

-- SECURITY DEFINER helpers read memberships/assignments as the owner, so
-- policies can consult them without recursive RLS. search_path is pinned.
CREATE FUNCTION app_role() RETURNS member_role
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT m.role FROM memberships m
   WHERE m.workspace_id = app_workspace_id()
     AND m.user_id = app_user_id()
     AND m.suspended_at IS NULL
$$;

CREATE FUNCTION app_is_member(ws uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM memberships m
                  WHERE m.workspace_id = ws AND m.user_id = app_user_id()
                    AND m.suspended_at IS NULL)
$$;

CREATE FUNCTION app_is_assigned(client uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM client_assignments a
                  WHERE a.workspace_id = app_workspace_id()
                    AND a.client_id = client AND a.user_id = app_user_id())
$$;

-- §6 "View boards and website detail": owner/admin = all; technician and
-- client roles = assigned clients only; billing = none. Sites with no client
-- (solo workspaces, agency's own sites) are owner/admin only.
CREATE FUNCTION app_can_see_client(client uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT CASE
    WHEN app_role() IN ('owner', 'admin') THEN true
    WHEN client IS NULL THEN false
    WHEN app_role() IN ('technician', 'client_owner', 'client_staff') THEN app_is_assigned(client)
    ELSE false
  END
$$;

-- FIX-B payer rule. Client work: the assigned client owner pays, or the
-- agency owner/billing role only where the client contract delegates it.
-- Non-client sites: owner or billing role of the workspace.
CREATE FUNCTION app_can_approve(client uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT CASE
    WHEN client IS NULL THEN app_role() IN ('owner', 'billing')
    WHEN app_role() = 'client_owner' THEN app_is_assigned(client)
    WHEN app_role() IN ('owner', 'billing') THEN
      EXISTS (SELECT 1 FROM clients c
               WHERE c.id = client AND c.workspace_id = app_workspace_id()
                 AND c.agency_may_pay)
    ELSE false
  END
$$;

-- Workspace creation is the one bootstrap path: creates the workspace and
-- makes the caller its owner atomically. No other way to self-join.
CREATE FUNCTION create_workspace(p_name text, p_kind workspace_kind) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE ws uuid;
BEGIN
  IF app_user_id() IS NULL THEN RAISE EXCEPTION 'no authenticated user'; END IF;
  INSERT INTO workspaces(name, kind) VALUES (p_name, p_kind) RETURNING id INTO ws;
  INSERT INTO memberships(workspace_id, user_id, role) VALUES (ws, app_user_id(), 'owner');
  RETURN ws;
END $$;

-- ------------------------------------------------------- ticket transitions
-- §18.2 (with FIX-A). Enforced in the database so no code path can skip it.
CREATE FUNCTION ticket_transition_allowed(f ticket_state, t ticket_state) RETURNS boolean
LANGUAGE sql IMMUTABLE AS $$
  SELECT (f, t) IN (
    ('new', 'investigating'), ('new', 'waiting_for_customer'),
    ('investigating', 'waiting_for_customer'), ('investigating', 'quote_pending'), ('investigating', 'closed'),
    ('waiting_for_customer', 'investigating'), ('waiting_for_customer', 'quote_pending'),
    ('quote_pending', 'ready_for_work'), ('quote_pending', 'waiting_for_customer'), ('quote_pending', 'closed'),
    ('ready_for_work', 'in_progress'),
    ('in_progress', 'verifying'), ('in_progress', 'investigating'),
    ('verifying', 'resolved'), ('verifying', 'investigating'),
    ('resolved', 'closed'), ('resolved', 'investigating'),
    ('closed', 'investigating')
  )
$$;

CREATE FUNCTION tickets_guard() RETURNS trigger
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
      RAISE EXCEPTION 'transition % -> % not allowed', OLD.state, NEW.state
        USING ERRCODE = '23514';
    END IF;
    -- FIX-A / AC-SVC-003.3: leaving Quote pending for work needs a live
    -- approval; paid work also needs a verified payment.
    IF OLD.state = 'quote_pending' AND NEW.state = 'ready_for_work' AND NOT EXISTS (
      SELECT 1 FROM approvals a
       WHERE a.ticket_id = NEW.id AND a.voided_at IS NULL AND a.expires_at > now()
         AND (a.amount_paise = 0 OR a.payment_verified)
    ) THEN
      RAISE EXCEPTION 'no valid approval for this ticket' USING ERRCODE = '23514';
    END IF;
  END IF;
  NEW.updated_at := now();
  RETURN NEW;
END $$;

CREATE TRIGGER tickets_guard BEFORE UPDATE ON tickets
  FOR EACH ROW EXECUTE FUNCTION tickets_guard();

-- New tickets always start at 'new'.
CREATE FUNCTION tickets_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  NEW.state := 'new'; NEW.version := 1;
  RETURN NEW;
END $$;
CREATE TRIGGER tickets_insert_guard BEFORE INSERT ON tickets
  FOR EACH ROW EXECUTE FUNCTION tickets_insert_guard();

-- Approval payer check (AC-SVC-003.4) runs at insert time against the
-- ticket's website client.
CREATE FUNCTION approvals_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE c uuid;
BEGIN
  SELECT w.client_id INTO c FROM tickets t JOIN websites w ON w.id = t.website_id
   WHERE t.id = NEW.ticket_id AND t.workspace_id = NEW.workspace_id;
  IF NEW.approved_by <> app_user_id() OR NOT app_can_approve(c) THEN
    RAISE EXCEPTION 'caller is not the authorised payer' USING ERRCODE = '42501';
  END IF;
  NEW.payment_verified := false;  -- only the verified webhook path may set this (M6)
  RETURN NEW;
END $$;
CREATE TRIGGER approvals_guard BEFORE INSERT ON approvals
  FOR EACH ROW EXECUTE FUNCTION approvals_guard();

-- --------------------------------------------------------------- enable RLS
ALTER TABLE workspaces         ENABLE ROW LEVEL SECURITY;
ALTER TABLE memberships        ENABLE ROW LEVEL SECURITY;
ALTER TABLE clients            ENABLE ROW LEVEL SECURITY;
ALTER TABLE client_assignments ENABLE ROW LEVEL SECURITY;
ALTER TABLE websites           ENABLE ROW LEVEL SECURITY;
ALTER TABLE monitor_targets    ENABLE ROW LEVEL SECURITY;
ALTER TABLE ownership_claims   ENABLE ROW LEVEL SECURITY;
ALTER TABLE delegations        ENABLE ROW LEVEL SECURITY;
ALTER TABLE observations       ENABLE ROW LEVEL SECURITY;
ALTER TABLE tickets            ENABLE ROW LEVEL SECURITY;
ALTER TABLE approvals          ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_events      ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_events       ENABLE ROW LEVEL SECURITY;

-- workspaces: members see their own workspaces; created only via create_workspace().
CREATE POLICY ws_select ON workspaces FOR SELECT TO upfixy_app USING (app_is_member(id));
CREATE POLICY ws_update ON workspaces FOR UPDATE TO upfixy_app
  USING (id = app_workspace_id() AND app_role() IN ('owner', 'admin'));

-- memberships: see your own, or all in the current workspace if owner/admin.
CREATE POLICY mem_select ON memberships FOR SELECT TO upfixy_app
  USING (user_id = app_user_id()
         OR (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin')));
CREATE POLICY mem_write ON memberships FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin')
              AND (role <> 'owner' OR app_role() = 'owner'));

-- clients
CREATE POLICY cli_select ON clients FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_can_see_client(id));
CREATE POLICY cli_write ON clients FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));

-- client_assignments
CREATE POLICY ca_select ON client_assignments FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND (app_role() IN ('owner', 'admin') OR user_id = app_user_id()));
CREATE POLICY ca_write ON client_assignments FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));

-- websites: §6 "Add website" = owner/admin, or client owner when the agency
-- enabled self-service for that client (D-09).
CREATE POLICY web_select ON websites FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_can_see_client(client_id));
CREATE POLICY web_insert ON websites FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id() AND (
    app_role() IN ('owner', 'admin')
    OR (app_role() = 'client_owner' AND app_is_assigned(client_id)
        AND EXISTS (SELECT 1 FROM clients c WHERE c.id = client_id AND c.client_self_service))));
CREATE POLICY web_modify ON websites FOR UPDATE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));
CREATE POLICY web_delete ON websites FOR DELETE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));

-- Children of websites inherit visibility through the (RLS-filtered) websites table.
CREATE POLICY mt_select ON monitor_targets FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY mt_write ON monitor_targets FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'client_owner')
         AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id))
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'client_owner')
              AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));

CREATE POLICY oc_select ON ownership_claims FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY oc_write ON ownership_claims FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'client_owner')
         AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id))
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'client_owner')
              AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));

CREATE POLICY del_select ON delegations FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY del_write ON delegations FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));

CREATE POLICY obs_select ON observations FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND EXISTS (SELECT 1 FROM monitor_targets t WHERE t.id = monitor_target_id));

-- tickets: §6 "Create ticket" A/A/C/—/C/C/A; "Move workflow" owner/admin/technician(C).
CREATE POLICY tk_select ON tickets FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY tk_insert ON tickets FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() <> 'billing'
              AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY tk_update ON tickets FOR UPDATE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin', 'technician')
         AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id))
  WITH CHECK (workspace_id = app_workspace_id());

CREATE POLICY ap_select ON approvals FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id));
CREATE POLICY ap_insert ON approvals FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id()
              AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id));

CREATE POLICY te_select ON ticket_events FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id));
CREATE POLICY te_insert ON ticket_events FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id() AND actor_id = app_user_id()
              AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = ticket_id));

-- audit: anyone may append for their own context; owner/admin may read.
CREATE POLICY au_insert ON audit_events FOR INSERT TO upfixy_app
  WITH CHECK ((workspace_id IS NULL OR workspace_id = app_workspace_id())
              AND (actor_id IS NULL OR actor_id = app_user_id()));
CREATE POLICY au_select ON audit_events FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'));

-- Worker: one workspace at a time, read targets, write observations.
CREATE POLICY w_mt ON monitor_targets FOR SELECT TO upfixy_worker
  USING (workspace_id = app_workspace_id());
CREATE POLICY w_obs ON observations FOR ALL TO upfixy_worker
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());

-- ------------------------------------------------------------------ grants
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO upfixy_app, upfixy_worker;
GRANT SELECT, INSERT, UPDATE ON users TO upfixy_app;
GRANT SELECT, UPDATE ON workspaces TO upfixy_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON memberships, clients, client_assignments, websites,
  monitor_targets, ownership_claims, delegations, tickets TO upfixy_app;
GRANT SELECT ON observations TO upfixy_app;
GRANT SELECT, INSERT ON approvals, ticket_events, audit_events TO upfixy_app;
GRANT SELECT ON monitor_targets TO upfixy_worker;
GRANT SELECT, INSERT, UPDATE ON observations TO upfixy_worker;
REVOKE ALL ON FUNCTION create_workspace(text, workspace_kind) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION create_workspace(text, workspace_kind) TO upfixy_app;
