<?php

$defaultQueueConnection = env('QUEUE_CONNECTION', 'sync');

return [

    /*
    |--------------------------------------------------------------------------
    | Backup storage
    |--------------------------------------------------------------------------
    |
    | Every run writes its files into its own folder under this directory. In
    | Docker it is a named volume mounted into both the web container (to list
    | and download files) and the backup worker (which writes them).
    |
    */

    'directory' => env('BACKUP_DIR', storage_path('app/backups')),

    // Dumps taken by the container entrypoint before `migrate --force`.
    'pre_migration_directory' => env('DATABASE_BACKUP_DIR', '/var/backups/postgres'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Backups can run for a long time, so they get their own queue connection
    | whose retry_after is longer than the job timeout. Otherwise Redis would
    | hand a running backup to a second worker after 90 seconds.
    |
    */

    'queue_connection' => env(
        'BACKUP_QUEUE_CONNECTION',
        $defaultQueueConnection === 'redis' ? 'redis-backups' : $defaultQueueConnection,
    ),

    'queue' => env('BACKUP_QUEUE', 'backups'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | When set (and encryption is enabled in the admin), every backup file is
    | encrypted with AES-256 (openssl, PBKDF2). The passphrase lives only in
    | the environment, never next to the backups. Losing it makes encrypted
    | backups unrecoverable.
    |
    */

    // Laravel database connections to dump. Empty = the app's default one.
    'connections' => [
        'database' => env('BACKUP_DB_CONNECTION'),
        'analytics' => env('BACKUP_ANALYTICS_DB_CONNECTION', 'analytics'),
    ],

    'encryption_passphrase' => env('BACKUP_ENCRYPTION_PASSPHRASE'),

    // Linked from notification emails.
    'admin_url' => env('ADMIN_FRONTEND_URL', 'http://localhost:5174'),

    'binaries' => [
        'pg_dump' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),
        'pg_restore' => env('BACKUP_PG_RESTORE_PATH', 'pg_restore'),
        'psql' => env('BACKUP_PSQL_PATH', 'psql'),
        'redis_cli' => env('BACKUP_REDIS_CLI_PATH', 'redis-cli'),
        'rclone' => env('BACKUP_RCLONE_PATH', 'rclone'),
        'openssl' => env('BACKUP_OPENSSL_PATH', 'openssl'),
    ],

];
