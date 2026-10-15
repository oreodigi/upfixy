-- Sanctum API tokens, keyed to uuid users. Read before tenant context exists,
-- so no RLS; tokens are stored hashed by Sanctum (SHA-256), never in plain text.
CREATE TABLE personal_access_tokens (
  id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tokenable_type varchar(255) NOT NULL,
  tokenable_id   uuid NOT NULL,
  name           text NOT NULL,
  token          varchar(64) NOT NULL UNIQUE,
  abilities      text,
  last_used_at   timestamp,
  expires_at     timestamp,
  created_at     timestamp,
  updated_at     timestamp
);
CREATE INDEX personal_access_tokens_tokenable_idx ON personal_access_tokens(tokenable_type, tokenable_id);
GRANT SELECT, INSERT, UPDATE, DELETE ON personal_access_tokens TO upfixy_app;
