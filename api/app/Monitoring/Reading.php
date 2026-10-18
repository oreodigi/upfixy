<?php

namespace App\Monitoring;

/**
 * One check's result for one cycle. severity drives health; outcome says
 * whose problem it is (§17.1): target_failure counts toward incidents,
 * probe_error / probe_blocked / inconclusive never do.
 */
final class Reading
{
    public function __construct(
        public readonly string $kind,      // dns | tls | http
        public readonly string $severity,  // ok | warning | critical | unknown | not_applicable
        public readonly string $outcome,   // ok | warning | target_failure | probe_error | probe_blocked | inconclusive
        public readonly string $code,      // issue-library code, e.g. HTTP_503
        public readonly array $detail = [],
        public readonly int $attempts = 1,
    ) {}

    public static function ok(string $kind, string $code, array $detail = []): self
    {
        return new self($kind, 'ok', 'ok', $code, $detail);
    }

    public static function fail(string $kind, string $code, array $detail = []): self
    {
        return new self($kind, 'critical', 'target_failure', $code, $detail);
    }

    public static function warn(string $kind, string $code, array $detail = []): self
    {
        return new self($kind, 'warning', 'warning', $code, $detail);
    }

    public static function unknown(string $kind, string $outcome, string $code, array $detail = []): self
    {
        return new self($kind, 'unknown', $outcome, $code, $detail);
    }

    public function withAttempts(int $n): self
    {
        return new self($this->kind, $this->severity, $this->outcome, $this->code, $this->detail, $n);
    }

    public function isTargetFailure(): bool
    {
        return $this->outcome === 'target_failure';
    }
}
