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

Meaningful human power, console, file, startup/settings, allocation, database configuration, subuser, schedule-edit, and task-edit events reset activity. Automatic `server:schedule.execute`, backup events, and lifecycle events do not. Warning delivery identity includes the target timestamp, so a new activity cycle may warn again without duplicating an old cycle.

Archival obtains a distributed server lock, revalidates state, requires Wings-confirmed offline status, rejects attached databases and currently unsupported material metadata, captures an encrypted manifest, and starts an asynchronous locked backup on the policy-selected host. Node/network errors fail closed. On verified completion, `AdoptBackupAsArchiveService` deletes only the temporary **database row** (never via Pelican's remote-delete service), refreshes the Server, and calls Pelican `ServerDeletionService`. If deletion fails, the adopted object remains and the operation is retryable; duplication is preferred to loss.

Restore verifies the object and encrypted manifest, then calls native `ServerCreationService` with startup disabled. Native deployment performs allocation validation; the original allocation set is supplied as preference data and changed ports must be surfaced. Restore waits for `Installed`, creates locked temporary Backup bookkeeping, sets `RestoringBackup`, and calls `DaemonBackupRepository::restore()` with an explicit five-minute URL for the original key. Failed install/restore never removes the archive.

Permanent deletion starts only after retention and final-delivery grace. It locks the archive, deletes the exact key, verifies absence, clears manifest/key/checksum and restoration metadata, and keeps a small tombstone. Failure remains retryable. Signed Panel links mint fresh five-minute S3 URLs; raw long-lived S3 URLs are never emailed.

## Version 1 limitations

* **Attached Pelican databases block archival.** File backups do not prove database contents are safe.
* Servers with subusers, schedules/tasks, or mounts are blocked rather than silently losing material Panel-side configuration.
* Full notification presentation, policy CRUD, allocation-change interaction, attachment delivery, and restore-completion UI depend on stable Pelican extension surfaces. Operators must not enable destructive production use until their installed Pelican version passes the automated and integration verification below.
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
