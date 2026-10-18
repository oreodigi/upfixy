<?php

namespace Tests\Fakes;

use App\Support\Net\Resolver;

final class FakeResolver implements Resolver
{
    /** @var array<string, string[]> */
    public array $a = [];
    /** @var array<string, string[]> */
    public array $txt = [];

    public function addresses(string $host): array
    {
        $h = trim($host, '[]');
        return filter_var($h, FILTER_VALIDATE_IP) ? [$h] : ($this->a[$host] ?? []);
    }

    public function txt(string $name): array
    {
        return $this->txt[$name] ?? [];
    }
}
