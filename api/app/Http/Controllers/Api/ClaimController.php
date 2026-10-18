<?php

namespace App\Http\Controllers\Api;

use App\Jobs\VerifyOwnershipClaim;
use App\Support\Audit;
use App\Support\TenantContext;
use App\Support\Net\OwnershipChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** AST-002 / D-07: DNS TXT and HTML-file ownership verification. */
class ClaimController
{
    public function index(string $website): JsonResponse
    {
        if (!DB::selectOne('SELECT 1 AS x FROM websites WHERE id = ?', [$website])) abort(404);
        $rows = DB::select(
            "SELECT c.id, c.host, c.method, c.nonce, c.status, c.verified_at, c.expires_at, c.challenge_expires_at,
                    c.last_checked_at, c.last_check_result, c.created_at,
                    (SELECT t.scheme FROM monitor_targets t WHERE t.website_id = c.website_id AND t.host = c.host ORDER BY (t.scheme = 'https') DESC LIMIT 1) AS scheme,
                    (SELECT t.port FROM monitor_targets t WHERE t.website_id = c.website_id AND t.host = c.host ORDER BY (t.scheme = 'https') DESC LIMIT 1) AS port
               FROM ownership_claims c WHERE c.website_id = ? ORDER BY c.created_at DESC", [$website]);
        return response()->json(['data' => array_map([$this, 'present'], $rows)]);
    }

    public function store(Request $request, string $website): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'method' => ['required', 'in:dns_txt,html_file'],
        ]);
        $c = DB::selectOne('SELECT * FROM create_ownership_challenge(?, ?, ?::claim_method)', [$website, strtolower($data['host']), $data['method']]);
        $row = DB::selectOne(
            "SELECT c.id, c.host, c.method, c.nonce, c.status, c.verified_at, c.expires_at, c.challenge_expires_at,
                    c.last_checked_at, c.last_check_result, t.scheme, t.port FROM ownership_claims c
               JOIN LATERAL (SELECT scheme, port FROM monitor_targets t WHERE t.website_id = c.website_id AND t.host = c.host
                             ORDER BY (t.scheme = 'https') DESC LIMIT 1) t ON true
              WHERE c.id = ?", [$c->id]);
        Audit::record('claim.challenge_issued', 'allowed', 'website', $website, ['host' => $c->host, 'method' => $c->method]);
        return response()->json($this->present($row), 201);
    }

    /** Queues a check by the verification worker; poll GET claims for the result. */
    public function check(string $claim): JsonResponse
    {
        $c = DB::selectOne('SELECT oc.id, oc.status, w.client_id FROM ownership_claims oc JOIN websites w ON w.id = oc.website_id WHERE oc.id = ?', [$claim]);
        if (!$c) abort(404);
        // Same people who may start a verification (FRD §6 "Verify ownership").
        $role = TenantContext::role();
        if (!in_array($role, ['owner', 'admin'], true)
            && !($role === 'client_owner' && DB::selectOne('SELECT app_is_assigned(?) AS ok', [$c->client_id])->ok)) {
            Audit::record('claim.check', 'denied', 'claim', $claim);
            abort(403, 'Only owners, admins and the client owner can verify ownership.');
        }
        if ($c->status !== 'challenge_issued') {
            return response()->json(['message' => "This check is already {$c->status}.", 'status' => $c->status], 409);
        }
        VerifyOwnershipClaim::dispatch($claim);
        return response()->json(['status' => 'checking'], 202);
    }

    public function revoke(string $claim): JsonResponse
    {
        DB::select('SELECT revoke_ownership_claim(?)', [$claim]);
        Audit::record('claim.revoked', 'allowed', 'claim', $claim);
        return response()->json(null, 204);
    }

    private function present(object $c): array
    {
        $value = 'upfixy-verify=' . $c->nonce;
        $port = (int) ($c->port ?? 443);
        $scheme = $c->scheme ?? 'https';
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $instructions = $c->method === 'dns_txt'
            ? ['type' => 'TXT', 'name' => OwnershipChecker::txtName($c->host), 'value' => $value]
            : ['url' => "{$scheme}://{$c->host}" . ($default ? '' : ":{$port}") . OwnershipChecker::filePath($c->id), 'contents' => $value];
        return [
            'id' => $c->id, 'host' => $c->host, 'method' => $c->method, 'status' => $c->status,
            'instructions' => $c->status === 'challenge_issued' ? $instructions : null,
            'challenge_expires_at' => $c->challenge_expires_at, 'verified_at' => $c->verified_at,
            'proof_expires_at' => $c->expires_at, 'last_checked_at' => $c->last_checked_at,
            'last_check_result' => $c->last_check_result,
        ];
    }
}
