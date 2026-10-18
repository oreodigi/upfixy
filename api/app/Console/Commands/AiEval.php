<?php

namespace App\Console\Commands;

use App\Diagnostics\Ai\OpenAiResponsesClient;
use App\Diagnostics\Eval\Evaluator;
use Illuminate\Console\Command;

/**
 * Compare OpenAI models on the fixed synthetic case set. Costs real API money, so it needs a key AND
 * --confirm-spend. Production AI stays OFF regardless (UPFIXY_AI_MODEL unset); this command never
 * changes configuration.
 */
class AiEval extends Command
{
    protected $signature = 'upfixy:ai-eval {--models=gpt-6-luna,gpt-6.1-sol,gpt-6-astra} {--confirm-spend} {--out=}';
    protected $description = 'Evaluate candidate AI models on synthetic diagnostic cases';

    public function handle(Evaluator $eval): int
    {
        $key = (string) config('upfixy.ai.openai_key');
        if ($key === '') { $this->error('OPENAI_API_KEY is not set. Nothing was sent.'); return self::FAILURE; }
        if (!$this->option('confirm-spend')) { $this->error('This calls the OpenAI API and costs money. Re-run with --confirm-spend.'); return self::FAILURE; }
        config(['cache.default' => 'array']);
        $set = Evaluator::load();
        $prices = (array) config('upfixy.ai.price_table');
        $reports = [];
        foreach (array_filter(explode(',', (string) $this->option('models'))) as $model) {
            $p = $prices[$model] ?? null;
            if (!$p) { $this->warn("No price for {$model} in upfixy.ai.price_table; cost will show as 0."); $p = ['in' => 0, 'out' => 0]; }
            $this->info("Running {$model} on " . count($set['cases']) . ' cases…');
            $client = new OpenAiResponsesClient($key, $model, (string) config('upfixy.ai.base_url'), (int) config('upfixy.ai.timeout'), (int) config('upfixy.ai.max_output_tokens'));
            $reports[] = $eval->run($client, $set['cases'], $p);
        }
        $md = Evaluator::markdown($reports, $set['version']);
        $this->line($md);
        $out = $this->option('out') ?: storage_path('app/ai-eval-' . date('Ymd-His') . '.json');
        file_put_contents($out, json_encode(['cases' => $set['version'], 'reports' => $reports], JSON_PRETTY_PRINT));
        $this->info("Full results: {$out}");
        return self::SUCCESS;
    }
}
