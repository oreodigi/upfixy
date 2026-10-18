<?php

use App\Support\SqlMigrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('upfixy:migrate', function () {
    $applied = SqlMigrator::run(DB::connection('pgsql_owner'));
    $this->info($applied ? 'Applied: ' . implode(', ', $applied) : 'Database is up to date.');
})->purpose('Apply pending db/migrations/*.sql as the schema owner');
