<?php

namespace Tests\Feature;

use App\Monitoring\Reading;
use App\Monitoring\Recorder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * AC-MON-005.2 (partial): the scheduling + recording path for 1,000 due
 * targets, without network. Real probe time depends on the sites and is
 * covered by running several worker processes; this proves the database side
 * is not the bottleneck. Prints the measured rate.
 */
#[Group('capacity')]
class MonitorCapacityTest extends TestCase
{
    public function test_one_thousand_targets_claim_and_record_well_within_a_cycle(): void
    {
        $ws = $this->makeWorkspace('Load');
        $this->owner()->unprepared("
            WITH w AS (INSERT INTO websites(workspace_id, display_name) SELECT '{$ws}', 's' || g || '.example' FROM generate_series(1, 1000) g RETURNING id, display_name)
            INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, state) SELECT '{$ws}', id, 'https', display_name, 443, 'active' FROM w");
        $db = DB::connection('pgsql_worker');
        $rec = app(Recorder::class);
        $readings = [Reading::ok('dns', 'DNS_OK'), Reading::ok('tls', 'TLS_OK'), Reading::ok('http', 'HTTP_OK', ['status' => 200])];
        $start = microtime(true);
        $done = 0;
        while ($batch = $db->select('SELECT * FROM worker_claim_due(50, 50, 120)')) {
            foreach ($batch as $t) { $rec->record($t, $readings, 'active', null, now(), now()); $done++; }
        }
        $secs = microtime(true) - $start;
        fwrite(STDERR, sprintf("\n[capacity] %d target cycles claimed and recorded in %.1f s (%.0f/s, one process)\n", $done, $secs, $done / $secs));
        $this->assertSame(1000, $done);
        $this->assertSame(3000, $this->owner()->table('observations')->count());
        $this->assertLessThan(60, $secs, 'database path must take well under one 5-minute cycle');
    }
}
