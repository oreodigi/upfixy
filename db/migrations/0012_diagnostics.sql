-- M5 diagnostics: evidence snapshots, deterministic findings, AI analyses.
-- FRD refs: DX-001..006, §18.1, §25.3, AI-SEC-01..10, AC-DX-*.
--
-- Everything here is immutable once written: a diagnosis is a record of what
-- was known at a point in time. A new diagnosis is a new run.

CREATE TYPE diagnostic_status AS ENUM ('requested', 'eligibility_check', 'collecting', 'evaluating', 'completed', 'partial', 'failed', 'cancelled');
CREATE TYPE finding_confidence AS ENUM ('confirmed', 'likely', 'possible', 'unknown');
CREATE TYPE responsibility AS ENUM ('provider_covered', 'customer_side', 'needs_quote', 'undetermined');
CREATE TYPE ai_status AS ENUM ('succeeded', 'rejected', 'unavailable', 'skipped', 'budget_exceeded');

CREATE TABLE diagnostic_runs (
  id               uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id     uuid NOT NULL,
  website_id       uuid NOT NULL,
  requested_by     uuid NOT NULL,
  status           diagnostic_status NOT NULL,
  rule_version     text NOT NULL,
  snapshot         jsonb NOT NULL,            -- the exact evidence the run used (ids, codes, times)
  snapshot_hash    text NOT NULL,             -- sha256 of the snapshot: same evidence => same run (AC-DX-006.2)
  private_evidence boolean NOT NULL,          -- was private (ownership-gated) evidence in scope?
  created_at       timestamptz NOT NULL DEFAULT now(),
  completed_at     timestamptz,
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, requested_by) REFERENCES memberships(workspace_id, user_id)
);
CREATE INDEX diagnostic_runs_site_idx ON diagnostic_runs(website_id, created_at DESC);

CREATE TABLE findings (
  id                         uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id               uuid NOT NULL,
  run_id                     uuid NOT NULL,
  source                     text NOT NULL CHECK (source IN ('rules', 'ai')),
  finding_key                text NOT NULL,
  observed_fact              text NOT NULL,
  evidence_ids               uuid[] NOT NULL CHECK (cardinality(evidence_ids) > 0),
  hypothesis                 text,
  supporting_evidence_ids    uuid[] NOT NULL DEFAULT '{}',
  contradicting_evidence_ids uuid[] NOT NULL DEFAULT '{}',
  confidence                 finding_confidence NOT NULL,
  responsibility             responsibility NOT NULL DEFAULT 'undetermined',
  recommended_actions        jsonb NOT NULL DEFAULT '[]',
  human_review_required      boolean NOT NULL DEFAULT false,
  created_at                 timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  FOREIGN KEY (workspace_id, run_id) REFERENCES diagnostic_runs(workspace_id, id) ON DELETE CASCADE
);
CREATE INDEX findings_run_idx ON findings(run_id);

-- One row per AI attempt for a run. The validated output only; the exact
-- minimised payload that left the system is kept for audit (AI-SEC-10).
CREATE TABLE ai_analyses (
  id                uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id      uuid NOT NULL,
  run_id            uuid NOT NULL,
  status            ai_status NOT NULL,
  provider          text NOT NULL,
  model             text,
  prompt_version    text NOT NULL,
  schema_version    text NOT NULL,
  payload           jsonb,             -- what was sent (pseudonymised); NULL when nothing was sent
  payload_hash      text,
  output            jsonb,             -- validated output, mapped back to real labels; NULL unless succeeded
  rejection_reasons jsonb NOT NULL DEFAULT '[]',
  input_tokens      integer,
  output_tokens     integer,
  est_cost_micros   bigint NOT NULL DEFAULT 0,  -- estimated cost in millionths of a US dollar
  latency_ms        integer,
  created_at        timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  FOREIGN KEY (workspace_id, run_id) REFERENCES diagnostic_runs(workspace_id, id) ON DELETE CASCADE
);
CREATE INDEX ai_analyses_ws_day_idx ON ai_analyses(workspace_id, created_at);

-- -------------------------------------------------------------------- RLS
ALTER TABLE diagnostic_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE findings ENABLE ROW LEVEL SECURITY;
ALTER TABLE ai_analyses ENABLE ROW LEVEL SECURITY;

-- Reading follows website visibility. Writing: roles that may run Analyze (§6):
-- owner/admin, assigned technician, assigned client owner. Never billing or client staff.
CREATE FUNCTION app_can_diagnose(p_website uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT EXISTS (
    SELECT 1 FROM websites w
     WHERE w.id = p_website AND w.workspace_id = app_workspace_id()
       AND (app_role() IN ('owner', 'admin')
            OR (app_role() IN ('technician', 'client_owner') AND w.client_id IS NOT NULL AND app_is_assigned(w.client_id))))
$$;
REVOKE ALL ON FUNCTION app_can_diagnose(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_can_diagnose(uuid) TO upfixy_app;

CREATE POLICY dr_select ON diagnostic_runs FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY dr_insert ON diagnostic_runs FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id() AND requested_by = app_user_id() AND app_can_diagnose(website_id));
CREATE POLICY dr_complete ON diagnostic_runs FOR UPDATE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_can_diagnose(website_id))
  WITH CHECK (workspace_id = app_workspace_id());
CREATE POLICY f_select ON findings FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM diagnostic_runs r WHERE r.id = run_id));
CREATE POLICY f_insert ON findings FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id()
              AND EXISTS (SELECT 1 FROM diagnostic_runs r WHERE r.id = run_id AND app_can_diagnose(r.website_id)));
CREATE POLICY ai_select ON ai_analyses FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id() AND EXISTS (SELECT 1 FROM diagnostic_runs r WHERE r.id = run_id));
CREATE POLICY ai_insert ON ai_analyses FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id()
              AND EXISTS (SELECT 1 FROM diagnostic_runs r WHERE r.id = run_id AND app_can_diagnose(r.website_id)));

GRANT SELECT, INSERT ON diagnostic_runs, findings, ai_analyses TO upfixy_app;
-- A run only moves forward: status and completion time, nothing else.
GRANT UPDATE (status, completed_at) ON diagnostic_runs TO upfixy_app;

CREATE FUNCTION diagnostic_runs_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF OLD.status IN ('completed', 'partial', 'failed', 'cancelled') THEN
    RAISE EXCEPTION 'a finished diagnosis is immutable' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER diagnostic_runs_guard BEFORE UPDATE ON diagnostic_runs
  FOR EACH ROW EXECUTE FUNCTION diagnostic_runs_guard();

-- Findings and AI analyses are append-only for every role (evidence integrity, AI-SEC-10).
-- Only cascades from a deleted website (run as the schema owner) remove them.
CREATE FUNCTION append_only() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF TG_OP = 'UPDATE' OR current_user <> 'upfixy_owner' THEN
    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = '42501';
  END IF;
  RETURN OLD;
END $$;
CREATE TRIGGER findings_immutable BEFORE UPDATE OR DELETE ON findings
  FOR EACH ROW EXECUTE FUNCTION append_only();
CREATE TRIGGER ai_analyses_immutable BEFORE UPDATE OR DELETE ON ai_analyses
  FOR EACH ROW EXECUTE FUNCTION append_only();
