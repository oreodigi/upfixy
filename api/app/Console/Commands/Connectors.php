<?php

namespace App\Console\Commands;

use App\Connectors\SyncRunner;
use App\Repairs\SecretBox;
use Illuminate\Console\Command;

/** INT-001 connector worker: verify new connectors, sync active ones. */
class Connectors extends Command
{
    protected $signature = 'upfixy:connectors {--once : One pass then exit} {--keygen : Print a new connector key pair}';
    protected $description = 'Verify and sync read-only provider connectors';

    public function handle(SyncRunner $runner): int
    {
        if ($this->option('keygen')) {
            $k = SecretBox::keygen();
            $this->line('UPFIXY_CONNECTOR_PUBLIC_KEY=' . $k['public'] . '   # API');
            $this->line('UPFIXY_CONNECTOR_KEYPAIR=' . $k['keypair'] . '   # connector worker ONLY');
            return self::SUCCESS;
        }
        do {
            $n = $runner->runOnce();
            if ($this->option('once')) {
                $this->info("Processed {$n} connector(s).");
                return self::SUCCESS;
            }
            if ($n === 0) sleep(5);
        } while (true);
    }
}
