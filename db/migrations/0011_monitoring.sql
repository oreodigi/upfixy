-- M3 public monitoring: scheduling, check runs, evidence, incidents, alerts.
-- FRD refs: MON-001..008, ALR-001, KB-003, §17.1, §17.2, NFR-15, NFR-16, AC-MON-*.
--
-- Work distribution needs no queue table: probe processes claim due targets
-- with a lease (FOR UPDATE SKIP LOCKED). A crashed process's lease expires and
-- another process re-runs the SAME cycle; observations are unique per
-- (target, kind, cycle), so a re-run can never double-count (AC-MON-005.3).

CREATE TYPE run_outcome AS ENUM ('ok', 'target_failure', 'warning', 'probe_error', 'probe_blocked', 'inconclusive');
CREATE TYPE incident_status AS ENUM ('open', 'resolved');

ALTER TABLE monitor_targets
  ADD COLUMN next_check_at        timestamptz NOT NULL DEFAULT now(),
  ADD COLUMN last_cycle_no        bigint NOT NULL DEFAULT 0,   -- last cycle that finished
  ADD COLUMN cycle_claims         integer NOT NULL DEFAULT 0,  -- claims of the current, unfinished cycle
  ADD COLUMN lease_until          timestamptz,
  ADD COLUMN cycle_scheduled_at   timestamptz,
  ADD COLUMN recheck_requested_at timestamptz,
  ADD COLUMN state_reason         text;
CREATE INDEX monitor_targets_due_idx ON monitor_targets(next_check_at) WHERE state IN ('pending_validation', 'active');

-- One row per target per cycle (§17.1): when it was due, when it ran, by which
-- worker build and region, and how the cycle ended.
CREATE TABLE check_runs (
  id                uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id      uuid NOT NULL,
  monitor_target_id uuid NOT NULL,
  cycle_no          bigint NOT NULL,
  scheduled_at      timestamptz NOT NULL,
  started_at        timestamptz NOT NULL,
  finished_at       timestamptz NOT NULL,
  worker            text NOT NULL,
  worker_version    text NOT NULL,
  region            text NOT NULL,
  outcome           run_outcome NOT NULL,
  attempts          integer NOT NULL CHECK (attempts BETWEEN 1 AND 10),
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  UNIQUE (monitor_target_id, cycle_no),
  FOREIGN KEY (workspace_id, monitor_target_id) REFERENCES monitor_targets(workspace_id, id) ON DELETE CASCADE
);
CREATE INDEX check_runs_target_idx ON check_runs(monitor_target_id, cycle_no DESC);

-- Evidence provenance on every reading (NFR-15). observation.id is the
-- evidence ID cited by diagnostics and AI analysis (FRD §25.3).
ALTER TABLE observations
  ADD COLUMN check_run_id uuid,
  ADD COLUMN region text,
  ADD CONSTRAINT observations_run_fk FOREIGN KEY (workspace_id, check_run_id) REFERENCES check_runs(workspace_id, id) ON DELETE CASCADE;

-- One open incident per target, deduplicated by root event: while DNS fails,
-- HTTP and TLS failures for the same target join the DNS incident (MON-008).
CREATE TABLE incidents (
  id                uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id      uuid NOT NULL,
  website_id        uuid NOT NULL,
  monitor_target_id uuid NOT NULL,
  status            incident_status NOT NULL DEFAULT 'open',
  root_kind         check_type NOT NULL,
  result_code       text NOT NULL,
  opened_at         timestamptz NOT NULL,
  first_failure_at  timestamptz NOT NULL,
  last_failure_at   timestamptz NOT NULL,
  resolved_at       timestamptz,
  recovered_cycle   bigint,
  acknowledged_at   timestamptz,
  acknowledged_by   uuid,
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, monitor_target_id) REFERENCES monitor_targets(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, acknowledged_by) REFERENCES memberships(workspace_id, user_id),
  CHECK ((status = 'resolved') = (resolved_at IS NOT NULL))
);
CREATE UNIQUE INDEX incidents_one_open ON incidents(monitor_target_id) WHERE status = 'open';
CREATE INDEX incidents_ws_idx ON incidents(workspace_id, status, opened_at DESC);

CREATE TABLE incident_events (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  workspace_id uuid NOT NULL,
  incident_id  uuid NOT NULL,
  kind         text NOT NULL CHECK (kind IN ('opened', 'root_changed', 'acknowledged', 'resolved')),
  result_code  text,
  actor_id     uuid,
  at           timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (workspace_id, incident_id) REFERENCES incidents(workspace_id, id) ON DELETE CASCADE
);
CREATE INDEX incident_events_idx ON incident_events(incident_id, at);

-- ALR-001 preferences: a workspace-wide row (client_id NULL) per user, plus
-- optional per-client overrides. No row means the defaults (email and in-app on).
CREATE TABLE notification_prefs (
  workspace_id uuid NOT NULL,
  user_id      uuid NOT NULL,
  client_id    uuid,
  email        boolean NOT NULL DEFAULT true,
  in_app       boolean NOT NULL DEFAULT true,
  FOREIGN KEY (workspace_id, user_id) REFERENCES memberships(workspace_id, user_id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, client_id) REFERENCES clients(workspace_id, id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX notification_prefs_key ON notification_prefs(workspace_id, user_id, coalesce(client_id, '00000000-0000-0000-0000-000000000000'::uuid));

-- In-app inbox. One row per (user, incident, kind): duplicates are impossible.
CREATE TABLE notifications (
  id           uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id uuid NOT NULL,
  user_id      uuid NOT NULL,
  incident_id  uuid NOT NULL,
  kind         text NOT NULL CHECK (kind IN ('incident_opened', 'incident_resolved')),
  created_at   timestamptz NOT NULL DEFAULT now(),
  read_at      timestamptz,
  emailed_at   timestamptz,
  PRIMARY KEY (id),
  UNIQUE (user_id, incident_id, kind),
  FOREIGN KEY (workspace_id, user_id) REFERENCES memberships(workspace_id, user_id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, incident_id) REFERENCES incidents(workspace_id, id) ON DELETE CASCADE
);
CREATE INDEX notifications_user_idx ON notifications(user_id, workspace_id, created_at DESC);

-- ----------------------------------------------------------------- RLS
ALTER TABLE check_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE incidents ENABLE ROW LEVEL SECURITY;
ALTER TABLE incident_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE notification_prefs ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;

-- App: read what the caller can see (website visibility follows client assignment).
CREATE POLICY cr_select ON check_runs FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM monitor_targets t WHERE t.id = monitor_target_id));
CREATE POLICY inc_select ON incidents FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY ie_select ON incident_events FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM incidents i WHERE i.id = incident_id));
CREATE POLICY np_own ON notification_prefs FOR ALL TO upfixy_app
  USING (workspace_id = app_workspace_id() AND user_id = app_user_id())
  WITH CHECK (workspace_id = app_workspace_id() AND user_id = app_user_id()
              AND (client_id IS NULL OR app_can_see_client(client_id)));
CREATE POLICY nt_own ON notifications FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND user_id = app_user_id()
         AND EXISTS (SELECT 1 FROM incidents i WHERE i.id = incident_id));
CREATE POLICY nt_read ON notifications FOR UPDATE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND user_id = app_user_id())
  WITH CHECK (workspace_id = app_workspace_id() AND user_id = app_user_id());

GRANT SELECT ON check_runs, incidents, incident_events TO upfixy_app;
GRANT SELECT, INSERT, DELETE ON notification_prefs TO upfixy_app;
GRANT UPDATE (email, in_app) ON notification_prefs TO upfixy_app;
GRANT SELECT ON notifications TO upfixy_app;
GRANT UPDATE (read_at) ON notifications TO upfixy_app;

-- Worker: one workspace at a time, append runs and readings. Incident and
-- notification writes go through the functions below only.
CREATE POLICY w_cr_select ON check_runs FOR SELECT TO upfixy_worker USING (workspace_id = app_workspace_id());
CREATE POLICY w_cr_insert ON check_runs FOR INSERT TO upfixy_worker WITH CHECK (workspace_id = app_workspace_id());
CREATE POLICY w_inc_select ON incidents FOR SELECT TO upfixy_worker USING (workspace_id = app_workspace_id());
GRANT SELECT, INSERT ON check_runs TO upfixy_worker;
GRANT SELECT ON incidents TO upfixy_worker;

CREATE FUNCTION check_runs_immutable() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF TG_OP = 'UPDATE' OR current_user <> 'upfixy_owner' THEN
    RAISE EXCEPTION 'check runs are append-only' USING ERRCODE = '42501';
  END IF;
  RETURN OLD;
END $$;
CREATE TRIGGER check_runs_immutable BEFORE UPDATE OR DELETE ON check_runs
  FOR EACH ROW EXECUTE FUNCTION check_runs_immutable();
CREATE TRIGGER incident_events_immutable BEFORE UPDATE OR DELETE ON incident_events
  FOR EACH ROW EXECUTE FUNCTION check_runs_immutable();

-- ------------------------------------------------------- scheduling
-- Claims up to p_limit due targets across all workspaces, at most p_per_ws
-- per workspace per call so one large tenant cannot starve others (MON-006).
-- A cycle whose lease expired is re-claimed with the SAME cycle number.
CREATE FUNCTION worker_claim_due(p_limit integer, p_per_ws integer, p_lease_seconds integer)
RETURNS TABLE(workspace_id uuid, target_id uuid, website_id uuid, cycle_no bigint, scheduled_at timestamptz,
              claims integer, scheme text, host text, port integer, path text, state monitor_state, interval_seconds integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
#variable_conflict use_column
BEGIN
  IF session_user NOT IN ('upfixy_worker', 'upfixy_owner') THEN
    RAISE EXCEPTION 'workers only' USING ERRCODE = '42501';
  END IF;
  RETURN QUERY
  WITH due AS (
    SELECT t.id FROM monitor_targets t
     WHERE t.state IN ('pending_validation', 'active')
       AND t.next_check_at <= now()
       AND (t.lease_until IS NULL OR t.lease_until < now())
     ORDER BY t.next_check_at
     LIMIT p_limit * 4
     FOR UPDATE SKIP LOCKED
  ), fair AS (
    SELECT t.id, row_number() OVER (PARTITION BY t.workspace_id ORDER BY t.next_check_at) AS rn
      FROM monitor_targets t JOIN due ON due.id = t.id
  ), picked AS (
    SELECT id FROM fair WHERE rn <= p_per_ws ORDER BY rn LIMIT p_limit
  )
  UPDATE monitor_targets t
     SET lease_until = now() + make_interval(secs => p_lease_seconds),
         cycle_claims = CASE WHEN t.lease_until IS NOT NULL THEN t.cycle_claims + 1 ELSE 1 END,
         cycle_scheduled_at = CASE WHEN t.lease_until IS NOT NULL THEN t.cycle_scheduled_at ELSE t.next_check_at END
    FROM picked
   WHERE t.id = picked.id
  RETURNING t.workspace_id, t.id, t.website_id, t.last_cycle_no + 1, t.cycle_scheduled_at, t.cycle_claims,
            t.scheme, t.host, t.port, t.path, t.state, t.interval_seconds;
END $$;
REVOKE ALL ON FUNCTION worker_claim_due(integer, integer, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION worker_claim_due(integer, integer, integer) TO upfixy_worker;

-- Ends a cycle: records progress and schedules the next one with jitter
-- (up to 10% of the interval) so checks spread out (§17.1). Only the current
-- lease holder's cycle can finish; a late duplicate is ignored.
CREATE FUNCTION worker_finish_cycle(p_target uuid, p_cycle bigint, p_state monitor_state, p_reason text)
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE t monitor_targets;
BEGIN
  SELECT * INTO t FROM monitor_targets WHERE id = p_target AND workspace_id = app_workspace_id() FOR UPDATE;
  IF NOT FOUND OR t.last_cycle_no + 1 <> p_cycle THEN
    RETURN false;
  END IF;
  IF p_state NOT IN ('pending_validation', 'active', 'rejected') THEN
    RAISE EXCEPTION 'workers may only validate or reject targets' USING ERRCODE = '42501';
  END IF;
  -- A suspended target stays suspended; a rejected one is never re-activated by a probe.
  UPDATE monitor_targets
     SET last_cycle_no = p_cycle, lease_until = NULL, cycle_claims = 0,
         state = CASE WHEN state IN ('suspended', 'rejected') THEN state ELSE p_state END,
         state_reason = CASE WHEN p_state = 'rejected' THEN p_reason ELSE state_reason END,
         next_check_at = greatest(coalesce(t.cycle_scheduled_at, now()) + make_interval(secs => interval_seconds), now())
                         + make_interval(secs => floor(random() * interval_seconds * 0.1)::int)
   WHERE id = p_target;
  RETURN true;
END $$;
REVOKE ALL ON FUNCTION worker_finish_cycle(uuid, bigint, monitor_state, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION worker_finish_cycle(uuid, bigint, monitor_state, text) TO upfixy_worker;

-- KB-003 "Check now": queue a live re-check of a website's targets, at most
-- once per 2 minutes per target (per-tenant abuse limit, MON-006). Returns the
-- number of targets queued; 0 means they were checked or queued recently.
CREATE FUNCTION request_recheck(p_website uuid) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE c uuid; n integer;
BEGIN
  SELECT w.client_id INTO c FROM websites w WHERE w.id = p_website AND w.workspace_id = app_workspace_id();
  IF NOT FOUND OR NOT app_can_see_client(c) THEN
    RAISE EXCEPTION 'website not found' USING ERRCODE = 'P0002';
  END IF;
  IF app_role() = 'billing' THEN
    RAISE EXCEPTION 'billing contacts cannot run checks' USING ERRCODE = '42501';
  END IF;
  UPDATE monitor_targets
     SET next_check_at = now(), recheck_requested_at = now()
   WHERE website_id = p_website AND workspace_id = app_workspace_id()
     AND state IN ('pending_validation', 'active')
     AND (recheck_requested_at IS NULL OR recheck_requested_at < now() - interval '2 minutes');
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;
REVOKE ALL ON FUNCTION request_recheck(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION request_recheck(uuid) TO upfixy_app;

-- ---------------------------------------------------------- incidents
-- Called after a cycle's readings are stored. The decision (open / resolve /
-- nothing) is made by the worker from the shared debounce rule; this function
-- only applies it, inside the worker's workspace, and keeps one open incident
-- per target. Returns the incident id and what happened.
CREATE FUNCTION worker_apply_incident(p_target uuid, p_action text, p_root check_type, p_code text,
                                      p_at timestamptz, p_first_failure timestamptz, p_cycle bigint)
RETURNS TABLE(incident_id uuid, event text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
#variable_conflict use_column
DECLARE t monitor_targets; i incidents;
BEGIN
  SELECT * INTO t FROM monitor_targets WHERE id = p_target AND workspace_id = app_workspace_id();
  IF NOT FOUND THEN RAISE EXCEPTION 'target not found' USING ERRCODE = 'P0002'; END IF;
  SELECT * INTO i FROM incidents WHERE monitor_target_id = p_target AND status = 'open' FOR UPDATE;

  -- 'open' and 'fail' share one path: a still-failing open incident may change root.
  IF p_action IN ('open', 'fail') THEN
    IF FOUND THEN
      IF i.root_kind <> p_root OR i.result_code <> p_code THEN
        UPDATE incidents SET root_kind = p_root, result_code = p_code, last_failure_at = p_at WHERE id = i.id;
        INSERT INTO incident_events(workspace_id, incident_id, kind, result_code, at) VALUES (t.workspace_id, i.id, 'root_changed', p_code, p_at);
        RETURN QUERY SELECT i.id, 'root_changed'::text;
      ELSE
        UPDATE incidents SET last_failure_at = p_at WHERE id = i.id;
        RETURN QUERY SELECT i.id, 'still_failing'::text;
      END IF;
      RETURN;
    END IF;
    IF p_action = 'fail' THEN RETURN; END IF;  -- not open yet: debounce still counting
    INSERT INTO incidents(workspace_id, website_id, monitor_target_id, root_kind, result_code, opened_at, first_failure_at, last_failure_at)
    VALUES (t.workspace_id, t.website_id, p_target, p_root, p_code, p_at, p_first_failure, p_at)
    RETURNING * INTO i;
    INSERT INTO incident_events(workspace_id, incident_id, kind, result_code, at) VALUES (t.workspace_id, i.id, 'opened', p_code, p_at);
    RETURN QUERY SELECT i.id, 'opened'::text;
  ELSIF p_action = 'resolve' THEN
    IF FOUND THEN
      UPDATE incidents SET status = 'resolved', resolved_at = p_at, recovered_cycle = p_cycle WHERE id = i.id;
      INSERT INTO incident_events(workspace_id, incident_id, kind, at) VALUES (t.workspace_id, i.id, 'resolved', p_at);
      RETURN QUERY SELECT i.id, 'resolved'::text;
    END IF;
  ELSE
    RAISE EXCEPTION 'unknown incident action %', p_action USING ERRCODE = '22023';
  END IF;
END $$;
REVOKE ALL ON FUNCTION worker_apply_incident(uuid, text, check_type, text, timestamptz, timestamptz, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION worker_apply_incident(uuid, text, check_type, text, timestamptz, timestamptz, bigint) TO upfixy_worker;

-- Acknowledge (SCR-02): staff, or the assigned client owner. Idempotent.
CREATE FUNCTION acknowledge_incident(p_incident uuid) RETURNS timestamptz
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE i incidents; c uuid;
BEGIN
  SELECT * INTO i FROM incidents WHERE id = p_incident AND workspace_id = app_workspace_id() FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'incident not found' USING ERRCODE = 'P0002'; END IF;
  SELECT client_id INTO c FROM websites WHERE id = i.website_id;
  IF NOT app_can_see_client(c) THEN RAISE EXCEPTION 'incident not found' USING ERRCODE = 'P0002'; END IF;
  IF NOT (app_role() IN ('owner', 'admin', 'technician') OR (app_role() = 'client_owner' AND app_is_assigned(c))) THEN
    RAISE EXCEPTION 'not allowed to acknowledge incidents' USING ERRCODE = '42501';
  END IF;
  IF i.acknowledged_at IS NULL THEN
    UPDATE incidents SET acknowledged_at = now(), acknowledged_by = app_user_id() WHERE id = p_incident;
    INSERT INTO incident_events(workspace_id, incident_id, kind, actor_id) VALUES (i.workspace_id, i.id, 'acknowledged', app_user_id());
    RETURN now();
  END IF;
  RETURN i.acknowledged_at;
END $$;
REVOKE ALL ON FUNCTION acknowledge_incident(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION acknowledge_incident(uuid) TO upfixy_app;

-- ---------------------------------------------------------- alerts
-- Who hears about an incident: active members who can see the website
-- (owners/admins: everything; technicians and client roles: assigned client
-- only), never billing. Per-client preference overrides the workspace one.
-- Inserts in-app rows (deduplicated) and returns who should get email.
-- One email per person: no recipient ever sees another's address (AC-ALR-001.1).
CREATE FUNCTION worker_notify_incident(p_incident uuid, p_kind text)
RETURNS TABLE(user_id uuid, email citext, notification_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
#variable_conflict use_column
DECLARE i incidents; c uuid;
BEGIN
  SELECT * INTO i FROM incidents WHERE id = p_incident AND workspace_id = app_workspace_id();
  IF NOT FOUND THEN RAISE EXCEPTION 'incident not found' USING ERRCODE = 'P0002'; END IF;
  SELECT w.client_id INTO c FROM websites w WHERE w.id = i.website_id;
  RETURN QUERY
  WITH audience AS (
    SELECT m.user_id, u.email,
           coalesce(pc.email, pw.email, true) AS want_email,
           coalesce(pc.in_app, pw.in_app, true) AS want_app
      FROM memberships m
      JOIN users u ON u.id = m.user_id
      LEFT JOIN notification_prefs pw ON pw.workspace_id = m.workspace_id AND pw.user_id = m.user_id AND pw.client_id IS NULL
      LEFT JOIN notification_prefs pc ON pc.workspace_id = m.workspace_id AND pc.user_id = m.user_id AND pc.client_id = c
     WHERE m.workspace_id = i.workspace_id AND m.suspended_at IS NULL AND u.email_verified_at IS NOT NULL
       AND (m.role IN ('owner', 'admin')
            OR (m.role IN ('technician', 'client_owner', 'client_staff') AND c IS NOT NULL
                AND EXISTS (SELECT 1 FROM client_assignments a
                             WHERE a.workspace_id = m.workspace_id AND a.client_id = c AND a.user_id = m.user_id)))
  ), ins AS (
    INSERT INTO notifications(workspace_id, user_id, incident_id, kind)
    SELECT i.workspace_id, a.user_id, i.id, p_kind FROM audience a WHERE a.want_app OR a.want_email
    ON CONFLICT (user_id, incident_id, kind) DO NOTHING
    RETURNING id, notifications.user_id
  )
  SELECT a.user_id, a.email, ins.id FROM audience a JOIN ins ON ins.user_id = a.user_id WHERE a.want_email;
END $$;
REVOKE ALL ON FUNCTION worker_notify_incident(uuid, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION worker_notify_incident(uuid, text) TO upfixy_worker;

CREATE FUNCTION worker_mark_emailed(p_notification uuid) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  UPDATE notifications SET emailed_at = now() WHERE id = p_notification AND workspace_id = app_workspace_id() AND emailed_at IS NULL
$$;
REVOKE ALL ON FUNCTION worker_mark_emailed(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION worker_mark_emailed(uuid) TO upfixy_worker;

-- A deleted website's probes stop at once (§14.2 step 6): its targets cascade away.
