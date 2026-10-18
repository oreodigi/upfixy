<?php
// Test-only DNS responder on 127.0.0.1:<port>. Modes: ok | nx | spoof | tc | silent. Answers one query, then exits.
[$_, $port, $mode] = $argv;
$udp = stream_socket_server("udp://127.0.0.1:{$port}", $e, $es, STREAM_SERVER_BIND);
$tcp = $mode === 'tc' ? stream_socket_server("tcp://127.0.0.1:{$port}", $e, $es) : null;
file_put_contents("php://stderr", "ready\n");
$q = stream_socket_recvfrom($udp, 512, 0, $peer);
if ($mode === 'silent') { sleep(3); exit; }

function answer(string $q, string $mode, bool $truncated = false): string {
    $id = unpack('n', substr($q, 0, 2))[1];
    if ($mode === 'spoof') $id = ($id + 1) & 0xFFFF;
    $qEnd = 12; while (ord($q[$qEnd]) !== 0) $qEnd += 1 + ord($q[$qEnd]); $qEnd += 5;
    $question = substr($q, 12, $qEnd - 12);
    $rcode = $mode === 'nx' ? 3 : 0;
    $flags = 0x8180 | 0x0020 | $rcode | ($truncated ? 0x0200 : 0);
    $an = '';
    if (!$truncated && $mode !== 'nx') {
        // CNAME shop.example -> edge.cdn.example (compressed), then A for the CNAME target.
        $target = "\x04edge\x03cdn\x07example\x00";
        $an .= "\xC0\x0C" . pack('nnNn', 5, 1, 60, strlen($target)) . $target;
        $an .= "\xC0\x0C" . pack('nnNn', 1, 1, 60, 4) . inet_pton('93.184.216.34');
        $count = 2;
    } else { $count = 0; }
    return pack('nnnnnn', $id, $flags, 1, $count, 0, 0) . $question . $an;
}
if ($mode === 'tc') {
    stream_socket_sendto($udp, answer($q, 'ok', true), 0, $peer);
    $c = stream_socket_accept($tcp, 3);
    $len = unpack('n', fread($c, 2))[1];
    $tq = fread($c, $len);
    $r = answer($tq, 'ok');
    fwrite($c, pack('n', strlen($r)) . $r);
    fclose($c);
    exit;
}
stream_socket_sendto($udp, answer($q, $mode), 0, $peer);
