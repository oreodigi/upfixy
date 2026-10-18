<?php

namespace App\Repairs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare API v4 (EXT, verify against an isolated test zone before use — FRD §25.8):
 *   GET   /zones/{zone_id}
 *   GET   /zones/{zone_id}/dns_records/{record_id}
 *   PATCH /zones/{zone_id}/dns_records/{record_id}   (type, name, content, ttl, proxied all sent explicitly)
 * Authorization: Bearer <scoped API token>. Tokens never appear in logs or errors.
 */
final class CloudflareDnsProvider implements DnsProvider
{
    public function __construct(private string $base = 'https://api.cloudflare.com/client/v4', private int $timeout = 15) {}

    public function zone(string $token, string $zoneId): array
    {
        $r = $this->call('GET', "/zones/{$zoneId}", $token, null);
        return ['zone_name' => strtolower((string) ($r['result']['name'] ?? '')), 'ref' => $r['ref']];
    }

    public function getRecord(string $token, string $zoneId, string $recordId): array
    {
        return $this->record($this->call('GET', "/zones/{$zoneId}/dns_records/{$recordId}", $token, null));
    }

    public function updateAddressRecord(string $token, string $zoneId, string $recordId, array $record): array
    {
        if (!in_array($record['type'], ['A', 'AAAA'], true)) throw new ProviderError('rejected', null, 'only A and AAAA records');
        $body = ['type' => $record['type'], 'name' => $record['name'], 'content' => $record['content'],
            'ttl' => (int) $record['ttl'], 'proxied' => (bool) $record['proxied']];
        return $this->record($this->call('PATCH', "/zones/{$zoneId}/dns_records/{$recordId}", $token, $body));
    }

    private function call(string $method, string $path, string $token, ?array $body): array
    {
        try {
            $req = Http::withToken($token)->acceptJson()->timeout($this->timeout);
            /** @var Response $r */
            $r = $method === 'GET' ? $req->get($this->base . $path) : $req->patch($this->base . $path, $body);
        } catch (ConnectionException) {
            // A write that timed out may have been applied: the executor must reconcile.
            throw new ProviderError($method === 'GET' ? 'server_error' : 'unknown_outcome');
        }
        $ref = $r->header('cf-ray') ?: null;
        $j = $r->json() ?? [];
        if ($r->status() === 401 || $r->status() === 403) throw new ProviderError('unauthorized', $ref);
        if ($r->status() === 404) throw new ProviderError('not_found', $ref);
        if ($r->status() === 429) throw new ProviderError('rate_limited', $ref);
        if ($r->serverError()) throw new ProviderError($method === 'GET' ? 'server_error' : 'unknown_outcome', $ref);
        if (!$r->successful() || ($j['success'] ?? false) !== true) throw new ProviderError('rejected', $ref);
        return ['result' => $j['result'] ?? [], 'ref' => $ref];
    }

    private function record(array $r): array
    {
        $x = $r['result'];
        return ['id' => (string) ($x['id'] ?? ''), 'type' => (string) ($x['type'] ?? ''), 'name' => strtolower((string) ($x['name'] ?? '')),
            'content' => (string) ($x['content'] ?? ''), 'ttl' => (int) ($x['ttl'] ?? 0), 'proxied' => (bool) ($x['proxied'] ?? false), 'ref' => $r['ref']];
    }
}
