# Server Lifecycle

Native Pelican plugin for conservatively warning, archiving, restoring, and eventually deleting inactive game servers. It never modifies Panel core and has no Tracecat dependency.

> **Safety:** an archive is not safe merely because Wings accepted a backup request. Pelican must report successful completion with non-zero bytes and a checksum, and this plugin must `HeadObject` the exact S3 key and match its size. Until then the live server is never deleted.

## Installation and operation

Install through Pelican's normal plugin installer, run plugin migrations, and keep both `php artisan schedule:work` (or a once-per-minute cron invoking `schedule:run`) and `php artisan queue:work` healthy. The engine defaults **off**, existing/new tracking defaults to automatic lifecycle **off**, and unknown historical activity starts at the time state is first created. Configure operational switches in plugin settings; policies and warning rules live in the database.

Create an enabled lifecycle policy, select a Pelican BackupHost with schema `s3`, add independent archive/deletion warning rules, explicitly assign/enable servers, then enable the engine. Durations are normalized to minutes; `NULL` inactivity means never archive and `NULL` retention means retain forever. A policy snapshot fixes retention behavior when archival succeeds.

## Storage compatibility

The plugin uses the AWS SDK already shipped by Pelican and reads endpoint, bucket, region, key, secret, optional session token, and path-style behavior from the selected BackupHost. It is provider-neutral and supports correctly configured AWS S3, Cloudflare R2, MinIO, Wasabi, Backblaze B2 S3, and other S3-compatible stores. Credentials are never copied to archive rows or user errors.

Only the exact `{original-server-uuid}/{backup-uuid}.tar.gz` object is addressed. Wings/local backups are not long-term archive storage.

## Lifecycle and failure recovery

`ACTIVE → WARNING → ARCHIVING → ARCHIVED → RESTORING → ACTIVE`, with optional `ARCHIVED → DELETION_WARNING → PENDING_DELETION → DELETED`.

Meaningful human power, console, file, startup/settings, allocation, database configuration, subuser, schedule-edit, and task-edit events reset activity. Automatic `server:schedule.execute`, backup events, and lifecycle events do not. Warning delivery identity includes the subject and target timestamp, so a new activity cycle may warn again without duplicating an old cycle. Database and email deliveries are queued and marked delivered only after their native Pelican/Laravel channel succeeds.

Archival obtains a distributed server lock, atomically changes the state to `ARCHIVING`, and requires the exact Wings `Offline` state. `Missing`, running and unreachable states are rejected. It rejects attached databases and currently unsupported material metadata, captures an encrypted manifest plus a credential-free policy snapshot, and starts an asynchronous locked backup on the policy-selected host. On verified completion, `AdoptBackupAsArchiveService` deletes only the temporary **database row** (never via Pelican's remote-delete service), refreshes the Server, and calls Pelican `ServerDeletionService`. If deletion fails, the adopted object remains and a reconciliation job retries only native server deletion; duplication is preferred to loss.

Restore verifies the object and encrypted manifest, looks up the archived IP/ports among currently unused allocations, and safely chooses free replacements without trusting archived numeric allocation IDs. It passes an explicit creation payload and native `DeploymentObject` to `ServerCreationService` with startup disabled. Restore waits for `Installed`, creates locked temporary Backup bookkeeping, sets `RestoringBackup`, and calls `DaemonBackupRepository::restore()` with an explicit five-minute URL for the original key. Completion activity removes only temporary bookkeeping and resets lifecycle activity; failed install/restore never removes the archive or leaves a pre-acceptance Wings failure stuck in `RestoringBackup`.

Permanent deletion starts only after retention, successful final delivery, and the final-delivery grace period. Modes are `none`, `download_link`, `attachment_if_small`, and `attachment_if_small_else_link`. Attachments stream through a private temporary file with unconditional cleanup; links are signed Panel links that mint fresh five-minute S3 URLs. Deletion locks the archive, validates and deletes the exact expected key, verifies absence, clears restoration metadata, and keeps a small tombstone. Failure remains retryable.

## Native UI

The Admin panel discovers lifecycle-policy and archived-server resources. The App panel has an owner-scoped Archived Servers resource linked from the normal server list. Each live server has a Lifecycle page showing state, activity, due date and exemption, with owner-authorized reset and manual archive actions. Service/controller authorization remains enforced even when actions are hidden by configuration.

## Version 1 limitations

* **Attached Pelican databases block archival.** File backups do not prove database contents are safe.
* Servers with subusers, schedules/tasks, or mounts are blocked rather than silently losing material Panel-side configuration.
* Restore currently blocks subusers, schedules/tasks and mounts rather than silently discarding them. Allocation changes are recorded by the planner but richer port-change notification presentation remains future work.
* Operators must not enable destructive production use until their installed Pelican version passes the automated and integration verification below.
* Removing the plugin while archives exist is unsafe. Disable the engine, drain queues, restore/export archives, and retain the database/encryption key before uninstalling. Restrictive BackupHost foreign keys intentionally prevent casual host removal.

## Security and troubleshooting

The manifest uses Laravel's encrypted array cast; preserve `APP_KEY`. Archive queries and download controllers enforce owner/admin access server-side. Never paste BackupHost configuration or exception traces into user tickets. `ARCHIVE_CREATED_DELETE_FAILED`, `RESTORE_FAILED`, and `DELETE_FAILED` retain the most recoverable state and may be retried after correcting node, credentials, capacity, mail, or storage issues. Do not manually delete temporary Backup rows unless you understand archive adoption.

## Non-production destructive integration test

Never begin with a production game server.

1. Create a small disposable server and S3-compatible BackupHost.
2. Assign a short explicit policy and enable lifecycle for only that server.
3. Generate meaningful activity; confirm the due time resets and warnings arrive once.
4. Stop the server and allow archival to start.
5. Confirm Pelican reports backup success and the plugin verifies the S3 object.
6. Confirm the archived-server entry exists, then confirm Panel/Wings data is removed and allocations released.
7. Download the archive through the signed route.
8. Restore; confirm a new native Pelican Server is created and Wings restores files.
9. Occupy the original port and repeat; confirm safe replacement and all additional allocations.
10. Re-archive, exercise deletion warnings/final-download grace, then permanently delete.
11. Confirm only the stored object key disappeared and the tombstone contains no manifest or credentials.
