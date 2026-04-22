<?php
/**
 * Daily backup — CLI entry point for cron.
 *
 * Idempotent: running multiple times in one day is a no-op after the first
 * success. Intended cron line (once a day, at 03:30):
 *
 *   30 3 * * * /usr/bin/php /path/to/BisericaSfVasile/bin/backup-daily.php >> /path/to/backup.log 2>&1
 *
 * Exit codes:
 *   0 — created today's backup, or one already existed
 *   1 — another run is holding the lock
 *   2 — creation failed
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(2);
}

require __DIR__ . '/../includes/backup-lib.php';

$result = bsv_backup_run_daily_if_due();
$stamp  = date('c');

switch ($result['status']) {
    case 'created':
        fwrite(STDOUT, "[$stamp] daily backup created: {$result['path']}\n");
        exit(0);
    case 'up_to_date':
        fwrite(STDOUT, "[$stamp] daily backup for today already exists — nothing to do.\n");
        exit(0);
    case 'locked':
        fwrite(STDERR, "[$stamp] another backup run is holding the lock; skipping.\n");
        exit(1);
    case 'error':
    default:
        fwrite(STDERR, "[$stamp] daily backup failed: " . ($result['message'] ?? 'unknown') . "\n");
        exit(2);
}
