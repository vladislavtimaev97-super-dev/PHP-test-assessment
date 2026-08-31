<?php

declare(strict_types=1);

use App\Infra\Db;
use App\Infra\Log;

require __DIR__ . '/../vendor/autoload.php';

Log::init('migrate');

// The database container may still be starting.
for ($i = 1; $i <= 60; $i++) {
    try {
        Db::value('SELECT 1');
        break;
    } catch (Throwable $e) {
        if ($i === 60) {
            Log::error('db_unreachable', ['message' => $e->getMessage()]);
            exit(1);
        }
        Db::reset();
        usleep(500_000);
    }
}

Db::run('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   text PRIMARY KEY,
    applied_at timestamptz NOT NULL DEFAULT now()
)');

$files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($files);

$applied = 0;
foreach ($files as $file) {
    $name = basename($file);

    $already = Db::value('SELECT 1 FROM schema_migrations WHERE filename = :f', ['f' => $name]);
    if ($already !== null) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        Log::error('migration_unreadable', ['file' => $name]);
        exit(1);
    }

    try {
        Db::transaction(function () use ($sql, $name) {
            Db::pdo()->exec($sql);
            Db::run('INSERT INTO schema_migrations (filename) VALUES (:f)', ['f' => $name]);
        });
        Log::info('migration_applied', ['file' => $name]);
        $applied++;
    } catch (Throwable $e) {
        Log::error('migration_failed', ['file' => $name, 'message' => $e->getMessage()]);
        exit(1);
    }
}

Log::info('migrations_done', ['applied' => $applied, 'total' => count($files)]);
