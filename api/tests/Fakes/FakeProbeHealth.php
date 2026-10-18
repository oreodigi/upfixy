<?php

namespace Tests\Fakes;

use App\Monitoring\ProbeHealth;

final class FakeProbeHealth extends ProbeHealth
{
    public bool $ok = true;

    public function __construct() {}

    public function healthy(): bool
    {
        return $this->ok;
    }
}
