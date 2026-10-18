#!/usr/bin/env bash
# Upfixy database backup (NFR backup readiness). Encrypted with age to a PUBLIC recipient key, so the
# backup host can create backups but cannot read them. The private key lives offline / with the founder.
#
#   UPFIXY_DB_URL=postgres://upfixy_owner:…@host/upfixy  AGE_RECIPIENT=age1…  ops/backup.sh /path/to/backups
#
# Contents: the whole Upfixy database (schema + data, including sealed provider tokens and encrypted
# secrets, which stay unreadable without their separate keys). NOT included, and backed up separately:
# APP_KEY, UPFIXY_CONNECTOR_KEYPAIR, UPFIXY_REPAIRS_KEYPAIR, Razorpay keys, the audit anchor log.
set -euo pipefail
OUT_DIR="${1:?usage: backup.sh OUT_DIR}"
: "${UPFIXY_DB_URL:?set UPFIXY_DB_URL (owner role)}"
: "${AGE_RECIPIENT:?set AGE_RECIPIENT (age public key)}"
mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/upfixy-$STAMP.dump.age"
# Custom format: compressed, restorable table by table; --no-owner so a restore can map roles.
pg_dump --format=custom --no-owner --dbname="$UPFIXY_DB_URL" | age --encrypt --recipient "$AGE_RECIPIENT" --output "$FILE"
( cd "$OUT_DIR" && sha256sum "$(basename "$FILE")" > "$(basename "$FILE").sha256" )   # relative name: the backup can be moved
# Record what the backup should contain, for the restore drill to compare.
psql "$UPFIXY_DB_URL" -Atc "SELECT 'audit_last_id=' || coalesce(max(id),0) || ' audit_last_hash=' || coalesce((SELECT row_hash FROM audit_events ORDER BY id DESC LIMIT 1),'none') FROM audit_events" > "$FILE.manifest"
psql "$UPFIXY_DB_URL" -Atc "SELECT 'migrations=' || count(*) FROM schema_migrations" >> "$FILE.manifest" 2>/dev/null || true
echo "$FILE"
