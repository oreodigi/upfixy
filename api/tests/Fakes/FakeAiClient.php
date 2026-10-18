<?php

namespace Tests\Fakes;

use App\Diagnostics\Ai\AiClient;
use App\Diagnostics\Ai\AiUnavailable;

/** Scripted model. $respond receives the exact payload sent and returns the answer (array → JSON), a string, or throws. */
final class FakeAiClient implements AiClient
{
    public array $payloads = [];
    public array $systems = [];
    /** @var \Closure(array): mixed */
    public \Closure $respond;
    public ?string $refusal = null;

    public function __construct()
    {
        $this->respond = fn (array $p) => self::good($p);
    }

    public function name(): string { return 'fake'; }
    public function model(): ?string { return 'fake-model-1'; }

    public function analyse(string $system, array $payload, array $schema): array
    {
        $this->payloads[] = $payload;
        $this->systems[] = $system;
        $out = ($this->respond)($payload);
        if ($out instanceof \Throwable) throw $out;
        return ['text' => is_string($out) ? $out : json_encode($out), 'refusal' => $this->refusal, 'status' => 'completed',
            'input_tokens' => 1200, 'output_tokens' => 300];
    }

    /** A well-behaved answer citing the first evidence item. */
    public static function good(array $p, array $over = []): array
    {
        $e = $p['evidence'][0]['evidence_id'];
        $t = $p['evidence'][0]['target'];
        return array_replace_recursive([
            'summary' => "The website {$t} is answering with an error, so visitors cannot use it right now.",
            'findings' => [[
                'finding_id' => 'f1', 'evidence_ids' => [$e],
                'observed_fact' => "{$t} returned an error page on the last checks.",
                'hypotheses' => [['text' => 'The hosting server may be overloaded or misconfigured.', 'supporting_evidence_ids' => [$e],
                    'contradicting_evidence_ids' => [], 'confidence' => 'possible']],
                'recommended_actions' => [['text' => 'Ask your hosting provider to check the server error log for the time shown.', 'kind' => 'contact_provider', 'operation_id' => null]],
                'unknowns' => ['Why the server fails cannot be seen without a hosting connection.'],
                'human_review_required' => true,
            ]],
            'missing_information' => ['Hosting account access'],
        ], $over);
    }

    public static function down(): AiUnavailable
    {
        return new AiUnavailable('http 503');
    }
}
