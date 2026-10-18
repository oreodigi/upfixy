#!/bin/sh
# Local Docker bootstrap only. Development passwords; never use these elsewhere.
set -e
for db in upfixy_dev upfixy_test upfixy_api_test; do
  psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -v owner_pw=devonly -v app_pw=devonly -v worker_pw=devonly -v executor_pw=devonly \
       -v db="$db" -f /bootstrap/roles.sql
done
