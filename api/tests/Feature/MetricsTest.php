<?php

namespace Tests\Feature;

use Tests\TestCase;

/** UX-01…03 dashboard metrics: measured values only, tenant/client isolation, gaps stay gaps. */
class MetricsTest extends TestCase
{
    private function reading(string $ws, string $target, string $kind, string $sev, int $minutesAgo, int $cycle, array $detail): void
    {
        $this->owner()->insert("INSERT INTO observations(workspace_id, monitor_target_id, check_kind, cycle_no, severity, result_code, observed_at, detail)
            VALUES (?, ?, ?, ?, ?, 'x', now() - make_interval(mins => ?), ?::jsonb)", [$ws, $target, $kind, $cycle, $sev, $minutesAgo, json_encode($detail)]);
    }

    public function test_metrics_are_measured_isolated_and_never_filled_in(): void
    {
        [$olive, $carl, $bob] = [$this->makeUser('olive'), $this->makeUser('carl'), $this->makeUser('bob')];
        $ws = $this->makeWorkspace('A');
        $wsB = $this->makeWorkspace('B');
        $this->addMember($ws, $olive, 'owner');
        $this->addMember($ws, $carl, 'client_owner');
        $this->addMember($wsB, $bob, 'owner');
        $mitra = $this->makeClient($ws, 'Mitra');
        $other = $this->makeClient($ws, 'Other');
        $this->assign($ws, $mitra, $carl);
        [$s1, $t1] = $this->makeSite($ws, $mitra, 'one.example');
        [$s2, $t2] = $this->makeSite($ws, $other, 'two.example');
        [$sB, $tB] = $this->makeSite($wsB, null, 'secret-b.example');
        // one.example: 4 checks in the last hour, 1 critical; ms 100/200/300/400. Nothing older.
        foreach ([[5, 'ok', 100], [15, 'ok', 200], [25, 'warning', 300], [35, 'critical', 400]] as $i => [$m, $sev, $ms]) {
            $this->reading($ws, $t1, 'http', $sev, $m, $i + 1, ['ms_total' => $ms]);
        }
        $this->reading($ws, $t1, 'tls', 'ok', 5, 1, ['days_left' => 41, 'not_after' => '2026-11-29T00:00:00+00:00']);
        $this->reading($ws, $t2, 'http', 'ok', 10, 1, ['ms_total' => 900]);
        $this->reading($wsB, $tB, 'http', 'ok', 10, 1, ['ms_total' => 50]);
        $this->reading($ws, $t1, 'http', 'ok', 60 * 30, 99, ['ms_total' => 9999]);   // outside the 24 h window

        $r = $this->api($olive, $ws, 'GET', 'boards/metrics')->assertOk();
        $one = $r->json("websites.{$s1}");
        $this->assertSame(4, $one['http_checks']);
        $this->assertEquals(75.0, $one['availability_pct']);
        $this->assertSame(250, $one['avg_ms']);
        $this->assertSame(100, $one['last_ms']);
        $this->assertSame(41, $one['tls_days_left']);
        $this->assertCount(24, $r->json('series'));
        $this->assertCount(24, $one['ms_series']);
        $known = array_filter($one['ms_series'], fn ($x) => $x !== null);
        $this->assertNotEmpty($known);
        $this->assertGreaterThan(12, count(array_filter($r->json('series'), fn ($h) => $h['availability_pct'] === null)), 'hours with no readings are gaps, not zero');
        $this->assertNull($r->json("websites.{$sB}"));
        $this->assertStringNotContainsString('secret-b', $r->getContent());

        // A client owner sees only their client's websites in the numbers.
        $c = $this->api($carl, $ws, 'GET', 'boards/metrics')->assertOk();
        $this->assertNotNull($c->json("websites.{$s1}"));
        $this->assertNull($c->json("websites.{$s2}"));
        $this->assertSame(4, array_sum(array_column($c->json('series'), 'checks')));
        // Another workspace sees none of it.
        $b = $this->api($bob, $wsB, 'GET', 'boards/metrics')->assertOk();
        $this->assertSame(1, array_sum(array_column($b->json('series'), 'checks')));
        $this->assertNull($b->json("websites.{$s1}"));
    }
}
