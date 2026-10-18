-- Run ONCE per environment as a PostgreSQL superuser / DBA, before migrations.
-- Usage: psql -v app_pw=... -v worker_pw=... -v executor_pw=... -v owner_pw=... -v db=upfixy_dev -f db/bootstrap/roles.sql
-- Never commit real passwords; pass them with -v from a secret store.
--
-- Roles:
--   upfixy_owner  owns the schema; used ONLY to run migrations. Never by the app.
--   upfixy_app    the API. Not an owner, so row-level security always applies.
--   upfixy_worker background workers (probes, verification). Narrower than the app.
--   upfixy_executor the repair executor (M7). The ONLY role that can read sealed DNS
--                 credentials' metadata for execution and record repair outcomes.

\set ON_ERROR_STOP on

SELECT format('CREATE ROLE %I', r) FROM unnest(ARRAY['upfixy_owner','upfixy_app','upfixy_worker','upfixy_executor']) r
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) \gexec

ALTER ROLE upfixy_owner  LOGIN PASSWORD :'owner_pw'  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION;
ALTER ROLE upfixy_app    LOGIN PASSWORD :'app_pw'    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION NOINHERIT CONNECTION LIMIT 60;
ALTER ROLE upfixy_worker LOGIN PASSWORD :'worker_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION NOINHERIT CONNECTION LIMIT 30;
ALTER ROLE upfixy_executor LOGIN PASSWORD :'executor_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION NOINHERIT CONNECTION LIMIT 5;

-- Bound runaway queries and abandoned transactions (they hold locks and RLS context).
ALTER ROLE upfixy_app    SET statement_timeout = '15s';
ALTER ROLE upfixy_app    SET idle_in_transaction_session_timeout = '30s';
ALTER ROLE upfixy_app    SET lock_timeout = '5s';
ALTER ROLE upfixy_worker SET statement_timeout = '30s';
ALTER ROLE upfixy_worker SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE upfixy_executor SET statement_timeout = '15s';
ALTER ROLE upfixy_executor SET idle_in_transaction_session_timeout = '30s';

SELECT format('CREATE DATABASE %I OWNER upfixy_owner', :'db')
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db') \gexec

\connect :db
ALTER SCHEMA public OWNER TO upfixy_owner;
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO upfixy_owner, upfixy_app, upfixy_worker, upfixy_executor;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO upfixy_app, upfixy_worker, upfixy_executor;
