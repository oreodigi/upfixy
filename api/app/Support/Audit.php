<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Append-only audit trail (ACC-007).
 * Allowed events are written inside the request transaction, so they commit
 * or roll back with the change they describe. Denied events are buffered and
 * written afterwards in their own transaction, because a denial usually ends
 * the request with an error that rolls the request transaction back.
 */
final class Audit
{
    /** @var array<int, array<string, mixed>> */
    private static array $deferred = [];

    public static function record(string $action, string $outcome, ?string $targetType = null, ?string $targetId = null, array $detail = []): void
    {
        $row = [
            'workspace_id' => TenantContext::workspaceId(),
            'actor_id' => TenantContext::userId(),
            'action' => $action,
            'outcome' => $outcome,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => request()?->ip(),
            // Server-generated per request; a client-sent X-Request-Id is kept only as a hint in detail.
            'trace_id' => self::traceId(),
            'detail' => json_encode(self::clientTrace() + $detail),
        ];
        if ($outcome === 'denied') {
            self::$deferred[] = $row;
            return;
        }
        DB::table('audit_events')->insert($row);
    }

    /** @var array<int, array{0: ?string, 1: \Closure}> work that must survive the request's rollback */
    private static array $after = [];

    public static function traceId(): ?string
    {
        $r = request();
        if (!$r) return null;
        if (!$r->attributes->has('upfixy_trace')) $r->attributes->set('upfixy_trace', (string) \Illuminate\Support\Str::uuid());
        return $r->attributes->get('upfixy_trace');
    }

    private static function clientTrace(): array
    {
        $h = request()?->header('X-Request-Id');
        return is_string($h) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $h) ? ['client_request_id' => $h] : [];
    }

    /**
     * Run $fn after the request transaction ends, in its own transaction, as $userId (no workspace).
     * Used for security counters (failed MFA codes) that must count even though the request fails.
     */
    public static function afterRequest(?string $userId, \Closure $fn): void
    {
        self::$after[] = [$userId, $fn];
    }

    /** Called by WithTenantContext after the request transaction has finished. */
    public static function flushDeferred(): void
    {
        $after = self::$after;
        self::$after = [];
        foreach ($after as [$uid, $fn]) {
            DB::transaction(function () use ($uid, $fn) {
                TenantContext::apply($uid, null);
                $fn();
            });
        }
        $rows = self::$deferred;
        self::$deferred = [];
        foreach ($rows as $row) {
            DB::transaction(function () use ($row) {
                TenantContext::apply($row['actor_id'], null);
                // Attribute to a workspace only if the actor belongs to it (never the probed tenant).
                if ($row['workspace_id'] && !DB::selectOne('SELECT app_is_member(?) AS m', [$row['workspace_id']])->m) {
                    $row['workspace_id'] = null;
                }
                TenantContext::apply($row['actor_id'], $row['workspace_id']);
                DB::table('audit_events')->insert($row);
            });
        }
    }

    public static function discardDeferred(): void
    {
        self::$deferred = [];
        self::$after = [];
    }
}
