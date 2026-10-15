import { describe, it, expect } from 'vitest';
import { normalizeTarget, isBlockedAddress } from '../src/url-guard';

const ok = (s: string) => {
  const r = normalizeTarget(s);
  if (!r.ok) throw new Error(`${s} rejected: ${r.code}`);
  return r.target;
};
const code = (s: string) => {
  const r = normalizeTarget(s);
  return r.ok ? 'ok' : r.code;
};

describe('normalizeTarget', () => {
  it('defaults to https and port 443, keeps the exact host', () => {
    expect(ok('Shop.Example.com')).toEqual({ scheme: 'https', host: 'shop.example.com', port: 443, path: '/', hostIsIp: false });
  });

  it('AC-AST-001.3: apex and www are different targets', () => {
    expect(ok('example.com').host).toBe('example.com');
    expect(ok('www.example.com').host).toBe('www.example.com');
  });

  it('keeps the path, drops the query string and says so', () => {
    const r = normalizeTarget('https://example.com/shop/?token=secret');
    expect(r.ok && r.target.path).toBe('/shop/');
    expect(r.ok && r.droppedQuery).toBe(true);
  });

  it('converts internationalised names to punycode', () => {
    expect(ok('https://bücher.example/').host).toBe('xn--bcher-kva.example');
  });

  it.each([
    ['', 'empty'],
    ['ftp://example.com', 'scheme'],
    ['file:///etc/passwd', 'scheme'],
    ['javascript:alert(1)', 'scheme'],
    ['gopher://example.com', 'scheme'],
    ['https://user:pass@example.com', 'credentials'],
    ['https://example.com:22', 'port'],
    ['https://example.com:6379', 'port'],
    ['http://localhost', 'internal_name'],
    ['http://intranet.corp', 'internal_name'],
    ['http://printer.local', 'internal_name'],
    ['http://metadata', 'single_label'],
    ['exa mple.com', 'control_chars'],
    ['https://example.com/\u0000', 'control_chars'],
    ['https://example.com\\@evil.com', 'control_chars'],
    ['a'.repeat(3000), 'too_long'],
  ])('rejects %j as %s', (input, expected) => {
    expect(code(input)).toBe(expected);
  });

  it.each([
    'http://169.254.169.254/latest/meta-data/',  // cloud metadata (AC-AST-001.2)
    'http://127.0.0.1',
    'http://10.0.0.5',
    'http://172.16.1.1',
    'http://192.168.1.1',
    'http://100.64.0.1',
    'http://0.0.0.0',
    'http://2130706433',          // decimal 127.0.0.1
    'http://0x7f.0.0.1',          // hex
    'http://0177.0.0.1',          // octal
    'http://127.1',               // short form
    'http://[::1]',
    'http://[::ffff:127.0.0.1]',  // IPv4-mapped
    'http://[::ffff:a9fe:a9fe]',  // mapped metadata
    'http://[fd00::1]',
    'http://[fe80::1]',
    'http://[64:ff9b::a00:1]',    // NAT64 → 10.0.0.1
    'http://[2002:a00:1::]',      // 6to4 → 10.0.0.1
  ])('AC-AST-001.2: rejects private/reserved literal %s', (input) => {
    expect(code(input)).toBe('private_address');
  });

  it('accepts public IP literals', () => {
    expect(ok('http://93.184.216.34').hostIsIp).toBe(true);
    expect(ok('http://[2606:4700::1111]').host).toBe('[2606:4700::1111]');
  });
});

describe('isBlockedAddress (connect-time check)', () => {
  it.each(['10.1.2.3', '127.0.0.1', '169.254.169.254', '::1', '::', 'fc00::1', 'ff02::1', '2001:db8::1',
    '::ffff:192.168.0.1', '64:ff9b::7f00:1', '224.0.0.1', '255.255.255.255', 'fe80::1%eth0', 'not-an-ip', ''])(
    'blocks %s', (a) => expect(isBlockedAddress(a)).toBe(true));

  it.each(['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111', '[2a00:1450:4009::200e]', '::ffff:8.8.8.8'])(
    'allows %s', (a) => expect(isBlockedAddress(a)).toBe(false));
});
