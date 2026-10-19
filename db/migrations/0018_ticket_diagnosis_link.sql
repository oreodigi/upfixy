-- SVC-001 "Ticket with diagnosis attached": a request can name the diagnostic report it was raised from.
-- Same workspace (composite FK), same website (trigger), set only at creation (no UPDATE grant).
ALTER TABLE tickets ADD COLUMN diagnostic_run_id uuid;
ALTER TABLE tickets ADD CONSTRAINT tickets_diagnostic_fk
  FOREIGN KEY (workspace_id, diagnostic_run_id) REFERENCES diagnostic_runs(workspace_id, id);

CREATE FUNCTION tickets_diagnosis_same_site() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.diagnostic_run_id IS NOT NULL AND NOT EXISTS (
       SELECT 1 FROM diagnostic_runs r WHERE r.id = NEW.diagnostic_run_id AND r.website_id = NEW.website_id AND r.workspace_id = NEW.workspace_id) THEN
    RAISE EXCEPTION 'the diagnosis belongs to another website' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER tickets_diagnosis_same_site BEFORE INSERT ON tickets FOR EACH ROW EXECUTE FUNCTION tickets_diagnosis_same_site();
