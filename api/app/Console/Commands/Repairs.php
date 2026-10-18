<?php

namespace App\Console\Commands;

use App\Repairs\Executor;
use App\Repairs\SecretBox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The repair executor process (M7). Runs as upfixy_executor and is the only
 * process configured with UPFIXY_REPAIRS_KEYPAIR.
 *
 *   php artisan upfixy:repairs                 loop
 *   php artisan upfixy:repairs --once          one unit of work
 *   php artisan upfixy:repairs --keygen        print a new key pair (store the private half in a secret manager)
 *   php artisan upfixy:repairs --pause="why"   engage the GLOBAL kill switch (platform operators)
 *   php artisan upfixy:repairs --resume="why"  release it (founder-approved release only)
 */
class Repairs extends Command
{
    protected $signature = 'upfixy:repairs {--once} {--idle=5} {--keygen} {--pause=} {--resume=}';
    protected $description = 'Run the controlled DNS repair executor';

    public function handle(Executor $executor): int
    {
        if ($this->option('keygen')) {
            $k = SecretBox::keygen();
            $this->line("UPFIXY_REPAIRS_PUBLIC_KEY={$k['public']}   # API hosts");
            $this->line("UPFIXY_REPAIRS_KEYPAIR={$k['keypair']}   # executor host ONLY");
            return self::SUCCESS;
        }
        foreach (['pause' => true, 'resume' => false] as $opt => $paused) {
            if (($why = $this->option($opt)) !== null) {
                DB::connection('pgsql_owner')->update('UPDATE repair_controls SET paused = ?, reason = ?, changed_at = now() WHERE workspace_id IS NULL', [$paused, $why]);
                $this->info('Global repairs ' . ($paused ? 'PAUSED' : 'resumed') . ": {$why}");
                return self::SUCCESS;
            }
        }
        do {
            $did = $executor->runOnce();
            if ($this->option('once')) break;
            if (!$did) sleep((int) $this->option('idle'));
        } while (true);
        return self::SUCCESS;
    }
}
