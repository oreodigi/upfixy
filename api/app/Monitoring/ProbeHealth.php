<?php

namespace App\Monitoring;

use App\Monitoring\Dns\DnsClient;
use Throwable;

/**
 * Is OUR probe working? Before failures are blamed on a customer's site, the
 * worker checks that it can resolve well-known names through its resolvers.
 * If it cannot, every reading is recorded as a probe error, never as the
 * site being down (§17.1). Cached briefly so it costs a few queries a minute.
 */
class ProbeHealth
{
    private ?bool $cached = null;
    private int $checkedAt = 0;

    /** @param string[] $resolvers @param string[] $canaries */
    public function __construct(private DnsClient $dns, private array $resolvers, private array $canaries, private int $ttl = 60) {}

    public function healthy(): bool
    {
        if ($this->cached !== null && time() - $this->checkedAt < $this->ttl) return $this->cached;
        $this->checkedAt = time();
        foreach ($this->canaries as $name) {
            foreach ($this->resolvers as $r) {
                try {
                    $res = $this->dns->query($r, $name, 'A');
                    if ($res['rcode'] === 'NOERROR' && $res['answers'] !== []) return $this->cached = true;
                } catch (Throwable) {
                    // try the next resolver
                }
            }
        }
        return $this->cached = false;
    }
}
