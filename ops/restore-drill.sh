#!/usr/bin/env bash
# Restore drill: decrypt a backup into a THROWAWAY database, then prove it is usable:
#   checksum ok → restore ok → audit chain intact and matching the manifest → row counts → tenant isolation still enforced.
# Never restores over a live database.
#
#   DRILL_ADMIN_URL=postgres://postgres@host/postgres  AGE_IDENTITY=key.txt  ops/restore-drill.sh upfixy-….dump.age
set -euo pipefail
FILE="${1:?usage: restore-drill.sh BACKUP.dump.age}"
: "${DRILL_ADMIN_URL:?set DRILL_ADMIN_URL (a role that can create databases)}"
: "${AGE_IDENTITY:?set AGE_IDENTITY (age private key file)}"
DB="upfixy_drill_$(date -u +%Y%m%d%H%M%S)"
started=$(date +%s)
echo "1. checksum";        ( cd "$(dirname "$FILE")" && sha256sum --check --quiet "$(basename "$FILE").sha256" )
echo "2. create $DB";      psql "$DRILL_ADMIN_URL" -qc "CREATE DATABASE $DB OWNER upfixy_owner"
DRILL_URL="${DRILL_ADMIN_URL%/*}/$DB"
cleanup() { psql "$DRILL_ADMIN_URL" -qc "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || true; }
[ "${KEEP_DRILL_DB:-0}" = "1" ] || trap cleanup EXIT
echo "3. restore";         age --decrypt --identity "$AGE_IDENTITY" "$FILE" | pg_restore --no-owner --role=upfixy_owner --exit-on-error --dbname="$DRILL_URL"
psql "$DRILL_URL" -qc "GRANT USAGE ON SCHEMA public TO upfixy_app, upfixy_worker, upfixy_executor" >/dev/null
echo "4. audit chain"
read -r broken checked < <(psql "$DRILL_URL" -Atc "SELECT coalesce(broken_id::text,'none') || ' ' || checked FROM audit_verify(0)")
[ "$broken" = "none" ] || { echo "   FAIL: audit chain broken at $broken"; exit 1; }
want=$(grep -o 'audit_last_hash=[0-9a-f]*\|audit_last_hash=none' "$FILE.manifest" | cut -d= -f2)
got=$(psql "$DRILL_URL" -Atc "SELECT coalesce((SELECT row_hash FROM audit_events ORDER BY id DESC LIMIT 1),'none')")
[ "$want" = "$got" ] || { echo "   FAIL: newest audit hash differs from the manifest"; exit 1; }
echo "   ok: $checked rows, newest hash matches manifest"
echo "5. row counts";      psql "$DRILL_URL" -Atc "SELECT 'workspaces=' || (SELECT count(*) FROM workspaces) || ' websites=' || (SELECT count(*) FROM websites) || ' observations=' || (SELECT count(*) FROM observations) || ' quotes=' || (SELECT count(*) FROM quotes) || ' payment_orders=' || (SELECT count(*) FROM payment_orders)"
echo "6. isolation still enforced (app role, no tenant context, sees nothing)"
n=$(psql "$DRILL_URL" -At -c "SET ROLE upfixy_app" -c "SELECT count(*) FROM websites" | tail -1)
[ "$n" = "0" ] || { echo "   FAIL: app role saw $n websites without tenant context"; exit 1; }
echo "   ok"
echo "Drill passed in $(( $(date +%s) - started ))s (restore time = RTO input)."
