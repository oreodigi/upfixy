<?php

namespace App\Connectors;

/**
 * INT-004 Cloudflare zone-scoped, read-only (EXT: verify against a test account, FRD §7 S14).
 *   GET /user/tokens/verify
 *   GET /zones?per_page=50&page=N
 *   GET /zones/{id}/dns_records?per_page=100&page=N
 *   GET /zones/{id}/dnssec
 * Upfixy cannot see a token's permission list, so it cannot prove a token is read-only;
 * it only ever reads (GET-only transport). The DNS-edit credential used by repairs (M7) is
 * a separate connection held by the repair executor.
 */
final class CloudflareReadAdapter implements Adapter
{
    private const MAX_ZONE_PAGES = 20;
    private const MAX_RECORD_PAGES = 10;

    public function __construct(private ConnectorHttp $http, private string $base = 'https://api.cloudflare.com/client/v4') {}

    public function verify(array $cred): array
    {
        $v = $this->call('/user/tokens/verify', $cred);
        if (($v['result']['status'] ?? null) !== 'active') {
            throw new ConnectorError('unauthorized', 'Cloudflare reports this token is not active.');
        }
        $zones = $this->zones($cred);
        if ($zones === []) throw new ScopeViolation('This token cannot see any zone. Give it Zone:Read and DNS:Read on the zones you want monitored.');
        $verified = ['zones.read'];
        $warnings = [];
        $dnsOk = true;
        foreach ($zones as $z) {
            try {
                $this->call("/zones/{$z['id']}/dns_records?per_page=1", $cred);
            } catch (ConnectorError $e) {
                if ($e->kind !== 'forbidden' && $e->kind !== 'unauthorized') throw $e;
                $dnsOk = false;
            }
        }
        if ($dnsOk) $verified[] = 'dns.read';
        else $warnings[] = 'DNS records are not readable for every zone this token can see.';
        try {
            $this->call("/zones/{$zones[0]['id']}/dnssec", $cred);
            $verified[] = 'dnssec.read';
        } catch (ConnectorError $e) {
            if (!in_array($e->kind, ['forbidden', 'unauthorized'], true)) throw $e;
        }
        if (count($zones) > 1) {
            $warnings[] = sprintf('This token can see %d zones. Upfixy only reads, but a token limited to the zones you need is safer.', count($zones));
        }
        return ['verified' => $verified, 'scope' => ['zones' => count($zones)], 'warnings' => $warnings];
    }

    public function sync(array $cred): array
    {
        $assets = [];
        foreach ($this->zones($cred) as $z) {
            $facts = ['status' => Json::text($z['status'] ?? null, 20), 'paused' => (bool) ($z['paused'] ?? false),
                'name_servers' => array_values(array_filter(array_map([Json::class, 'host'], (array) ($z['name_servers'] ?? [])))),
                'original_name_servers' => array_values(array_filter(array_map([Json::class, 'host'], (array) ($z['original_name_servers'] ?? []))))];
            try {
                $facts['records'] = $this->records($cred, $z['id'], $z['name']);
            } catch (ConnectorError $e) {
                if (!in_array($e->kind, ['forbidden', 'unauthorized'], true)) throw $e;
                $facts['records'] = null; // unknown, never "empty"
            }
            try {
                $d = $this->call("/zones/{$z['id']}/dnssec", $cred);
                $facts['dnssec'] = Json::text($d['result']['status'] ?? null, 20);
            } catch (ConnectorError $e) {
                if (!in_array($e->kind, ['forbidden', 'unauthorized'], true)) throw $e;
                $facts['dnssec'] = null;
            }
            $assets[] = ['kind' => 'zone', 'external_id' => $z['id'], 'name' => $z['name'], 'parent' => null, 'facts' => $facts];
        }
        return $assets;
    }

    /** @return list<array{id: string, name: string}> */
    private function zones(array $cred): array
    {
        $out = [];
        for ($page = 1; $page <= self::MAX_ZONE_PAGES; $page++) {
            $j = $this->call("/zones?per_page=50&page={$page}", $cred);
            foreach ((array) ($j['result'] ?? []) as $z) {
                $id = (string) ($z['id'] ?? '');
                $name = Json::host($z['name'] ?? '');
                if (!preg_match('/^[0-9a-f]{32}$/', $id) || $name === null) continue; // never put provider data into a URL unchecked
                $out[$id] = ['id' => $id, 'name' => $name] + $z;
            }
            if ($page >= (int) ($j['result_info']['total_pages'] ?? 1)) break;
        }
        return array_values($out);
    }

    /** Summary of the zone's records: counts by type and the apex/www address records. */
    private function records(array $cred, string $zoneId, string $zone): array
    {
        $count = [];
        $apex = [];
        $www = [];
        for ($page = 1; $page <= self::MAX_RECORD_PAGES; $page++) {
            $j = $this->call("/zones/{$zoneId}/dns_records?per_page=100&page={$page}", $cred);
            foreach ((array) ($j['result'] ?? []) as $r) {
                $type = strtoupper((string) ($r['type'] ?? ''));
                if (!preg_match('/^[A-Z]{1,10}$/', $type)) continue;
                $count[$type] = ($count[$type] ?? 0) + 1;
                $name = Json::host($r['name'] ?? '');
                if (!in_array($type, ['A', 'AAAA', 'CNAME'], true) || $name === null) continue;
                $row = ['type' => $type, 'content' => Json::text($r['content'] ?? null, 253), 'proxied' => (bool) ($r['proxied'] ?? false)];
                if ($name === $zone) $apex[] = $row;
                if ($name === "www.{$zone}") $www[] = $row;
            }
            if ($page >= (int) ($j['result_info']['total_pages'] ?? 1)) break;
        }
        ksort($count);
        return ['count' => $count, 'apex' => array_slice($apex, 0, 10), 'www' => array_slice($www, 0, 10)];
    }

    private function call(string $path, array $cred): array
    {
        $j = Json::decode($this->http->get($this->base . $path, ['Authorization' => 'Bearer ' . $cred['token']]), 'Cloudflare');
        if (($j['success'] ?? false) !== true) throw new ConnectorError('bad_response', 'Cloudflare reported the request as unsuccessful.');
        return $j;
    }
}
