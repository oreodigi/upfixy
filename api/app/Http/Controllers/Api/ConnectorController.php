<?php

namespace App\Http\Controllers\Api;

use App\Domain\UrlGuard;
use App\Repairs\SecretBox;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * INT-001…006 (SCR-09/10). The API never talks to a provider and can't read a
 * stored token: it seals what the owner pastes, the connector worker verifies
 * and syncs, and imports happen only for assets a person selects.
 */
class ConnectorController
{
    private const COLS = 'id, kind, label, base_host, username, secret_version, status, status_reason, declared_capabilities,
        verified_capabilities, resource_scope, warnings, sync_interval_seconds, next_sync_at, consecutive_failures, error_class,
        last_attempt_at, last_success_at, created_at, revoked_at';

    private function present(object $c): array
    {
        $arr = fn ($v) => is_string($v) ? array_values(array_filter(str_getcsv(trim($v, '{}')), fn ($x) => $x !== null && $x !== '')) : (array) $v;
        $overdue = $c->last_success_at === null || strtotime($c->last_success_at) < time() - 2 * (int) $c->sync_interval_seconds;
        return [
            'id' => $c->id, 'kind' => $c->kind, 'label' => $c->label, 'host' => $c->base_host, 'username' => $c->username,
            'status' => $c->status, 'status_reason' => $c->status_reason,
            'declared_capabilities' => $arr($c->declared_capabilities), 'verified_capabilities' => $arr($c->verified_capabilities),
            'scope' => json_decode($c->resource_scope, true), 'warnings' => $arr($c->warnings),
            'secret_version' => (int) $c->secret_version, 'last_success_at' => $c->last_success_at, 'last_attempt_at' => $c->last_attempt_at,
            'next_sync_at' => $c->next_sync_at, 'error_class' => $c->error_class, 'created_at' => $c->created_at, 'revoked_at' => $c->revoked_at,
            // Honest freshness: never "current" unless the last sync is recent and the connector is healthy.
            'stale' => $c->status !== 'active' || $overdue,
        ];
    }

    private function find(string $id): object
    {
        $c = DB::selectOne('SELECT ' . self::COLS . ' FROM connectors WHERE id = ?', [$id]);
        if (!$c) abort(404);
        return $c;
    }

    private function admin(): void
    {
        if (!in_array(TenantContext::role(), ['owner', 'admin'], true)) abort(403, 'Only owners and admins manage integrations.');
    }

    private function seal(string $token): string
    {
        $key = config('upfixy.connectors.public_key');
        if (!$key) abort(503, 'Integrations are not configured on this server yet.');
        return SecretBox::seal($token, $key);
    }

    public function index(): JsonResponse
    {
        $this->admin();
        return response()->json(['data' => array_map(fn ($c) => $this->present($c),
            DB::select('SELECT ' . self::COLS . ' FROM connectors ORDER BY revoked_at IS NOT NULL, created_at DESC'))]);
    }

    /** Step-up MFA (route). Token is sealed before it touches the database; never returned or logged. */
    public function store(Request $request): JsonResponse
    {
        $this->admin();
        $data = $request->validate([
            'kind' => ['required', 'in:cloudflare,whm_reseller,cpanel'],
            'label' => ['required', 'string', 'min:1', 'max:80'],
            'host' => ['exclude_if:kind,cloudflare', 'required', 'string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'username' => ['exclude_if:kind,cloudflare', 'required', 'string', 'regex:/^[a-z][a-z0-9_.-]{0,31}$/i'],
            'api_token' => ['required', 'string', 'min:16', 'max:300', 'regex:/^[\x21-\x7E]+$/'],
        ], ['host.regex' => 'Enter the panel hostname only, for example server1.example.com.',
            'username.regex' => 'Enter the account name only (letters, digits, dot, dash or underscore).']);
        $sealed = $this->seal($data['api_token']);
        unset($data['api_token']);
        try {
            $id = DB::selectOne('SELECT create_connector(?::connector_kind, ?, ?, ?, ?) AS id',
                [$data['kind'], $data['label'], $data['host'] ?? null, $data['username'] ?? null, $sealed])->id;
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'connectors_live')) abort(409, 'This provider account is already connected.');
            throw $e;
        }
        Audit::record('connector.created', 'allowed', 'connector', $id, ['kind' => $data['kind'], 'host' => $data['host'] ?? null]);
        return response()->json(['id' => $id, 'status' => 'verifying',
            'note' => 'Stored sealed. Upfixy now checks what this credential can see before anything is shown or imported.'], 202);
    }

    public function show(string $connector): JsonResponse
    {
        $this->admin();
        $c = $this->find($connector);
        $assets = DB::select(
            "SELECT a.id, a.kind, a.external_id, a.name, a.parent_external_id, a.facts, a.last_seen_at, a.gone_at, a.import_state,
                    a.review_reason, a.website_id,
                    m.website_id AS match_website_id, mw.display_name AS match_website, mw.client_id AS match_client_id, mc.name AS match_client
               FROM connector_assets a
               LEFT JOIN LATERAL (SELECT t.website_id FROM monitor_targets t WHERE t.host = a.name ORDER BY t.created_at LIMIT 1) m ON true
               LEFT JOIN websites mw ON mw.id = m.website_id
               LEFT JOIN clients mc ON mc.id = mw.client_id
              WHERE a.connector_id = ?
              ORDER BY a.gone_at IS NOT NULL, a.kind, a.name", [$connector]);
        foreach ($assets as $a) $a->facts = json_decode($a->facts, true);
        return response()->json(['connector' => $this->present($c), 'assets' => $assets]);
    }

    public function events(string $connector): JsonResponse
    {
        $this->admin();
        $this->find($connector);
        $rows = DB::select('SELECT kind, detail, at FROM connector_events WHERE connector_id = ? ORDER BY id DESC LIMIT 100', [$connector]);
        foreach ($rows as $r) $r->detail = json_decode($r->detail, true);
        return response()->json(['data' => $rows]);
    }

    public function rotate(Request $request, string $connector): JsonResponse
    {
        $this->admin();
        $data = $request->validate(['api_token' => ['required', 'string', 'min:16', 'max:300', 'regex:/^[\x21-\x7E]+$/']]);
        $this->find($connector);   // 404 for anything not visible, before any other answer
        $sealed = $this->seal($data['api_token']);
        unset($data);
        try {
            $v = DB::selectOne('SELECT rotate_connector(?, ?) AS v', [$connector, $sealed])->v;
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'not found')) abort(404);
            throw $e;
        }
        Audit::record('connector.rotated', 'allowed', 'connector', $connector, ['version' => $v]);
        return response()->json(['secret_version' => $v, 'status' => 'verifying']);
    }

    public function revoke(string $connector): JsonResponse
    {
        $this->admin();
        try {
            DB::select('SELECT revoke_connector(?)', [$connector]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'not found')) abort(404);
            throw $e;
        }
        Audit::record('connector.revoked', 'allowed', 'connector', $connector);
        return response()->json(['status' => 'revoked',
            'note' => 'The credential is destroyed. Provider details already shown are now marked stale; public monitoring continues.']);
    }

    /**
     * INT-006 selective import. Only the assets the person ticked; a domain name alone never
     * claims anything (no ownership is granted). An asset whose website already belongs to a
     * different client is flagged "needs review" and not linked (AC-INT-006.2).
     */
    public function import(Request $request, string $connector): JsonResponse
    {
        $this->admin();
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1', 'max:200'],
            'asset_ids.*' => ['uuid', 'distinct'],
            'client_id' => ['nullable', 'uuid'],
        ]);
        $c = $this->find($connector);
        if ($c->status !== 'active') abort(409, 'Import is available once the connection is verified and active.');
        if ($data['client_id'] ?? null) {
            if (!DB::selectOne('SELECT 1 AS x FROM clients WHERE id = ?', [$data['client_id']])) abort(422, 'Choose one of your clients.');
        }
        $assets = DB::select('SELECT id, kind, name, import_state, gone_at FROM connector_assets WHERE connector_id = ? AND id = ANY(?::uuid[])',
            [$connector, '{' . implode(',', $data['asset_ids']) . '}']);
        if (count($assets) !== count($data['asset_ids'])) abort(404, 'Some selected items are not part of this connection.');

        $out = ['imported' => [], 'linked' => [], 'needs_review' => [], 'skipped' => []];
        foreach ($assets as $a) {
            if ($a->import_state === 'imported' || $a->gone_at !== null) {
                $out['skipped'][] = ['id' => $a->id, 'name' => $a->name, 'reason' => $a->gone_at ? 'No longer reported by the provider' : 'Already imported'];
                continue;
            }
            $existing = DB::selectOne('SELECT w.id, w.client_id FROM monitor_targets t JOIN websites w ON w.id = t.website_id
                                        WHERE t.host = ? ORDER BY t.created_at LIMIT 1', [$a->name]);
            $client = $data['client_id'] ?? null;
            if ($existing) {
                if ($client !== null && $existing->client_id !== null && $existing->client_id !== $client) {
                    DB::update("UPDATE connector_assets SET import_state = 'needs_review', review_reason = ? WHERE id = ?",
                        ['The website already belongs to a different client.', $a->id]);
                    $out['needs_review'][] = ['id' => $a->id, 'name' => $a->name, 'reason' => 'The website already belongs to a different client.'];
                    continue;
                }
                DB::update("UPDATE connector_assets SET import_state = 'imported', review_reason = NULL, website_id = ? WHERE id = ?", [$existing->id, $a->id]);
                $out['linked'][] = ['id' => $a->id, 'name' => $a->name, 'website_id' => $existing->id];
                continue;
            }
            $r = UrlGuard::normalize("https://{$a->name}/");
            if (!$r['ok']) {
                DB::update("UPDATE connector_assets SET import_state = 'needs_review', review_reason = ? WHERE id = ?", [$r['message'], $a->id]);
                $out['needs_review'][] = ['id' => $a->id, 'name' => $a->name, 'reason' => $r['message']];
                continue;
            }
            $t = $r['target'];
            $wid = DB::selectOne('INSERT INTO websites(workspace_id, client_id, display_name) VALUES (?, ?, ?) RETURNING id',
                [TenantContext::workspaceId(), $client, $t['host']])->id;
            DB::insert('INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, path) VALUES (?, ?, ?, ?, ?, ?)',
                [TenantContext::workspaceId(), $wid, $t['scheme'], $t['host'], $t['port'], $t['path']]);
            DB::update("UPDATE connector_assets SET import_state = 'imported', review_reason = NULL, website_id = ? WHERE id = ?", [$wid, $a->id]);
            $out['imported'][] = ['id' => $a->id, 'name' => $a->name, 'website_id' => $wid];
        }
        Audit::record('connector.import', 'allowed', 'connector', $connector, [
            'imported' => count($out['imported']), 'linked' => count($out['linked']), 'needs_review' => count($out['needs_review'])]);
        return response()->json($out);
    }

    public function ignore(Request $request, string $connector): JsonResponse
    {
        $this->admin();
        $data = $request->validate(['asset_ids' => ['required', 'array', 'min:1', 'max:500'], 'asset_ids.*' => ['uuid']]);
        $this->find($connector);
        $n = DB::update("UPDATE connector_assets SET import_state = 'ignored', review_reason = NULL
                          WHERE connector_id = ? AND id = ANY(?::uuid[]) AND import_state <> 'imported'",
            [$connector, '{' . implode(',', $data['asset_ids']) . '}']);
        return response()->json(['ignored' => $n]);
    }

    /** AST-004: provider relationships of one website (facts need ownership proof, D-07). */
    public function forWebsite(string $website): JsonResponse
    {
        if (!DB::selectOne('SELECT 1 AS x FROM websites WHERE id = ?', [$website])) abort(404);
        $rows = DB::select('SELECT * FROM website_provider_links(?)', [$website]);
        foreach ($rows as $r) $r->facts = $r->facts === null ? null : json_decode($r->facts, true);
        return response()->json(['data' => $rows]);
    }
}
