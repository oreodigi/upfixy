<?php

namespace App\Jobs;

use App\Support\Net\OwnershipChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Runs as upfixy_worker: the only role that can mark a claim verified, and
 * only by reporting the exact token it observed (worker_record_claim_check).
 */
class VerifyOwnershipClaim implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $claimId)
    {
        $this->afterCommit();
    }

    public function handle(OwnershipChecker $checker): void
    {
        $db = DB::connection('pgsql_worker');
        $claim = $db->selectOne('SELECT * FROM worker_claim_for_check(?)', [$this->claimId]);
        if (!$claim || $claim->status !== 'challenge_issued') {
            return;
        }
        $result = $checker->check($claim);
        $db->select('SELECT worker_record_claim_check(?, ?, ?)', [$this->claimId, $result['found'], $result['detail']]);
    }
}
