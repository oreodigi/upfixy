<?php

namespace App\Support;

use Illuminate\Database\Connection;

/**
 * Applies ../db/migrations/*.sql in order, each once, recording them in
 * schema_migrations, exactly like db/migrate.mjs. Either runner can be used.
 */
final class SqlMigrator
{
    /** @return string[] files applied in this run */
    public static function run(Connection $db): array
    {
        $db->unprepared('CREATE TABLE IF NOT EXISTS schema_migrations (
            name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
        $done = $db->table('schema_migrations')->pluck('name')->all();
        $files = glob(base_path('../db/migrations/*.sql'));
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) continue;
            $db->transaction(function () use ($db, $file, $name) {
                $db->unprepared(file_get_contents($file));
                $db->table('schema_migrations')->insert(['name' => $name]);
            });
            $applied[] = $name;
        }
        return $applied;
    }
}
