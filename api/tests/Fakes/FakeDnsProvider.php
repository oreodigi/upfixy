<?php

namespace Tests\Fakes;

use App\Repairs\DnsProvider;
use App\Repairs\ProviderError;

/**
 * A synthetic Cloudflare zone. Records every call. Failure modes:
 *   $nextWrite = 'unknown_applied'     write is applied, then the provider errors (timeout/5xx)
 *   $nextWrite = 'unknown_not_applied' the call fails before anything changes
 *   $nextWrite = 'rejected'            a definite 4xx
 *   $afterWrite = fn () => ...         run right after a successful write (e.g. someone else edits)
 */
final class FakeDnsProvider implements DnsProvider
{
    public array $zones = [];      // zone_id => ['name' => ..., 'token' => ...]
    public array $records = [];    // record_id => ['zone' => ..., 'type','name','content','ttl','proxied']
    public array $calls = [];
    public ?string $nextWrite = null;
    public ?\Closure $afterWrite = null;
    private int $n = 0;

    public function zone(string $token, string $zoneId): array
    {
        $this->calls[] = ['zone', $zoneId];
        $z = $this->auth($token, $zoneId);
        return ['zone_name' => $z['name'], 'ref' => $this->ref()];
    }

    public function getRecord(string $token, string $zoneId, string $recordId): array
    {
        $this->calls[] = ['get', $recordId];
        $this->auth($token, $zoneId);
        $r = $this->records[$recordId] ?? null;
        if (!$r || $r['zone'] !== $zoneId) throw new ProviderError('not_found', $this->ref());
        return ['id' => $recordId] + array_diff_key($r, ['zone' => 1]) + ['ref' => $this->ref()];
    }

    public function updateAddressRecord(string $token, string $zoneId, string $recordId, array $record): array
    {
        $this->calls[] = ['patch', $recordId, $record['content']];
        $this->auth($token, $zoneId);
        $mode = $this->nextWrite;
        $this->nextWrite = null;
        if ($mode === 'rejected') throw new ProviderError('rejected', $this->ref());
        if ($mode === 'unknown_not_applied') throw new ProviderError('unknown_outcome', $this->ref());
        if (!isset($this->records[$recordId]) || $this->records[$recordId]['zone'] !== $zoneId) throw new ProviderError('not_found', $this->ref());
        $this->records[$recordId] = ['zone' => $zoneId] + array_intersect_key($record, array_flip(['type', 'name', 'content', 'ttl', 'proxied']));
        if ($mode === 'unknown_applied') throw new ProviderError('unknown_outcome', $this->ref());
        if ($this->afterWrite) { $f = $this->afterWrite; $this->afterWrite = null; $f($this); }
        return ['id' => $recordId] + $record + ['ref' => $this->ref()];
    }

    public function writes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === 'patch'));
    }

    private function auth(string $token, string $zoneId): array
    {
        $z = $this->zones[$zoneId] ?? null;
        if (!$z || !hash_equals($z['token'], $token)) throw new ProviderError('unauthorized', $this->ref());
        return $z;
    }

    private function ref(): string
    {
        return 'cf-ray-test-' . (++$this->n);
    }
}
