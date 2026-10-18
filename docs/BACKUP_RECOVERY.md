# Backup and recovery

**Readiness: scripts built and a local drill passed. Nothing is scheduled, because no server exists yet (D-02).**

## What is backed up

| Item | How | Where it must live |
| --- | --- | --- |
| Upfixy database (schema + data) | `ops/backup.sh`: `pg_dump` custom format, encrypted with **age** to a public recipient key, SHA-256 checksum, manifest (newest audit hash, migration count) | Off-server storage, separate from the database host |
| `APP_KEY` (decrypts MFA secrets, merchant secrets) | Not in the database backup | Founder's password manager / secrets store |
| `UPFIXY_CONNECTOR_KEYPAIR`, `UPFIXY_REPAIRS_KEYPAIR` | Not in the database backup | Same; different custodian from the backup files if possible |
| Audit anchor log (`storage/app/audit-anchors.log`) | `php artisan upfixy:audit --anchor`, daily | Outside the database host; it proves the audit trail was not rewritten |
| age **private** key (to read backups) | Never on the server | Offline, with the founder |

Because backups are encrypted to a public key, a compromised server can create backups but cannot read old ones. Sealed provider tokens and encrypted secrets in a backup stay unreadable without their own keys.

## Restore drill (`ops/restore-drill.sh`)

The drill restores into a **throwaway** database, never over a live one, and checks:

1. The checksum.
2. That the restore succeeds.
3. That the **audit chain** is intact and its newest hash equals the backup manifest.
4. Row counts.
5. That **tenant isolation** is still enforced (the app role with no tenant context sees nothing).

The database is dropped afterwards.

Local drill, 18 Oct 2026, on the seeded development database (migrations 0001–0016):

```
1. checksum
2. create upfixy_drill_20261018200141
3. restore
4. audit chain
   ok: 11 rows, newest hash matches manifest
5. row counts
workspaces=3 websites=13 observations=33 quotes=1 payment_orders=0
6. isolation still enforced (app role, no tenant context, sees nothing)
   ok
Drill passed in 0s (restore time = RTO input).
```

Two bugs were found and fixed by running it:
- The checksum file recorded an absolute path, so a moved backup failed verification.
- Audit verification refused the superuser used by the drill.

## Proposed targets (founder to confirm with D-02/D-12)

| | Proposal |
| --- | --- |
| RPO | 24 h (daily dump) at pilot; add WAL archiving / point-in-time recovery before paid launch |
| RTO | 4 h for the pilot (restore + DNS switch); measure on the real server size |
| Retention | 7 daily + 4 weekly + 12 monthly; financial records follow D-12 |
| Drill | Monthly, and after every migration that changes RLS or grants |

## Not done

- Scheduling (cron on the future server).
- Off-site storage choice.
- WAL/PITR.
- A drill on production-sized data.
- A documented key-escrow procedure for the founder.
