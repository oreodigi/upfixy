<?php

namespace App\Connectors;

/**
 * INT-003 single cPanel account, UAPI token, read-only (EXT: confirm on a pilot account, FRD §7 S13).
 * A cPanel token can usually write; Upfixy enforces read-only by an allowlist of UAPI
 * functions and a GET-only transport (AC-INT-003.1).
 */
final class CpanelAdapter implements Adapter
{
    public const ALLOWED = ['DomainInfo/list_domains', 'SSL/installed_hosts'];

    public function __construct(private ConnectorHttp $http) {}

    public function verify(array $cred): array
    {
        $d = $this->call('DomainInfo/list_domains', $cred);
        if (Json::host($d['main_domain'] ?? '') === null) throw new ConnectorError('bad_response', 'cPanel did not report a main domain for this account.');
        $verified = ['domains.read'];
        $warnings = [];
        try {
            $this->call('SSL/installed_hosts', $cred);
            $verified[] = 'ssl.read';
        } catch (ConnectorError $e) {
            if (!in_array($e->kind, ['forbidden', 'bad_response'], true)) throw $e;
            $warnings[] = 'SSL details are not available to this token; certificate status comes from public checks only.';
        }
        return ['verified' => $verified, 'scope' => ['account' => $cred['user'], 'main_domain' => Json::host($d['main_domain'])], 'warnings' => $warnings];
    }

    public function sync(array $cred): array
    {
        $d = $this->call('DomainInfo/list_domains', $cred);
        $main = Json::host($d['main_domain'] ?? '');
        if ($main === null) throw new ConnectorError('bad_response', 'cPanel did not report a main domain for this account.');
        $ssl = [];
        $sslKnown = true;
        try {
            foreach ((array) $this->call('SSL/installed_hosts', $cred) as $h) {
                $c = (array) ($h['certificate'] ?? []);
                $info = ['not_after' => isset($c['not_after']) ? gmdate('c', (int) $c['not_after']) : null,
                    'self_signed' => (bool) ($c['is_self_signed'] ?? false),
                    'issuer' => Json::text($c['issuer.organizationName'] ?? null, 60)];
                foreach (array_merge([$h['servername'] ?? ''], (array) ($c['domains'] ?? [])) as $n) {
                    if ($n = Json::host($n)) $ssl[$n] = $info;
                }
            }
        } catch (ConnectorError $e) {
            if (!in_array($e->kind, ['forbidden', 'bad_response'], true)) throw $e;
            $sslKnown = false;
        }
        $types = ['main' => [$main], 'addon' => (array) ($d['addon_domains'] ?? []), 'parked' => (array) ($d['parked_domains'] ?? []),
            'sub' => (array) ($d['sub_domains'] ?? [])];
        $assets = [['kind' => 'account', 'external_id' => $cred['user'], 'name' => $main, 'parent' => null,
            'facts' => ['domains' => array_sum(array_map('count', $types))]]];
        $seen = [];
        foreach ($types as $type => $names) {
            foreach ($names as $n) {
                if (!($n = Json::host($n)) || isset($seen[$n])) continue;
                $seen[$n] = true;
                $assets[] = ['kind' => 'domain', 'external_id' => $n, 'name' => $n, 'parent' => $cred['user'],
                    'facts' => ['type' => $type, 'ssl' => $ssl[$n] ?? null, 'ssl_known' => $sslKnown]];
            }
        }
        return $assets;
    }

    private function call(string $fn, array $cred): mixed
    {
        if (!in_array($fn, self::ALLOWED, true)) throw new ConnectorError('blocked', 'This cPanel function is not on Upfixy\'s read-only list.');
        $j = Json::decode($this->http->get("https://{$cred['host']}:2083/execute/{$fn}", ['Authorization' => "cpanel {$cred['user']}:{$cred['token']}"]), 'cPanel');
        if ((int) ($j['status'] ?? 0) !== 1) {
            $err = strtolower(implode(' ', (array) ($j['errors'] ?? [])));
            throw new ConnectorError(str_contains($err, 'permission') || str_contains($err, 'access') ? 'forbidden' : 'bad_response',
                "cPanel refused the \"{$fn}\" call for this token.");
        }
        return $j['data'] ?? [];
    }
}
