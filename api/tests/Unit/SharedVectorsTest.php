<?php

namespace Tests\Unit;

use App\Domain\Health;
use App\Domain\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Same vectors as packages/core/test/vectors.test.ts: TS and PHP must agree. */
class SharedVectorsTest extends TestCase
{
    private static function vectors(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . "/../../../packages/core/vectors/{$name}.json"), true);
    }

    public static function normalizeCases(): iterable
    {
        foreach (self::vectors('url-guard')['normalize'] as $v) {
            yield mb_substr($v['input'], 0, 60) => [$v];
        }
    }

    #[DataProvider('normalizeCases')]
    public function test_normalize(array $v): void
    {
        $r = UrlGuard::normalize($v['input']);
        if ($v['code'] === 'ok') {
            $this->assertTrue($r['ok'], 'rejected: ' . ($r['code'] ?? ''));
            $this->assertSame($v['target'], $r['target']);
        } else {
            $this->assertSame($v['code'], $r['ok'] ? 'ok' : $r['code']);
        }
    }

    public function test_blocked_and_allowed_addresses(): void
    {
        $v = self::vectors('url-guard');
        foreach ($v['blocked'] as $a) $this->assertTrue(UrlGuard::isBlockedAddress($a), "should block {$a}");
        foreach ($v['allowed'] as $a) $this->assertFalse(UrlGuard::isBlockedAddress($a), "should allow {$a}");
    }

    public function test_site_health(): void
    {
        foreach (self::vectors('health')['site'] as $v) {
            $this->assertSame($v['lane'], Health::site($v['monitorActive'], $v['readings'])['lane'], $v['name']);
        }
    }

    public function test_roll_up(): void
    {
        foreach (self::vectors('health')['rollUp'] as $v) {
            $this->assertSame($v['lane'], Health::rollUp($v['lanes']), implode('+', $v['lanes']));
        }
    }
}
