<?php

namespace App\Monitoring\Dns;

use RuntimeException;

/**
 * UDP DNS with TCP retry on truncation. Each response must echo our random ID
 * and exact question, so an off-path spoofed answer is rejected.
 */
final class UdpDnsClient implements DnsClient
{
    public function __construct(private float $timeoutSeconds = 2.0, private int $port = 53) {}

    public function query(string $resolver, string $name, string $type, bool $checkingDisabled = false): array
    {
        if (!filter_var($resolver, FILTER_VALIDATE_IP)) throw new RuntimeException('resolver must be an IP address');
        $id = random_int(0, 0xFFFF);
        $q = DnsMessage::query($id, $name, $type, $checkingDisabled);
        $start = hrtime(true);
        $host = str_contains($resolver, ':') ? "[{$resolver}]" : $resolver;

        $resp = $this->exchange("udp://{$host}:{$this->port}", $q, false);
        $msg = DnsMessage::parse($resp);
        if ($msg['tc']) {
            $resp = $this->exchange("tcp://{$host}:{$this->port}", pack('n', strlen($q)) . $q, true);
            $msg = DnsMessage::parse($resp);
        }
        if ($msg['id'] !== $id || $msg['qname'] !== rtrim(strtolower($name), '.') || $msg['qtype'] !== DnsMessage::TYPES[$type]) {
            throw new RuntimeException('response does not match the question');
        }
        return ['rcode' => $msg['rcode'], 'ad' => $msg['ad'], 'answers' => $msg['answers'],
            'ms' => (int) ((hrtime(true) - $start) / 1e6)];
    }

    private function exchange(string $uri, string $payload, bool $tcp): string
    {
        $errno = 0; $err = '';
        $s = @stream_socket_client($uri, $errno, $err, $this->timeoutSeconds);
        if (!$s) throw new DnsTimeout("connect failed: {$err}");
        stream_set_timeout($s, (int) $this->timeoutSeconds, (int) (fmod($this->timeoutSeconds, 1) * 1e6));
        fwrite($s, $payload);
        if ($tcp) {
            $len = fread($s, 2);
            if ($len === false || strlen($len) < 2) { fclose($s); throw new DnsTimeout('no TCP answer'); }
            $need = unpack('n', $len)[1];
            $buf = '';
            while (strlen($buf) < $need && !feof($s)) {
                $chunk = fread($s, $need - strlen($buf));
                if ($chunk === false || $chunk === '') break;
                $buf .= $chunk;
            }
        } else {
            $buf = fread($s, 4096);
        }
        $meta = stream_get_meta_data($s);
        fclose($s);
        if ($meta['timed_out'] || $buf === false || $buf === '') throw new DnsTimeout('resolver did not answer');
        return $buf;
    }
}
