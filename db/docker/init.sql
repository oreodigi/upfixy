-- Local Docker bootstrap: roles and databases matching .env.example. Dev passwords only.
CREATE ROLE upfixy_owner LOGIN PASSWORD 'devonly' CREATEROLE;
CREATE ROLE upfixy_app LOGIN PASSWORD 'devonly';
CREATE DATABASE upfixy_dev OWNER upfixy_owner;
CREATE DATABASE upfixy_test OWNER upfixy_owner;
\c upfixy_dev
ALTER SCHEMA public OWNER TO upfixy_owner;
\c upfixy_test
ALTER SCHEMA public OWNER TO upfixy_owner;
