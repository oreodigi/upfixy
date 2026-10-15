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
            'trace_id' => request()?->header('X-Request-Id'),
            'detail' => json_encode($detail),
        ];
        if ($outcome === 'denied') {
            self::$deferred[] = $row;
            return;
        }
        DB::table('audit_events')->insert($row);
    }

    /** Called by WithTenantContext after the request transaction has finished. */
    public static function flushDeferred(): void
    {
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
    }
}
