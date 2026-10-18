<?php

namespace App\Monitoring;

/**
 * Runs one cycle for one target: DNS, then TLS (https only), then HTTP.
 * A failing check is retried inside the cycle (bounded, with backoff); the
 * cycle still produces exactly one reading per check (AC-MON-005.3).
 */
final class CycleRunner
{
    /** @param int[] $retryDelaysMs */
    public function __construct(
        private DnsProbe $dns,
        private TlsProbe $tls,
        private HttpProbe $http,
        private ProbeHealth $health,
        private array $retryDelaysMs = [2000, 5000],
        private ?\Closure $sleep = null,
    ) {}

    /** @return array{readings: Reading[], state: string, reason: ?string} */
    public function run(object $t): array
    {
        $kinds = $t->scheme === 'https' ? ['dns', 'tls', 'http'] : ['dns', 'http'];
        if (!$this->health->healthy()) {
            return ['readings' => array_map(fn ($k) => Reading::unknown($k, 'probe_error', 'PROBE_UNAVAILABLE'), $kinds),
                'state' => $t->state, 'reason' => null];
        }

        $addresses = [];
        $dns = $this->retry(function () use ($t, &$addresses) {
            [$r, $addresses] = $this->dns->probe($t->host);
            return $r;
        });
        $readings = [$dns];
        $usable = in_array($dns->code, ['DNS_OK', 'DNS_NOT_APPLICABLE'], true);

        foreach (array_slice($kinds, 1) as $k) {
            if (!$usable) {
                // Not checked because DNS gave no safe address. Unknown, never "down" or "fine".
                $readings[] = Reading::unknown($k, 'inconclusive', strtoupper($k) . '_NOT_CHECKED', ['reason' => $dns->code]);
                continue;
            }
            $readings[] = $k === 'tls'
                ? $this->retry(fn () => $this->tls->probe($t->host, $t->port, $addresses))
                : $this->retry(fn () => $this->http->probe($t->scheme, $t->host, $t->port, $t->path, $addresses));
        }

        // Validation (§14.1): a pending target becomes active once it resolves to
        // public addresses; one that resolves to a private address is rejected.
        $state = $t->state;
        $reason = null;
        if ($t->state === 'pending_validation') {
            if ($usable) $state = 'active';
            elseif ($dns->code === 'DNS_PRIVATE_ADDRESS') { $state = 'rejected'; $reason = 'resolves to a private or reserved address'; }
        }
        return ['readings' => $readings, 'state' => $state, 'reason' => $reason];
    }

    private function retry(callable $fn): Reading
    {
        $attempt = 1;
        $r = $fn();
        foreach ($this->retryDelaysMs as $ms) {
            if (!$r->isTargetFailure()) break;
            $this->sleep ? ($this->sleep)($ms) : usleep($ms * 1000);
            $r = $fn();
            $attempt++;
        }
        return $r->withAttempts($attempt);
    }
}
