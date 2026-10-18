<?php

namespace App\Console\Commands;

use App\Monitoring\CycleRunner;
use App\Monitoring\Reading;
use App\Monitoring\Recorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The probe worker (M3). Run several copies for throughput; they share work
 * through leases, never through a queue table. Connects ONLY as upfixy_worker.
 *
 *   php artisan upfixy:monitor            # loop forever
 *   php artisan upfixy:monitor --once     # one claim pass (tests, cron)
 */
class Monitor extends Command
{
    protected $signature = 'upfixy:monitor {--once : one claim pass, then exit} {--idle=3 : seconds to sleep when nothing is due}';
    protected $description = 'Run public HTTP/TLS/DNS checks for due monitor targets';

    public function handle(CycleRunner $runner, Recorder $recorder): int
    {
        $db = DB::connection('pgsql_worker');
        $cfg = config('upfixy.monitor');
        do {
            $due = $db->select('SELECT * FROM worker_claim_due(?, ?, ?)', [$cfg['claim_limit'], $cfg['per_workspace'], $cfg['lease_seconds']]);
            foreach ($due as $t) {
                $started = now();
                try {
                    if ($t->claims > $cfg['max_claims']) {
                        // Dead-letter: this cycle keeps crashing its worker. Record it honestly and move on (NFR-16).
                        $kinds = $t->scheme === 'https' ? ['dns', 'tls', 'http'] : ['dns', 'http'];
                        $out = ['readings' => array_map(fn ($k) => Reading::unknown($k, 'probe_error', 'PROBE_UNAVAILABLE', ['dead_letter' => true]), $kinds),
                            'state' => $t->state, 'reason' => null];
                        Log::error('monitor cycle dead-lettered', ['target' => $t->target_id, 'cycle' => $t->cycle_no, 'claims' => $t->claims]);
                    } else {
                        $out = $runner->run($t);
                    }
                    $recorder->record($t, $out['readings'], $out['state'], $out['reason'], $started, now());
                } catch (Throwable $e) {
                    // Lease expires and another pass retries the same cycle.
                    Log::error('monitor cycle failed', ['target' => $t->target_id, 'cycle' => $t->cycle_no, 'error' => $e->getMessage()]);
                }
            }
            if ($this->option('once')) break;
            if ($due === []) sleep((int) $this->option('idle'));
        } while (true);
        return self::SUCCESS;
    }
}
