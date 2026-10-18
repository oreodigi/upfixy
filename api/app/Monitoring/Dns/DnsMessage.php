<?php

namespace App\Monitoring\Dns;

use RuntimeException;

/**
 * Minimal DNS wire format (RFC 1035, EDNS0 RFC 6891) for monitoring queries:
 * one question, recursion desired, DO bit set so validating resolvers report
 * DNSSEC status through the AD flag. Parsing is strict and bounded: a
 * malformed or oversized answer raises instead of being half-read.
 */
final class DnsMessage
{
    public const TYPES = ['A' => 1, 'NS' => 2, 'CNAME' => 5, 'SOA' => 6, 'MX' => 15, 'TXT' => 16, 'AAAA' => 28, 'CAA' => 257];
    public const RCODES = [0 => 'NOERROR', 1 => 'FORMERR', 2 => 'SERVFAIL', 3 => 'NXDOMAIN', 4 => 'NOTIMP', 5 => 'REFUSED'];

    public static function query(int $id, string $name, string $type, bool $checkingDisabled = false): string
    {
        if (!isset(self::TYPES[$type])) throw new RuntimeException("unsupported type {$type}");
        $flags = 0x0100 | 0x0020 | ($checkingDisabled ? 0x0010 : 0); // RD, AD, CD
        $out = pack('nnnnnn', $id, $flags, 1, 0, 0, 1);
        $out .= self::encodeName($name) . pack('nn', self::TYPES[$type], 1);
        // OPT pseudo-record: UDP payload 1232, DO bit.
        $out .= "\0" . pack('nnNn', 41, 1232, 0x00008000, 0);
        return $out;
    }

    public static function encodeName(string $name): string
    {
        $name = rtrim(strtolower($name), '.');
        if ($name === '' || strlen($name) > 253) throw new RuntimeException('invalid name');
        $out = '';
        foreach (explode('.', $name) as $label) {
            if ($label === '' || strlen($label) > 63) throw new RuntimeException('invalid label');
            $out .= chr(strlen($label)) . $label;
        }
        return $out . "\0";
    }

    /**
     * @return array{id:int, rcode:string, tc:bool, ad:bool, qname:string, qtype:int, answers:array<int, array{type:string, ttl:int, data:mixed}>}
     */
    public static function parse(string $msg): array
    {
        if (strlen($msg) < 12) throw new RuntimeException('short DNS message');
        $h = unpack('nid/nflags/nqd/nan/nns/nar', substr($msg, 0, 12));
        if (($h['flags'] & 0x8000) === 0) throw new RuntimeException('not a response');
        if ($h['qd'] !== 1) throw new RuntimeException('unexpected question count');
        if ($h['an'] > 200) throw new RuntimeException('too many answers');
        $off = 12;
        $qname = self::readName($msg, $off);
        $q = self::u16($msg, $off, 4);
        $qtype = ($q >> 16) & 0xFFFF;
        $off += 4;
        $answers = [];
        $names = array_flip(self::TYPES);
        for ($i = 0; $i < $h['an']; $i++) {
            self::readName($msg, $off);
            if ($off + 10 > strlen($msg)) throw new RuntimeException('truncated record');
            $r = unpack('ntype/nclass/Nttl/nlen', substr($msg, $off, 10));
            $off += 10;
            if ($off + $r['len'] > strlen($msg)) throw new RuntimeException('truncated rdata');
            $type = $names[$r['type']] ?? (string) $r['type'];
            $answers[] = ['type' => $type, 'ttl' => $r['ttl'], 'data' => self::rdata($msg, $off, $r['type'], $r['len'])];
            $off += $r['len'];
        }
        return [
            'id' => $h['id'], 'rcode' => self::RCODES[$h['flags'] & 0x000F] ?? 'RCODE' . ($h['flags'] & 0x000F),
            'tc' => (bool) ($h['flags'] & 0x0200), 'ad' => (bool) ($h['flags'] & 0x0020),
            'qname' => $qname, 'qtype' => $qtype, 'answers' => $answers,
        ];
    }

    private static function u16(string $msg, int $off, int $len): int
    {
        if ($off + $len > strlen($msg)) throw new RuntimeException('truncated');
        return $len === 4 ? unpack('N', substr($msg, $off, 4))[1] : unpack('n', substr($msg, $off, 2))[1];
    }

    private static function readName(string $msg, int &$off): string
    {
        $labels = [];
        $pos = $off;
        $jumped = false;
        for ($hops = 0; $hops < 64; $hops++) {
            if ($pos >= strlen($msg)) throw new RuntimeException('truncated name');
            $len = ord($msg[$pos]);
            if ($len === 0) {
                if (!$jumped) $off = $pos + 1;
                return implode('.', $labels);
            }
            if (($len & 0xC0) === 0xC0) {
                $ptr = self::u16($msg, $pos, 2) & 0x3FFF;
                if (!$jumped) $off = $pos + 2;
                if ($ptr >= $pos) throw new RuntimeException('forward compression pointer');
                $pos = $ptr;
                $jumped = true;
                continue;
            }
            if ($len > 63 || $pos + 1 + $len > strlen($msg)) throw new RuntimeException('bad label');
            $labels[] = strtolower(substr($msg, $pos + 1, $len));
            $pos += 1 + $len;
        }
        throw new RuntimeException('name too long');
    }

    private static function rdata(string $msg, int $off, int $type, int $len): mixed
    {
        $raw = substr($msg, $off, $len);
        $p = $off;
        return match ($type) {
            1 => $len === 4 ? inet_ntop($raw) : throw new RuntimeException('bad A'),
            28 => $len === 16 ? inet_ntop($raw) : throw new RuntimeException('bad AAAA'),
            2, 5 => self::readName($msg, $p),
            15 => ['preference' => self::u16($msg, $off, 2), 'exchange' => (function () use ($msg, $off) { $q = $off + 2; return self::readName($msg, $q); })()],
            6 => (function () use ($msg, $off) {
                $q = $off;
                $mname = self::readName($msg, $q);
                $rname = self::readName($msg, $q);
                return ['mname' => $mname, 'rname' => $rname, 'serial' => self::u16($msg, $q, 4)];
            })(),
            16 => (function () use ($raw) {
                $s = '';
                for ($i = 0; $i < strlen($raw);) { $l = ord($raw[$i]); $s .= substr($raw, $i + 1, $l); $i += 1 + $l; }
                return $s;
            })(),
            257 => (function () use ($raw) {
                if (strlen($raw) < 2) throw new RuntimeException('bad CAA');
                $tl = ord($raw[1]);
                return ['flags' => ord($raw[0]), 'tag' => substr($raw, 2, $tl), 'value' => substr($raw, 2 + $tl)];
            })(),
            default => bin2hex($raw),
        };
    }
}
