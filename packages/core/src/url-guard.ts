// Monitor-target intake and SSRF address policy.
// FRD refs: AST-001, MON-006, §14.2 steps 1–2, §17.2, AC-AST-001.2, AC-AST-001.3, AC-MON-001.3.
//
// Two layers, both required:
//  1. normalizeTarget(): rejects unsafe input before anything is stored.
//  2. isBlockedAddress(): must be called by the probe worker on EVERY resolved
//     address at connect time and at every redirect hop (DNS-rebinding defence).
//     Validating only at intake is not sufficient.

export const ALLOWED_PORTS = [80, 443, 8080, 8443] as const;
const MAX_INPUT = 2048;
const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.intranet', '.lan', '.home.arpa', '.corp'];

export type TargetErrorCode =
  | 'empty' | 'too_long' | 'control_chars' | 'bad_url' | 'scheme' | 'credentials'
  | 'port' | 'private_address' | 'internal_name' | 'single_label';

export interface MonitorTarget {
  scheme: 'http' | 'https';
  host: string;   // lower-case, IDN as punycode; apex and www stay distinct
  port: number;
  path: string;   // pathname only; query strings are dropped (may carry secrets)
  hostIsIp: boolean;
}

export type TargetResult =
  | { ok: true; target: MonitorTarget; droppedQuery: boolean }
  | { ok: false; code: TargetErrorCode; message: string };

const MESSAGES: Record<TargetErrorCode, string> = {
  empty: 'Enter a website address, for example shop.example.com.',
  too_long: 'That address is too long.',
  control_chars: 'That address contains characters that are not allowed.',
  bad_url: "That doesn't look like a valid website address.",
  scheme: 'Only http:// and https:// websites can be monitored.',
  credentials: 'Remove the username or password from the address. Never put passwords in a URL.',
  port: `Only standard web ports can be monitored (${ALLOWED_PORTS.join(', ')}).`,
  private_address: 'This address points to a private or reserved network and cannot be monitored.',
  internal_name: 'This looks like an internal network name and cannot be monitored.',
  single_label: 'Enter a full public domain name, for example example.com.',
};

const fail = (code: TargetErrorCode): TargetResult => ({ ok: false, code, message: MESSAGES[code] });

export function normalizeTarget(raw: string): TargetResult {
  const input = (raw ?? '').trim();
  if (!input) return fail('empty');
  if (input.length > MAX_INPUT) return fail('too_long');
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u001f\u007f\s\\]/.test(input)) return fail('control_chars');

  const withScheme = /^[a-z][a-z0-9+.-]*:/i.test(input) ? input : `https://${input}`;
  let url: URL;
  try {
    url = new URL(withScheme);
  } catch {
    return fail('bad_url');
  }
  const scheme = url.protocol.slice(0, -1).toLowerCase();
  if (scheme !== 'http' && scheme !== 'https') return fail('scheme');
  if (url.username || url.password) return fail('credentials');

  const port = url.port ? Number(url.port) : scheme === 'https' ? 443 : 80;
  if (!(ALLOWED_PORTS as readonly number[]).includes(port)) return fail('port');

  // WHATWG URL already canonicalises IPv4 tricks (0x7f.1, 2130706433, 0177.0.0.1).
  let host = url.hostname.toLowerCase().replace(/\.$/, '');
  if (!host) return fail('bad_url');
  const v6 = host.startsWith('[') ? host.slice(1, -1) : null;
  const hostIsIp = v6 !== null || parseIPv4(host) !== null;

  if (hostIsIp) {
    if (isBlockedAddress(v6 ?? host)) return fail('private_address');
  } else {
    if (host === 'localhost' || BLOCKED_SUFFIXES.some((s) => host.endsWith(s))) return fail('internal_name');
    if (!host.includes('.')) return fail('single_label');
    if (!/^[a-z0-9.-]+$/.test(host) || host.split('.').some((l) => !l || l.length > 63 || l.startsWith('-') || l.endsWith('-'))) {
      return fail('bad_url');
    }
  }
  if (v6) host = `[${v6}]`;

  return {
    ok: true,
    droppedQuery: url.search.length > 0,
    target: { scheme, host, port, path: url.pathname || '/', hostIsIp },
  };
}

// ------------------------------------------------------------ address policy

export function parseIPv4(s: string): number[] | null {
  const parts = s.split('.');
  if (parts.length !== 4) return null;
  const out: number[] = [];
  for (const p of parts) {
    if (!/^\d{1,3}$/.test(p)) return null;
    const n = Number(p);
    if (n > 255) return null;
    out.push(n);
  }
  return out;
}

const V4_BLOCKED: Array<[number[], number]> = [
  [[0, 0, 0, 0], 8], [[10, 0, 0, 0], 8], [[100, 64, 0, 0], 10], [[127, 0, 0, 0], 8],
  [[169, 254, 0, 0], 16], [[172, 16, 0, 0], 12], [[192, 0, 0, 0], 24], [[192, 0, 2, 0], 24],
  [[192, 88, 99, 0], 24], [[192, 168, 0, 0], 16], [[198, 18, 0, 0], 15], [[198, 51, 100, 0], 24],
  [[203, 0, 113, 0], 24], [[224, 0, 0, 0], 4], [[240, 0, 0, 0], 4],
];

const v4ToInt = (o: number[]) => ((o[0] << 24) >>> 0) + (o[1] << 16) + (o[2] << 8) + o[3];

function v4Blocked(o: number[]): boolean {
  const ip = v4ToInt(o);
  return V4_BLOCKED.some(([net, bits]) => {
    const mask = bits === 0 ? 0 : (~0 << (32 - bits)) >>> 0;
    return ((ip & mask) >>> 0) === ((v4ToInt(net) & mask) >>> 0);
  });
}

/** Expand an IPv6 literal to 8 hextets; null if not valid IPv6. */
export function parseIPv6(s: string): number[] | null {
  let str = s.toLowerCase();
  const zone = str.indexOf('%');
  if (zone >= 0) return null; // zone IDs are link-local by definition; refuse
  let tail: number[] = [];
  const lastColon = str.lastIndexOf(':');
  const maybeV4 = str.slice(lastColon + 1);
  if (maybeV4.includes('.')) {
    const v4 = parseIPv4(maybeV4);
    if (!v4) return null;
    tail = [(v4[0] << 8) | v4[1], (v4[2] << 8) | v4[3]];
    str = str.slice(0, lastColon + 1) + '0:0';
  }
  const halves = str.split('::');
  if (halves.length > 2) return null;
  const toNums = (h: string) => (h ? h.split(':') : []).map((x) => (/^[0-9a-f]{1,4}$/.test(x) ? parseInt(x, 16) : NaN));
  const head = toNums(halves[0]);
  const rest = halves.length === 2 ? toNums(halves[1]) : [];
  if ([...head, ...rest].some(Number.isNaN)) return null;
  const fill = 8 - head.length - rest.length;
  if (halves.length === 1 ? head.length !== 8 : fill < 0) return null;
  const full = [...head, ...Array(halves.length === 2 ? fill : 0).fill(0), ...rest];
  if (tail.length) full.splice(6, 2, ...tail);
  return full.length === 8 ? full : null;
}

function v6Blocked(h: number[]): boolean {
  const allZero = (from: number, to: number) => h.slice(from, to).every((x) => x === 0);
  const embeddedV4 = () => [h[6] >> 8, h[6] & 255, h[7] >> 8, h[7] & 255];
  if (allZero(0, 8)) return true;                                   // ::
  if (allZero(0, 7) && h[7] === 1) return true;                      // ::1
  if (allZero(0, 5) && h[5] === 0xffff) return v4Blocked(embeddedV4()); // ::ffff:a.b.c.d
  if (allZero(0, 6)) return true;                                    // deprecated ::a.b.c.d
  if (h[0] === 0x64 && h[1] === 0xff9b) return v4Blocked(embeddedV4()); // NAT64
  if ((h[0] & 0xfe00) === 0xfc00) return true;                       // fc00::/7 ULA
  if ((h[0] & 0xffc0) === 0xfe80) return true;                       // fe80::/10 link-local
  if ((h[0] & 0xffc0) === 0xfec0) return true;                       // fec0::/10 site-local
  if ((h[0] & 0xff00) === 0xff00) return true;                       // multicast
  if (h[0] === 0x2001 && h[1] === 0x0db8) return true;               // documentation
  if (h[0] === 0x0100 && allZero(1, 4)) return true;                 // discard-only
  if (h[0] === 0x2002) return v4Blocked([h[1] >> 8, h[1] & 255, h[2] >> 8, h[2] & 255]); // 6to4
  return false;
}

/**
 * True when a resolved address must never be connected to by a public probe.
 * Unparseable input is treated as blocked (fail closed).
 */
export function isBlockedAddress(addr: string): boolean {
  const a = addr.replace(/^\[|\]$/g, '');
  const v4 = parseIPv4(a);
  if (v4) return v4Blocked(v4);
  const v6 = parseIPv6(a);
  if (v6) return v6Blocked(v6);
  return true;
}
