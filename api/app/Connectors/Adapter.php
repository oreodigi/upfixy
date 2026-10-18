<?php

namespace App\Connectors;

/**
 * Adapter contract (FRD §17.3). Credentials arrive opened, are used for the
 * call and are never stored on the adapter.
 *
 * @phpstan-type Cred array{host: ?string, user: ?string, token: string}
 */
interface Adapter
{
    /** @return array{verified: string[], scope: array, warnings: string[]} @throws ConnectorError|ScopeViolation */
    public function verify(array $cred): array;

    /**
     * Re-proves scope, then reports assets.
     * @return list<array{kind: string, external_id: string, name: string, parent?: ?string, facts: array}>
     * @throws ConnectorError|ScopeViolation
     */
    public function sync(array $cred): array;
}
