-- Tickets, approvals and the append-only audit log.
-- FRD refs: SVC-001, SVC-003, §18.2 (FIX-A), §16 payer rule (FIX-B), ACC-007.

CREATE TYPE ticket_state AS ENUM (
  'new', 'investigating', 'waiting_for_customer', 'quote_pending',
  'ready_for_work', 'in_progress', 'verifying', 'resolved', 'closed'
);

CREATE TABLE tickets (
  id           uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id uuid NOT NULL,
  website_id   uuid NOT NULL,
  title        text NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
  state        ticket_state NOT NULL DEFAULT 'new',
  version      integer NOT NULL DEFAULT 1,       -- optimistic lock (AC-SVC-001.1)
  assignee_id  uuid,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, assignee_id) REFERENCES memberships(workspace_id, user_id)
);

-- Every change, including ₹0 provider-covered work, needs one of these (FIX-A).
CREATE TABLE approvals (
  id                 uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id       uuid NOT NULL,
  ticket_id          uuid NOT NULL,
  amount_paise       bigint NOT NULL CHECK (amount_paise >= 0),
  action_fingerprint text NOT NULL,             -- exact resource + operation
  approved_by        uuid NOT NULL,
  approved_at        timestamptz NOT NULL DEFAULT now(),
  expires_at         timestamptz NOT NULL,
  payment_verified   boolean NOT NULL DEFAULT false, -- set only from verified webhook (M6)
  voided_at          timestamptz,
  PRIMARY KEY (id),
  FOREIGN KEY (workspace_id, ticket_id) REFERENCES tickets(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, approved_by) REFERENCES memberships(workspace_id, user_id),
  CHECK (expires_at > approved_at)
);

CREATE TABLE ticket_events (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  workspace_id uuid NOT NULL,
  ticket_id    uuid NOT NULL,
  actor_id     uuid NOT NULL,
  from_state   ticket_state,
  to_state     ticket_state NOT NULL,
  reason       text,
  created_at   timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (workspace_id, ticket_id) REFERENCES tickets(workspace_id, id) ON DELETE CASCADE
);

-- ACC-007 / AC-ACC-007.1: append-only. The app role has INSERT + SELECT only,
-- and this trigger blocks UPDATE/DELETE even for the owner role.
CREATE TABLE audit_events (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  workspace_id uuid,
  actor_id     uuid,
  action       text NOT NULL,
  target_type  text,
  target_id    text,
  outcome      text NOT NULL CHECK (outcome IN ('allowed', 'denied', 'error')),
  ip           inet,
  trace_id     text,
  detail       jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_events_ws_idx ON audit_events(workspace_id, created_at DESC);

CREATE FUNCTION audit_events_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'audit_events is append-only';
END $$;

CREATE TRIGGER audit_events_no_update BEFORE UPDATE OR DELETE ON audit_events
  FOR EACH ROW EXECUTE FUNCTION audit_events_immutable();
CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events
  FOR EACH STATEMENT EXECUTE FUNCTION audit_events_immutable();
