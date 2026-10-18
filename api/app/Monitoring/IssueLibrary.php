<?php

namespace App\Monitoring;

/** Reads packages/core/issues/issues.json, the same versioned library the web app renders. */
final class IssueLibrary
{
    private static ?array $data = null;

    public static function all(): array
    {
        return self::$data ??= json_decode(file_get_contents(base_path('../packages/core/issues/issues.json')), true);
    }

    public static function version(): string
    {
        return self::all()['version'];
    }

    public static function get(string $code): ?array
    {
        return self::all()['issues'][$code] ?? null;
    }

    public static function title(string $code): string
    {
        return self::get($code)['title'] ?? $code;
    }
}
