# Backup and restore runbook

Policy (PRD ORG-7): encrypted off-host **daily** backups of the PostgreSQL database and uploaded project
documents, **35-day** retention, **quarterly** restore tests, **24-hour** RPO and RTO.

This repository provides the automation. The policy is **not operational** until a deployment has configured
an off-host destination and a restore test has passed (record the result below).

## What is backed up

| Data | Source | Artifact |
| --- | --- | --- |
| Database | `pg_dump --format=custom` of the `app` database | `db-<stamp>.dump.age` |
| Uploaded documents | `src/var/project-documents` | `documents-<stamp>.tar.gz.age` |
| Integrity | SHA-256 of each artifact | `SHA256SUMS-<stamp>` |

JWT keys (`src/config/jwt`) and `.env.local` secrets are **not** included; store them in a secrets manager.

## Setup

1. Generate an age key pair on an admin workstation, not on the server: `age-keygen -o crm-backup.key`.
   Keep the private key (`crm-backup.key`) offline in at least two places. Anyone without it cannot restore.
2. Create an rclone config for the off-host destination (object storage in a different account/region than the
   server) in `docker/backup/rclone.conf` (git-ignored) or set `BACKUP_RCLONE_CONFIG` to its path. Use a
   credential that can write and delete only in the backup bucket; enable bucket versioning/object lock if the
   provider supports it so ransomware on the host cannot erase history.
3. Start the service:

   ```bash
   export BACKUP_AGE_RECIPIENT=age1...        # public key only
   export BACKUP_REMOTE=offsite:crm-backups/prod
   docker compose --profile backup up -d --build backup
   ```

   The service runs `backup.sh` daily at 02:00 UTC (`BACKUP_HOUR_UTC`), verifies the off-host copy with
   `rclone check`, then deletes sets older than `BACKUP_RETENTION_DAYS` (default 35). It writes
   `storage/backup-status/last-status`; alert if its timestamp is older than 26 hours or begins with `failed`
   (for example a Zabbix file/age check).
4. Run one backup immediately to confirm: `docker compose --profile backup run --rm --entrypoint backup.sh backup`.

## Quarterly restore test

Run on a machine that has the private key, against a scratch database (never the live one):

```bash
docker compose --profile backup run --rm \
  -v "$PWD/crm-backup.key:/key:ro" --entrypoint restore.sh backup latest /key crm_restore_test
```

It checks checksums, decrypts, restores into `crm_restore_test`, reports table, migration, invoice and document
counts, and prints elapsed seconds. Compare against the 24-hour RTO, confirm the newest backup is less than 24
hours old (RPO), then `dropdb crm_restore_test`. Also open a restored document to confirm it is readable.

### Restore log

| Date | Backup set | Elapsed | Backup age (RPO) | Result | Performed by |
| --- | --- | --- | --- | --- | --- |
| _none yet_ | | | | | |

## Disaster restore

Stop the app, restore the database into an empty `app` database with `pg_restore --no-owner -d app`, extract the
documents archive into `src/var/project-documents`, run `bin/console doctrine:migrations:status`, then start
the app. Confirm `GET /api/finance/reports/reconciliation` as an administrator.
