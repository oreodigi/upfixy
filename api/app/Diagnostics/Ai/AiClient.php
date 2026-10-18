<?php

namespace App\Diagnostics\Ai;

interface AiClient
{
    public function name(): string;

    public function model(): ?string;

    /**
     * Send the system prompt, the user payload and the output schema.
     * @return array{text: ?string, refusal: ?string, status: string, input_tokens: int, output_tokens: int}
     * @throws AiUnavailable on timeout, transport error, 5xx, 429 or an unusable response
     */
    public function analyse(string $system, array $payload, array $schema): array;
}
