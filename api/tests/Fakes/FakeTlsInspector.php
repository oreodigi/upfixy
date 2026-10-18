<?php

namespace Tests\Fakes;

use App\Monitoring\Tls\TlsError;
use App\Monitoring\Tls\TlsInspector;

final class FakeTlsInspector implements TlsInspector
{
    /** @var array<string, mixed> host => result array | error code string */
    public array $hosts = [];

    public static function cert(array $over = []): array
    {
        return array_merge([
            'subject_cn' => 'shop.example', 'issuer_cn' => 'Test CA', 'sans' => ['shop.example', 'www.shop.example'],
            'not_before' => time() - 86400 * 30, 'not_after' => time() + 86400 * 60, 'sha256' => str_repeat('ab', 32), 'self_signed' => false,
        ], $over);
    }

    public function inspect(string $host, string $ip, int $port): array
    {
        $v = $this->hosts[$host] ?? ['verified' => true, 'cert' => self::cert(['subject_cn' => $host, 'sans' => [$host]])];
        if (is_string($v)) throw new TlsError($v);
        return $v + ['verify_error' => null, 'chain_length' => 2, 'protocol' => 'TLSv1.3', 'ms' => 30];
    }
}
