<?php

namespace App\Console\Commands;

use App\Payments\Reconciler;
use Illuminate\Console\Command;

/** §19.2 server reconciliation: ask Razorpay about orders still pending. */
class Payments extends Command
{
    protected $signature = 'upfixy:payments {--reconcile : Reconcile pending orders once}';
    protected $description = 'Payment maintenance (reconciliation)';

    public function handle(Reconciler $r): int
    {
        $out = $r->runOnce((int) config('upfixy.payments.reconcile_after_seconds'));
        $this->info('Reconciled ' . count($out) . ' order(s): ' . json_encode(array_count_values($out)));
        return self::SUCCESS;
    }
}
