<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Per-transaction request context read by the database RLS policies
 * (db/migrations/0004_rls_policies.sql). set_config(..., true) is
 * transaction-local, so it can never leak into another request.
 */
final class TenantContext
{
    private static ?string $userId = null;
    private static ?string $workspaceId = null;

    public static function apply(?string $userId, ?string $workspaceId): void
    {
        self::$userId = $userId;
        self::$workspaceId = $workspaceId;
        DB::select("SELECT set_config('app.user_id', ?, true), set_config('app.workspace_id', ?, true)",
            [$userId ?? '', $workspaceId ?? '']);
    }

    public static function clear(): void
    {
        self::$userId = null;
        self::$workspaceId = null;
    }

    public static function userId(): ?string { return self::$userId; }
    public static function workspaceId(): ?string { return self::$workspaceId; }

    /** Caller's role in the current workspace, or null if not an active member. */
    public static function role(): ?string
    {
        return DB::selectOne('SELECT app_role()::text AS r')->r ?? null;
    }
}
