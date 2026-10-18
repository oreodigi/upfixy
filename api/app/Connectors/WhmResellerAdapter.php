<?php

namespace App\Connectors;

/**
 * INT-002 WHM reseller-scoped, read-only. D-04: root and root-equivalent tokens are refused.
 * (EXT: response shapes to be confirmed on a pilot reseller test account, FRD §7 S12.)
 *   GET /json-api/myprivs?api.version=1           privileges; "all" = root-equivalent → refuse
 *   GET /json-api/listaccts?api.version=1&...      accounts; any account not owned by this reseller → refuse
 *   GET /json-api/fetch_ssl_vhosts?api.version=1   SSL per virtual host; any foreign owner → refuse
 * Scope is proven on EVERY sync, not only at connect time (§16 provider isolation).
 */
final class WhmResellerAdapter implements Adapter
{
    private const PAGE = 500;
    private const MAX_PAGES = 20;

    public function __construct(private ConnectorHttp $http) {}

    public function verify(array $cred): array
    {
        $accounts = $this->provenAccounts($cred);
        $verified = ['accounts.read', 'domains.read'];
        $warnings = [];
        try {
            $this->vhosts($cred, $accounts);
            $verified[] = 'ssl.read';
        } catch (ConnectorError $e) {
            if ($e instanceof ScopeViolation || !in_array($e->kind, ['forbidden', 'bad_response'], true)) throw $e;
            $warnings[] = 'SSL details are not available to this token; certificate status comes from public checks only.';
        }
        return ['verified' => $verified, 'scope' => ['reseller' => $cred['user'], 'accounts' => count($accounts)], 'warnings' => $warnings];
    }

    public function sync(array $cred): array
    {
        $accounts = $this->provenAccounts($cred);
        $assets = [];
        foreach ($accounts as $user => $a) {
            $assets[] = ['kind' => 'account', 'external_id' => $user, 'name' => $a['domain'], 'parent' => null, 'facts' => [
                'suspended' => (bool) $a['suspended'], 'suspend_reason' => $a['suspended'] ? Json::text($a['suspendreason'] ?? null) : null,
                'plan' => Json::text($a['plan'] ?? null, 60), 'disk_used' => Json::text($a['diskused'] ?? null, 20),
                'disk_limit' => Json::text($a['disklimit'] ?? null, 20)]];
        }
        try {
            $vhosts = $this->vhosts($cred, $accounts);
        } catch (ConnectorError $e) {
            if ($e instanceof ScopeViolation || !in_array($e->kind, ['forbidden', 'bad_response'], true)) throw $e;
            $vhosts = null;
        }
        $domains = [];
        foreach ($accounts as $user => $a) $domains[$a['domain']] = ['parent' => $user, 'ssl' => null];
        foreach ($vhosts ?? [] as $v) {
            foreach ($v['names'] as $n) $domains[$n] = ['parent' => $v['user'], 'ssl' => $v['ssl']];
        }
        foreach ($domains as $name => $d) {
            $assets[] = ['kind' => 'domain', 'external_id' => $name, 'name' => $name, 'parent' => $d['parent'],
                'facts' => ['ssl' => $d['ssl'], 'ssl_known' => $vhosts !== null]];
        }
        return $assets;
    }

    /** @return array<string, array> user => account; throws unless every account is this reseller's. */
    private function provenAccounts(array $cred): array
    {
        $p = $this->call('myprivs', [], $cred)['data']['privileges'] ?? null;
        $privs = is_array($p) && array_is_list($p) ? ($p[0] ?? []) : (array) $p;
        if ((int) ($privs['all'] ?? 0) === 1) {
            throw new ScopeViolation('This token has root-equivalent WHM privileges ("all"). Upfixy accepts reseller-scoped tokens only.');
        }
        if ((int) ($privs['list-accts'] ?? 0) !== 1) {
            throw new ScopeViolation('This token cannot list accounts (the reseller "list-accts" privilege is missing).');
        }
        $reseller = $cred['user'];
        $out = [];
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $j = $this->call('listaccts', ['api.chunk.enable' => 1, 'api.chunk.size' => self::PAGE, 'api.chunk.start' => $i * self::PAGE + 1], $cred);
            $rows = (array) ($j['data']['acct'] ?? []);
            foreach ($rows as $a) {
                $user = strtolower((string) ($a['user'] ?? ''));
                $owner = strtolower((string) ($a['owner'] ?? ''));
                if ($owner !== $reseller && $user !== $reseller) {
                    // Any account that is not this reseller's means the token's reach is wider than a reseller's.
                    throw new ScopeViolation('Integration scope could not be verified: the token can see accounts owned by someone else.');
                }
                $domain = Json::host($a['domain'] ?? '');
                if (!preg_match('/^[a-z][a-z0-9_.-]{0,31}$/', $user) || $domain === null) continue;
                $out[$user] = ['domain' => $domain, 'suspended' => (int) ($a['suspended'] ?? 0) === 1] + $a;
            }
            if (count($rows) < self::PAGE) break;
        }
        return $out;
    }

    /** @return list<array{user: string, names: string[], ssl: ?array}> */
    private function vhosts(array $cred, array $accounts): array
    {
        $out = [];
        foreach ((array) ($this->call('fetch_ssl_vhosts', [], $cred)['data']['vhosts'] ?? []) as $v) {
            $user = strtolower((string) ($v['user'] ?? $v['owner'] ?? ''));
            if (!isset($accounts[$user])) {
                throw new ScopeViolation('Integration scope could not be verified: the token can see websites of other accounts.');
            }
            $names = array_values(array_filter(array_map([Json::class, 'host'],
                array_merge([$v['servername'] ?? ''], (array) ($v['serveralias'] ?? []), (array) ($v['domains'] ?? [])))));
            $names = array_values(array_unique(array_filter($names, fn ($n) => !preg_match('/^(www|mail|cpanel|webmail|webdisk|autodiscover|cpcalendars|cpcontacts)\./', $n))));
            $c = (array) ($v['crt'] ?? []);
            $out[] = ['user' => $user, 'names' => $names, 'ssl' => $c === [] ? null : [
                'not_after' => isset($c['not_after']) ? gmdate('c', (int) $c['not_after']) : null,
                'self_signed' => (bool) ($c['is_self_signed'] ?? false),
                'issuer' => Json::text($c['issuer.organizationName'] ?? $c['issuer']['organizationName'] ?? null, 60),
            ]];
        }
        return $out;
    }

    private function call(string $fn, array $params, array $cred): array
    {
        $q = http_build_query(['api.version' => 1] + $params);
        $j = Json::decode($this->http->get("https://{$cred['host']}:2087/json-api/{$fn}?{$q}",
            ['Authorization' => "whm {$cred['user']}:{$cred['token']}"]), 'WHM');
        if ((int) ($j['metadata']['result'] ?? 0) !== 1) {
            $reason = strtolower((string) ($j['metadata']['reason'] ?? ''));
            throw new ConnectorError(str_contains($reason, 'access denied') || str_contains($reason, 'permission') ? 'forbidden' : 'bad_response',
                "WHM refused the \"{$fn}\" call for this token.");
        }
        return $j;
    }
}
