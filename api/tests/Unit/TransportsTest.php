<?php

namespace Tests\Unit;

use App\Monitoring\Dns\DnsMessage;
use App\Monitoring\Dns\DnsTimeout;
use App\Monitoring\Dns\UdpDnsClient;
use App\Monitoring\Http\CurlFetcher;
use App\Monitoring\Http\FetchError;
use App\Monitoring\Tls\StreamTlsInspector;
use App\Monitoring\Tls\TlsError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The real network code against local servers (no internet): DNS wire format
 * and anti-spoofing, curl pinning/redirect/byte/time budgets, TLS reading.
 */
class TransportsTest extends TestCase
{
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) { @proc_terminate($p); @proc_close($p); }
        $this->procs = [];
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $port;
    }

    private function start(array $cmd, ?int $waitTcpPort = null): void
    {
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->procs[] = $p;
        if ($waitTcpPort) {
            for ($i = 0; $i < 50; $i++) {
                if (@fsockopen('127.0.0.1', $waitTcpPort, $e, $es, 0.1)) return;
                usleep(100_000);
            }
            $this->fail('server did not start: ' . implode(' ', $cmd));
        }
        fgets($pipes[2]); // "ready"
    }

    private function dns(string $mode): array
    {
        $port = self::freePort();
        $this->start([PHP_BINARY, __DIR__ . '/../Fakes/servers/dns-server.php', (string) $port, $mode]);
        return [new UdpDnsClient(1.0, $port), $port];
    }

    // ----------------------------------------------------------------- DNS
    public function test_query_encoding_sets_rd_ad_and_edns_do(): void
    {
        $q = DnsMessage::query(0x1234, 'Shop.Example.', 'A', true);
        $h = unpack('nid/nflags/nqd/nan/nns/nar', substr($q, 0, 12));
        $this->assertSame([0x1234, 0x0130, 1, 1], [$h['id'], $h['flags'], $h['qd'], $h['ar']]);
        $this->assertStringContainsString("\x04shop\x07example\x00", $q, 'lower-cased labels');
        $this->assertStringEndsWith("\0" . pack('nnNn', 41, 1232, 0x8000, 0), $q);
    }

    public function test_answers_with_compression_and_cname_are_parsed(): void
    {
        [$c] = $this->dns('ok');
        $r = $c->query('127.0.0.1', 'shop.example', 'A');
        $this->assertSame('NOERROR', $r['rcode']);
        $this->assertTrue($r['ad']);
        $this->assertSame(['CNAME', 'A'], array_column($r['answers'], 'type'));
        $this->assertSame('edge.cdn.example', $r['answers'][0]['data']);
        $this->assertSame('93.184.216.34', $r['answers'][1]['data']);
    }

    public function test_nxdomain_rcode(): void
    {
        [$c] = $this->dns('nx');
        $this->assertSame('NXDOMAIN', $c->query('127.0.0.1', 'gone.example', 'A')['rcode']);
    }

    public function test_spoofed_answer_with_wrong_id_is_rejected(): void
    {
        [$c] = $this->dns('spoof');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        $c->query('127.0.0.1', 'shop.example', 'A');
    }

    public function test_truncated_udp_answer_retries_over_tcp(): void
    {
        [$c] = $this->dns('tc');
        $r = $c->query('127.0.0.1', 'shop.example', 'A');
        $this->assertSame('93.184.216.34', $r['answers'][1]['data']);
    }

    public function test_silent_resolver_is_a_timeout(): void
    {
        [$c] = $this->dns('silent');
        $this->expectException(DnsTimeout::class);
        $c->query('127.0.0.1', 'shop.example', 'A');
    }

    public function test_malformed_messages_are_rejected_not_half_read(): void
    {
        $base = pack('nnnnnn', 1, 0x8180, 1, 1, 0, 0) . "\x04shop\x07example\x00" . pack('nn', 1, 1);
        foreach ([
            'self pointer loop' => $base . "\xC0\x1D" . pack('nnNn', 1, 1, 60, 4) . "\x01\x02\x03\x04",
            'truncated rdata' => $base . "\xC0\x0C" . pack('nnNn', 1, 1, 60, 4) . "\x01\x02",
            'bad A length' => $base . "\xC0\x0C" . pack('nnNn', 1, 1, 60, 3) . "\x01\x02\x03",
            'not a response' => pack('nnnnnn', 1, 0x0100, 1, 0, 0, 0) . "\x01a\x00" . pack('nn', 1, 1),
        ] as $name => $msg) {
            try {
                DnsMessage::parse($msg);
                $this->fail("accepted: {$name}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(RuntimeException::class);
        new UdpDnsClient() && (new UdpDnsClient())->query('not-an-ip', 'a.example', 'A');
    }

    // ---------------------------------------------------------------- HTTP
    private function http(): int
    {
        $port = self::freePort();
        $this->start([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/../Fakes/servers/http-router.php'], $port);
        return $port;
    }

    public function test_connection_is_pinned_and_redirects_are_not_followed(): void
    {
        $port = $this->http();
        $f = new CurlFetcher(2, 3, 65536, 'UpfixyMonitor/test');
        // "pinned.test" does not resolve anywhere: success proves curl used the pinned address.
        $r = $f->fetch("http://pinned.test:{$port}/redirect", 'pinned.test', $port, '127.0.0.1');
        $this->assertSame([302, '/ok'], [$r['status'], $r['location']]);
        $ok = $f->fetch("http://pinned.test:{$port}/ok", 'pinned.test', $port, '127.0.0.1');
        $this->assertSame([200, 5], [$ok['status'], $ok['bytes']]);
        $this->assertStringStartsWith('text/html', $ok['content_type']);
    }

    public function test_body_is_capped_and_no_cookies_are_sent(): void
    {
        $port = $this->http();
        $f = new CurlFetcher(2, 5, 1000);
        $r = $f->fetch("http://127.0.0.1:{$port}/big", '127.0.0.1', $port, '127.0.0.1');
        $this->assertSame([200, 1000], [$r['status'], $r['bytes']]);
        // The server sets a cookie each time and answers 400 if one ever comes back.
        $this->assertSame(204, $f->fetch("http://127.0.0.1:{$port}/cookie", '127.0.0.1', $port, '127.0.0.1')['status']);
        $this->assertSame(204, $f->fetch("http://127.0.0.1:{$port}/cookie", '127.0.0.1', $port, '127.0.0.1')['status']);
    }

    public function test_timeouts_and_refused_connections_are_classified(): void
    {
        $port = $this->http();
        $f = new CurlFetcher(1, 1, 1000);
        try {
            $f->fetch("http://127.0.0.1:{$port}/slow", '127.0.0.1', $port, '127.0.0.1');
            $this->fail('no timeout');
        } catch (FetchError $e) {
            $this->assertSame('HTTP_TIMEOUT', $e->issue);
        }
        $closed = self::freePort();
        try {
            $f->fetch("http://127.0.0.1:{$closed}/", '127.0.0.1', $closed, '127.0.0.1');
            $this->fail('no refusal');
        } catch (FetchError $e) {
            $this->assertSame('HTTP_CONNECTION_REFUSED', $e->issue);
        }
    }

    // ----------------------------------------------------------------- TLS
    public function test_tls_reads_and_verifies_a_real_certificate(): void
    {
        $dir = sys_get_temp_dir() . '/upfixy-tls-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'tls.test'], $key, ['digest_alg' => 'sha256', 'req_extensions' => 'v3_req', 'config' => $this->opensslConf($dir)]);
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256', 'x509_extensions' => 'v3_req', 'config' => $this->opensslConf($dir)]);
        openssl_x509_export_to_file($cert, "{$dir}/cert.pem");
        openssl_pkey_export_to_file($key, "{$dir}/key.pem");
        $port = self::freePort();
        $this->start(['openssl', 's_server', '-accept', (string) $port, '-cert', "{$dir}/cert.pem", '-key', "{$dir}/key.pem", '-quiet'], $port);

        // Untrusted (self-signed): handshake still read so the cause can be explained.
        $r = (new StreamTlsInspector(3))->inspect('tls.test', '127.0.0.1', $port);
        $this->assertFalse($r['verified']);
        $this->assertSame(['tls.test', 'www.tls.test'], $r['cert']['sans']);
        $this->assertTrue($r['cert']['self_signed']);
        $this->assertGreaterThan(time() + 86400 * 29, $r['cert']['not_after']);

        // Trusted when its CA is known, and only for the right name.
        $ok = (new StreamTlsInspector(3, "{$dir}/cert.pem"))->inspect('tls.test', '127.0.0.1', $port);
        $this->assertTrue($ok['verified']);
        $wrong = (new StreamTlsInspector(3, "{$dir}/cert.pem"))->inspect('other.test', '127.0.0.1', $port);
        $this->assertFalse($wrong['verified']);

        $closed = self::freePort();
        try {
            (new StreamTlsInspector(1))->inspect('tls.test', '127.0.0.1', $closed);
            $this->fail('no error');
        } catch (TlsError $e) {
            $this->assertSame('TLS_CONNECTION_REFUSED', $e->issue);
        }
    }

    private function opensslConf(string $dir): string
    {
        $f = "{$dir}/openssl.cnf";
        if (!is_file($f)) {
            file_put_contents($f, "[req]\ndistinguished_name=dn\n[dn]\n[v3_req]\nsubjectAltName=DNS:tls.test,DNS:www.tls.test\nbasicConstraints=critical,CA:TRUE\nkeyUsage=digitalSignature,keyCertSign\n");
        }
        return $f;
    }
}
