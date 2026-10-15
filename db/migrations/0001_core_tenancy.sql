-- Upfixy M1: identity, workspaces, memberships, clients, assignments.
-- FRD v0.2.1 refs: ACC-003..006, §2 workspace rules, §14.3 data model, NFR-06.
-- Runs as the schema owner (upfixy_owner). The application connects as
-- upfixy_app, which is NOT the table owner, so row-level security applies.

CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TYPE workspace_kind AS ENUM ('solo', 'agency');
CREATE TYPE member_role AS ENUM (
  'owner', 'admin', 'technician', 'billing', 'client_owner', 'client_staff'
);

CREATE TABLE users (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email             citext NOT NULL UNIQUE,
  password_hash     text NOT NULL,
  email_verified_at timestamptz,
  mfa_enabled_at    timestamptz,
  created_at        timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE workspaces (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name          text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
  kind          workspace_kind NOT NULL,
  provider_flag boolean NOT NULL DEFAULT false,
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE memberships (
  workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  user_id      uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role         member_role NOT NULL,
  suspended_at timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (workspace_id, user_id)
);
CREATE INDEX memberships_user_idx ON memberships(user_id);

CREATE TABLE clients (
  id           uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  name         text NOT NULL CHECK (length(name) BETWEEN 1 AND 160),
  -- FIX-B: payment authority is delegated to the agency only by contract.
  agency_may_pay boolean NOT NULL DEFAULT false,
  -- D-09 (open): client self-service is off by default.
  client_self_service boolean NOT NULL DEFAULT false,
  created_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id)
);

-- Who may act on which client. Staff (technicians) and client-side users
-- see only clients they are assigned to (§6 "C" scope).
CREATE TABLE client_assignments (
  workspace_id uuid NOT NULL,
  client_id    uuid NOT NULL,
  user_id      uuid NOT NULL,
  created_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (workspace_id, client_id, user_id),
  FOREIGN KEY (workspace_id, client_id) REFERENCES clients(workspace_id, id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id, user_id) REFERENCES memberships(workspace_id, user_id) ON DELETE CASCADE
);
