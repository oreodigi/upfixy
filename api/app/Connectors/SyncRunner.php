<?php

namespace App\Connectors;

use App\Repairs\SecretBox;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Connector worker (runs as upfixy_worker; reaches the database only through
 * worker_* functions). Opens each sealed token just for the call, then forgets it.
 * A failure here only affects this connector's data: monitoring never waits on it.
 */
final class SyncRunner
{
    public function __construct(private ConnectorHttp $http) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('pgsql_worker');
    }

    public function adapter(string $kind): Adapter
    {
        return match ($kind) {
            'cloudflare' => new CloudflareReadAdapter($this->http, (string) config('upfixy.connectors.cloudflare_base')),
            'whm_reseller' => new WhmResellerAdapter($this->http),
            'cpanel' => new CpanelAdapter($this->http),
        };
    }

    /** @return int connectors processed */
    public function runOnce(): int
    {
        $rows = $this->db()->select('SELECT * FROM worker_claim_connectors(?, ?)',
            [(int) config('upfixy.connectors.batch'), (int) config('upfixy.connectors.lease_seconds')]);
        foreach ($rows as $row) $this->process($row);
        return count($rows);
    }

    private function process(object $c): void
    {
        try {
            $token = SecretBox::open($c->secret_sealed, (string) config('upfixy.connectors.keypair'));
        } catch (Throwable) {
            $this->failed($c, 'config', 'The connector service cannot open this credential. An administrator must check the connector key.');
            return;
        }
        $cred = ['host' => $c->base_host, 'user' => $c->username, 'token' => $token];
        $adapter = $this->adapter($c->kind);
        try {
            if ($c->status === 'verifying') {
                $v = $adapter->verify($cred);
                $this->db()->select('SELECT worker_connector_verified(?, ?, true, ?, ?, ?, NULL)', [$c->id, $c->secret_version,
                    $this->pgArray($v['verified']), json_encode($v['scope']), $this->pgArray($v['warnings'])]);
            } else {
                $assets = $adapter->sync($cred);
                $this->db()->select('SELECT worker_connector_synced(?, ?, ?::jsonb)', [$c->id, $c->secret_version, json_encode(array_slice($assets, 0, 5000))]);
            }
        } catch (ScopeViolation $e) {
            $this->reject($c, $e->getMessage());
        } catch (ConnectorError $e) {
            // A bad credential at connect time is a rejection; later it is an outage the owner must fix.
            if ($c->status === 'verifying' && in_array($e->kind, ConnectorError::REJECTING, true)) $this->reject($c, $e->getMessage());
            else $this->failed($c, $e->kind, $e->getMessage());
        } catch (Throwable) {
            $this->failed($c, 'internal', 'The provider answer could not be processed.');
        } finally {
            unset($token, $cred);
        }
    }

    private function reject(object $c, string $reason): void
    {
        $this->db()->select('SELECT worker_connector_verified(?, ?, false, NULL, NULL, NULL, ?)', [$c->id, $c->secret_version, $reason]);
    }

    private function failed(object $c, string $kind, string $reason): void
    {
        $this->db()->select('SELECT worker_connector_failed(?, ?, ?, ?)', [$c->id, $c->secret_version, $kind, $reason]);
    }

    private function pgArray(array $v): string
    {
        return '{' . implode(',', array_map(fn ($s) => '"' . addcslashes((string) $s, '"\\') . '"', $v)) . '}';
    }
}
