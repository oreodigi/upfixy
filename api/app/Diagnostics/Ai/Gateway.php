<?php

namespace App\Diagnostics\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * DX-006 AI safety gateway. Decides whether to call the model at all
 * (configured, budget, circuit breaker), builds the minimised payload, calls
 * the client, validates the answer and returns a record to store. Any failure
 * produces a status the report can explain ("AI unavailable — measured findings
 * only"); it never blocks or alters the deterministic findings.
 */
final class Gateway
{
    private const BREAKER_KEY = 'upfixy:ai:breaker';

    public function __construct(private ?AiClient $client, private array $cfg) {}

    public function enabled(): bool
    {
        return $this->client !== null && (bool) $this->client->model();
    }

    /** @return array{status:string, provider:string, model:?string, payload:?array, payload_hash:?string, output:?array, rejection_reasons:array, input_tokens:?int, output_tokens:?int, est_cost_micros:int, latency_ms:?int} */
    /**
     * @param ?string $overBudget result of overBudget(), which the caller must evaluate INSIDE the tenant
     *                            context (RLS hides ai_analyses otherwise and the cap would never apply)
     */
    public function analyse(array $snapshot, array $rules, ?string $overBudget): array
    {
        $base = ['provider' => $this->client?->name() ?? 'none', 'model' => $this->client?->model(), 'payload' => null, 'payload_hash' => null,
            'output' => null, 'rejection_reasons' => [], 'input_tokens' => null, 'output_tokens' => null, 'est_cost_micros' => 0, 'latency_ms' => null];
        if (!$this->enabled()) return ['status' => 'skipped', 'rejection_reasons' => ['AI is not configured']] + $base;
        if ($overBudget !== null) return ['status' => 'budget_exceeded', 'rejection_reasons' => [$overBudget]] + $base;
        if ((int) Cache::get(self::BREAKER_KEY, 0) >= $this->cfg['breaker_failures']) {
            return ['status' => 'unavailable', 'rejection_reasons' => ['AI temporarily paused after repeated failures']] + $base;
        }
        $builder = new PayloadBuilder();
        $payload = $builder->build($snapshot, $rules);
        if ($payload['evidence'] === []) return ['status' => 'skipped', 'rejection_reasons' => ['no fresh evidence to analyse']] + $base;
        $base['payload'] = $payload;
        $base['payload_hash'] = hash('sha256', json_encode($payload));

        $start = hrtime(true);
        try {
            $r = $this->client->analyse(Contract::systemPrompt(), $payload, Contract::schema());
        } catch (AiUnavailable $e) {
            Cache::put(self::BREAKER_KEY, (int) Cache::get(self::BREAKER_KEY, 0) + 1, $this->cfg['breaker_seconds']);
            return ['status' => 'unavailable', 'rejection_reasons' => [substr($e->getMessage(), 0, 120)],
                'latency_ms' => (int) ((hrtime(true) - $start) / 1e6)] + $base;
        }
        Cache::forget(self::BREAKER_KEY);
        $base['latency_ms'] = (int) ((hrtime(true) - $start) / 1e6);
        $base['input_tokens'] = $r['input_tokens'];
        $base['output_tokens'] = $r['output_tokens'];
        $base['est_cost_micros'] = (int) round($r['input_tokens'] * $this->cfg['price_in_per_mtok'] + $r['output_tokens'] * $this->cfg['price_out_per_mtok']);

        if ($r['refusal'] !== null) return ['status' => 'rejected', 'rejection_reasons' => ['model refused']] + $base;
        $decoded = json_decode((string) $r['text'], true);
        $v = (new Validator())->validate($decoded, $payload);
        if (!$v['ok']) return ['status' => 'rejected', 'rejection_reasons' => $v['errors']] + $base;
        return ['status' => 'succeeded', 'output' => $this->relabel($v['output'], $builder->aliases())] + $base;
    }

    /** AC-DX-006.2: per-workspace daily count and monthly spend caps. Only calls that reached the model count. */
    public function overBudget(string $ws): ?string
    {
        $row = DB::selectOne(
            "SELECT count(*) FILTER (WHERE created_at >= date_trunc('day', now()) AND status IN ('succeeded', 'rejected'))::int AS today,
                    coalesce(sum(est_cost_micros) FILTER (WHERE created_at >= date_trunc('month', now())), 0)::bigint AS month
               FROM ai_analyses WHERE workspace_id = ?", [$ws]);
        if ($row->today >= $this->cfg['daily_limit']) return 'daily AI analysis limit reached for this workspace';
        if ($row->month >= $this->cfg['monthly_cost_cap_micros']) return 'monthly AI spending cap reached for this workspace';
        return null;
    }

    /** Map site-A… back to real hostnames for display. Stored output is already safe to show. */
    private function relabel(array $out, array $aliases): array
    {
        array_walk_recursive($out, function (&$v) use ($aliases) {
            if (is_string($v)) $v = preg_replace_callback('/\bsite-[A-Z]\d*\b/', fn ($m) => $aliases[$m[0]] ?? $m[0], $v);
        });
        return $out;
    }
}
