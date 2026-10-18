-- M2: saved views and ticket assignment rules.
-- FRD refs: BRD-004 (saved views), BRD-003 / SVC-001 (assign technician),
-- §6 (technicians act only on assigned clients), §16 tickets.assign.

-- ------------------------------------------------------------ saved views
-- Personal to one user in one workspace; never shared, never visible to others.
CREATE TABLE saved_views (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  workspace_id uuid NOT NULL,
  user_id      uuid NOT NULL,
  board        text NOT NULL CHECK (board IN ('clients', 'websites', 'work')),
  name         text NOT NULL CHECK (length(name) BETWEEN 1 AND 60),
  filters      jsonb NOT NULL CHECK (jsonb_typeof(filters) = 'object' AND length(filters::text) <= 2000),
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (workspace_id, user_id, board, name),
  FOREIGN KEY (workspace_id, user_id) REFERENCES memberships(workspace_id, user_id) ON DELETE CASCADE
);
ALTER TABLE saved_views ENABLE ROW LEVEL SECURITY;
CREATE POLICY sv_own ON saved_views FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND user_id = app_user_id() AND app_role() IS NOT NULL)
  WITH CHECK (workspace_id = app_workspace_id() AND user_id = app_user_id() AND app_role() IS NOT NULL);
GRANT SELECT, INSERT, DELETE ON saved_views TO upfixy_app;
GRANT UPDATE (name, filters) ON saved_views TO upfixy_app;

-- At most 20 per user per board: enough for real use, bounded for abuse.
CREATE FUNCTION saved_views_limit() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF (SELECT count(*) FROM saved_views WHERE workspace_id = NEW.workspace_id AND user_id = NEW.user_id AND board = NEW.board) >= 20 THEN
    RAISE EXCEPTION 'saved view limit reached' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER saved_views_limit BEFORE INSERT ON saved_views
  FOR EACH ROW EXECUTE FUNCTION saved_views_limit();

-- ------------------------------------------------------ ticket assignment
-- An assignee must be active staff (owner, admin, technician). A technician
-- must also be assigned to the website's client; technicians never work on
-- sites with no client (those are the workspace's own sites). Technicians
-- may take or drop a ticket for themselves only; owners/admins assign anyone.
CREATE FUNCTION tickets_assignee_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r member_role; c uuid; caller member_role;
BEGIN
  IF NEW.assignee_id IS NOT DISTINCT FROM OLD.assignee_id THEN RETURN NEW; END IF;

  caller := app_role();   -- NULL for system writes (owner fixtures, suspension cleanup)
  IF caller = 'technician'
     AND (NEW.assignee_id IS DISTINCT FROM app_user_id() AND NEW.assignee_id IS NOT NULL
          OR OLD.assignee_id IS NOT NULL AND OLD.assignee_id <> app_user_id()) THEN
    RAISE EXCEPTION 'technicians can only take or drop their own requests' USING ERRCODE = '42501';
  END IF;

  IF NEW.assignee_id IS NOT NULL THEN
    SELECT m.role INTO r FROM memberships m
     WHERE m.workspace_id = NEW.workspace_id AND m.user_id = NEW.assignee_id AND m.suspended_at IS NULL;
    IF r IS NULL OR r NOT IN ('owner', 'admin', 'technician') THEN
      RAISE EXCEPTION 'requests can only be assigned to active staff' USING ERRCODE = '23514';
    END IF;
    IF r = 'technician' THEN
      SELECT w.client_id INTO c FROM websites w WHERE w.id = NEW.website_id;
      IF c IS NULL OR NOT EXISTS (SELECT 1 FROM client_assignments a
                                  WHERE a.workspace_id = NEW.workspace_id AND a.client_id = c AND a.user_id = NEW.assignee_id) THEN
        RAISE EXCEPTION 'this technician is not assigned to the client' USING ERRCODE = '23514';
      END IF;
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER tickets_assignee_guard BEFORE UPDATE OF assignee_id ON tickets
  FOR EACH ROW EXECUTE FUNCTION tickets_assignee_guard();

-- Staff list for assignment pickers: id + email of active staff assigned to a
-- client (or all owners/admins). Emails of other members are otherwise only
-- visible to owners/admins (users RLS); technicians see names they work with.
CREATE FUNCTION assignable_staff(p_website uuid)
RETURNS TABLE(user_id uuid, email citext, role member_role)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT m.user_id, u.email, m.role
    FROM websites w
    JOIN memberships m ON m.workspace_id = w.workspace_id AND m.suspended_at IS NULL
    JOIN users u ON u.id = m.user_id
   WHERE w.id = p_website AND w.workspace_id = app_workspace_id()
     AND app_role() IN ('owner', 'admin', 'technician')
     AND app_can_see_client(w.client_id)
     AND (m.role IN ('owner', 'admin')
          OR (m.role = 'technician' AND w.client_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM client_assignments a
                 WHERE a.workspace_id = w.workspace_id AND a.client_id = w.client_id AND a.user_id = m.user_id)))
   ORDER BY m.role, u.email
$$;
GRANT EXECUTE ON FUNCTION assignable_staff(uuid) TO upfixy_app;

-- History reads per target, newest first (website detail timeline).
CREATE INDEX observations_target_time_idx ON observations(monitor_target_id, observed_at DESC);
