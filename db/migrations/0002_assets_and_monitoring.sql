-- Websites, exact monitor targets, ownership claims, delegations, observations.
-- FRD refs: AST-001..004, §14.1–14.3, D-07 (approved), MON-007, §15.2.

CREATE TYPE claim_method AS ENUM ('dns_txt', 'html_file');
CREATE TYPE claim_status AS ENUM ('unverified', 'challenge_issued', 'verified', 'expired', 'revoked');
CREATE TYPE delegation_status AS ENUM ('requested', 'granted', 'revoked', 'expired');
CREATE TYPE check_type AS ENUM ('http', 'tls', 'dns');
CREATE TYPE signal_severity AS ENUM ('ok', 'warning', 'critical', 'unknown', 'not_applicable');
CREATE TYPE observation_source AS ENUM ('public', 'connector', 'user');
CREATE TYPE monitor_state AS ENUM ('draft', 'pending_validation', 'active', 'rejected', 'suspended');

CREATE TABLE websites (
  id           uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  client_id    uuid,
  display_name text NOT NULL CHECK (length(display_name) BETWEEN 1 AND 255),
  created_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  -- Composite FK: a website can never point at another workspace's client.
  FOREIGN KEY (workspace_id, client_id) REFERENCES clients(workspace_id, id) ON DELETE SET NULL (client_id)
);
CREATE INDEX websites_client_idx ON websites(workspace_id, client_id);

-- The exact thing probed. Apex and www are different hosts and therefore
-- different targets (FIX-C, AC-AST-001.3); nothing collapses them.
CREATE TABLE monitor_targets (
  id           uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id uuid NOT NULL,
  website_id   uuid NOT NULL,
  scheme       text NOT NULL CHECK (scheme IN ('http', 'https')),
  host         text NOT NULL CHECK (host = lower(host) AND length(host) BETWEEN 1 AND 253),
  port         integer NOT NULL CHECK (port IN (80, 443, 8080, 8443)),
  path         text NOT NULL DEFAULT '/' CHECK (left(path, 1) = '/' AND length(path) <= 2048),
  state        monitor_state NOT NULL DEFAULT 'pending_validation',
  interval_seconds integer NOT NULL DEFAULT 300 CHECK (interval_seconds >= 60),
  created_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  UNIQUE (workspace_id, id),
  UNIQUE (workspace_id, scheme, host, port, path),
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE CASCADE
);

-- D-07: DNS TXT / HTML proof binds workspace + exact host + method + nonce + expiry.
CREATE TABLE ownership_claims (
  id           uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id uuid NOT NULL,
  website_id   uuid NOT NULL,
  host         text NOT NULL CHECK (host = lower(host)),
  method       claim_method NOT NULL,
  nonce        text NOT NULL UNIQUE,  -- reuse across claims is impossible (AC-AST-002.5)
  status       claim_status NOT NULL DEFAULT 'challenge_issued',
  verified_at  timestamptz,
  expires_at   timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE CASCADE,
  CHECK (status <> 'verified' OR (verified_at IS NOT NULL AND expires_at IS NOT NULL))
);

-- D-07: exact-asset, revocable, expiring delegation.
CREATE TABLE delegations (
  id             uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id   uuid NOT NULL,
  website_id     uuid NOT NULL,
  grantor_label  text NOT NULL,
  permissions    text[] NOT NULL CHECK (cardinality(permissions) > 0),
  status         delegation_status NOT NULL DEFAULT 'requested',
  expires_at     timestamptz NOT NULL,
  revoked_at     timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id),
  FOREIGN KEY (workspace_id, website_id) REFERENCES websites(workspace_id, id) ON DELETE CASCADE
);

-- One row per check per scheduled cycle. Retries inside a cycle update the
-- same row, so they count once for debounce (AC-MON-005.3).
CREATE TABLE observations (
  id                uuid NOT NULL DEFAULT gen_random_uuid(),
  workspace_id      uuid NOT NULL,
  monitor_target_id uuid NOT NULL,
  check_kind        check_type NOT NULL,
  cycle_no          bigint NOT NULL,
  severity          signal_severity NOT NULL,
  source            observation_source NOT NULL DEFAULT 'public',
  result_code       text NOT NULL,
  observed_at       timestamptz NOT NULL,
  detail            jsonb NOT NULL DEFAULT '{}'::jsonb,
  PRIMARY KEY (id),
  UNIQUE (monitor_target_id, check_kind, cycle_no),
  FOREIGN KEY (workspace_id, monitor_target_id) REFERENCES monitor_targets(workspace_id, id) ON DELETE CASCADE
);
CREATE INDEX observations_latest_idx ON observations(monitor_target_id, check_kind, observed_at DESC);
