# Backup and restore runbook

Policy (PRD ORG-7): encrypted off-host **daily** backups of the PostgreSQL database and uploaded project
documents, **35-day** retention, **quarterly** restore tests, **24-hour** RPO and RTO.

This repository provides the automation. The policy is **not operational** until a deployment has configured
an off-host destination and a restore test against that destination has passed (record the result below).

The scripts themselves have been exercised end to end with `scripts/verify-backup-drill.sh` (see
[Local drill](#local-drill)); that drill uses a local folder in place of the remote, so it does not replace
the restore test against the real off-host copy.

## What is backed up

| Data | Source | Artifact |
| --- | --- | --- |
| Database | `pg_dump --format=custom` of the `app` database | `db-<stamp>.dump.age` |
| Uploaded documents | `src/var/project-documents` (project files, plus document-registry files in its `dms/` subfolder) | `documents-<stamp>.tar.gz.age` |
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
   `rclone check`, then removes whole backup sets whose name (their UTC timestamp) is older than
   `BACKUP_RETENTION_DAYS` (default 35); other folders on the remote are left alone. It writes
   `storage/backup-status/last-status` (`ok|failed|pending <time> ...`). The container health check
   (`docker compose --profile backup ps`) turns **unhealthy** when the last run failed or the status is older
   than 26 hours; point monitoring (for example a Zabbix file/age check) at the same file.

   The `pg_dump` in the backup image must have the same major version as the database server (PostgreSQL 17,
   as in `docker-compose.yml`), and a dump restores only into the same or a newer major version. Change the
   client package in `docker/backup/Dockerfile` when the server is upgraded.
4. Run one backup immediately to confirm: `docker compose --profile backup run --rm --entrypoint backup.sh backup`.

## Quarterly restore test

Run on a machine that has the private key, against a scratch database (never the live one):

```bash
docker compose --profile backup run --rm \
  -v "$PWD/crm-backup.key:/key:ro" --entrypoint restore.sh backup latest /key crm_restore_test
```

It checks checksums, decrypts, restores into `crm_restore_test`, reports table, migration, invoice and document
counts, and prints the elapsed seconds (compare with the 24-hour RTO) and the age of the backup set (it warns
when the newest set is older than the 24-hour RPO). Then `dropdb crm_restore_test`. Also open a restored
document to confirm it is readable. The script refuses to restore into the live database.

### Restore log

| Date | Backup set | Elapsed | Backup age (RPO) | Result | Performed by |
| --- | --- | --- | --- | --- | --- |
| _none yet_ | | | | | |

## Local drill

`scripts/verify-backup-drill.sh` builds the backup image, backs up a running database into a temporary local
folder with a throwaway key, checks pruning of an expired set, the status file and health check, restores into
a scratch database, compares row counts with the source and confirms that restoring over the live database is
refused. It only reads the source database.

```bash
DOCKER_NETWORK=crm_app PGPASSWORD=... scripts/verify-backup-drill.sh
```

| Date | Database | Result | Notes |
| --- | --- | --- | --- |
| 2026-11-12 | Disposable PostgreSQL 17 with ledger test data (not production) | Passed (8 checks) | First run of the scripts. It found and fixed two defects: the retention step failed on every run, and `latest` could select a folder that is not a backup set. |

## Disaster restore

Stop the app, restore the database into an empty `app` database with `pg_restore --no-owner -d app`, extract the
documents archive into `src/var/project-documents`, run `bin/console doctrine:migrations:status`, then start
the app. Confirm `GET /api/finance/reports/reconciliation` as an administrator.
