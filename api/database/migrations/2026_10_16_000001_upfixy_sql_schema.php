<?php

use App\Support\SqlMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bootstraps a fresh database from ../db/migrations. Laravel runs this once;
 * later SQL files are applied by `php artisan upfixy:migrate` (or npm run db:migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        SqlMigrator::run(DB::connection($this->getConnection()));
    }

    public function down(): void
    {
        // Forward-only.
    }
};
