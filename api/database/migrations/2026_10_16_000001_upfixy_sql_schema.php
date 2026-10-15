<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Single schema source of truth: applies ../db/migrations/*.sql in order,
 * recording each in schema_migrations exactly like db/migrate.mjs, so either
 * runner can be used on the same database. Run as the owner connection:
 *   php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        $db->unprepared('CREATE TABLE IF NOT EXISTS schema_migrations (
            name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
        $done = $db->table('schema_migrations')->pluck('name')->all();
        $files = glob(base_path('../db/migrations/*.sql'));
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $db->transaction(function () use ($db, $file, $name) {
                $db->unprepared(file_get_contents($file));
                $db->table('schema_migrations')->insert(['name' => $name]);
            });
        }
    }

    public function down(): void
    {
        // Forward-only. Use `npm run db:reset` for local resets.
    }
};
