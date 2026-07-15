# Backup and Restore

Create a database export before launch, releases, migrations, pricing/provider changes, and any repair. `bin/backup-database.php` creates timestamped private dumps, SHA-256 checksums, database records, and retention cleanup without echoing passwords. Also back up source/configuration, excluding cache, sessions, temporary files, and redundant logs.

Download copies off-host, encrypt them, restrict access, and test restore quarterly into a staging database. Validate migrations, row counts, users, wallet reconciliation, provider mappings, and admin login there. Never expose restoration as a public button and never restore directly over production without a fresh backup, written plan, maintenance window, and Adult Owner reauthentication.

