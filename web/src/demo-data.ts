// DEMO DATA ONLY — synthetic fixtures until the Laravel API is available.
// Lanes are NOT hard-coded: every site runs through @upfixy/core siteHealth()
// and every client through rollUp(), exactly as the server will.
import {
  siteHealth, rollUp, freshnessOf, backupBadge,
  type CheckReading, type HealthLane, type ComponentState, type TicketState,
} from '@upfixy/core';

export type ComponentKey = 'domain' | 'hosting' | 'ssl' | 'security' | 'backup' | 'errors';
export interface Component { state: ComponentState; label: string }

export interface Issue { code: string; title: string; observedAt: Date; host?: string }

export interface Site {
  id: string;
  host: string;
  clientId: string | null;
  lane: HealthLane;
  lastVerifiedAt: Date | null;
  /** Newest reading of any freshness; shown when nothing is fresh. */
  lastObservedAt: Date | null;
  components: Record<ComponentKey, Component>;
  issues: Issue[];
  /** Live app only. */
  href?: string;
  hosts?: string[];
  openRequests?: number;
  verifiedHosts?: string[];
}

export interface Client {
  id: string;
  name: string;
  technician: string | null;
  tags: string[];
  sites: Site[];
  lane: HealthLane;
  openTickets: number;
  /** Live app only: technicians assigned to this client (emails where visible). */
  staff?: string[];
  href?: string;
}

export interface Ticket {
  id: string;
  title: string;
  host: string;
  client: string;
  state: TicketState;
  assignee: string | null;
  hasApproval: boolean;
  /** Live app only. */
  assigneeId?: string | null;
  version?: number;
  hasQuote?: boolean;
}

const now = new Date();
const minsAgo = (m: number) => new Date(now.getTime() - m * 60_000);
const INTERVAL = 300;

interface SiteSpec {
  host: string;
  clientId: string | null;
  active?: boolean;
  http: [CheckReading['severity'], number | null];   // severity, minutes ago (null = never)
  tls: [CheckReading['severity'], number | null];
  dns: [CheckReading['severity'], number | null];
  hosting?: Component;
  backup?: Parameters<typeof backupBadge>[0];
  issues?: Array<[string, string, number]>;
  sslLabel?: string;
}

function reading(kind: CheckReading['kind'], [severity, mins]: SiteSpec['http']): CheckReading {
  const observedAt = mins === null ? null : minsAgo(mins);
  return { kind, severity, observedAt, freshness: freshnessOf(observedAt, INTERVAL, now) };
}

function toComponent(r: CheckReading, okLabel: string): Component {
  if (r.freshness !== 'fresh') return { state: 'unknown', label: r.freshness === 'stale' ? 'Stale reading' : 'Not checked yet' };
  if (r.severity === 'unknown') return { state: 'unknown', label: 'Inconclusive' };
  const map: Record<string, ComponentState> = { ok: 'ok', warning: 'warning', critical: 'critical' };
  return { state: map[r.severity] ?? 'unknown', label: r.severity === 'ok' ? okLabel : r.severity };
}

function site(id: string, s: SiteSpec): Site {
  const readings = [reading('http', s.http), reading('tls', s.tls), reading('dns', s.dns)];
  const h = siteHealth({ monitorActive: s.active ?? true, readings });
  const [http, tls, dns] = readings;
  const issues = (s.issues ?? []).map(([code, title, m]) => ({ code, title, observedAt: minsAgo(m) }));
  const observed = readings.map((r) => r.observedAt?.getTime() ?? 0).filter(Boolean);
  return {
    id, host: s.host, clientId: s.clientId, lane: h.lane, lastVerifiedAt: h.lastVerifiedAt, issues,
    lastObservedAt: observed.length ? new Date(Math.max(...observed)) : null,
    components: {
      domain: toComponent(dns, 'Resolving'),
      hosting: s.hosting ?? { state: 'not_connected', label: 'Not connected' },
      ssl: tls.freshness === 'fresh' && (tls.severity === 'warning' || tls.severity === 'critical')
        ? { state: tls.severity === 'critical' ? 'critical' : 'warning', label: s.sslLabel ?? tls.severity }
        : toComponent(tls, s.sslLabel ?? 'Valid'),
      security: { state: 'unknown', label: 'Not assessed' },
      backup: (({ state, label }) => ({ state, label: label.replace(/^Backup: /, '') }))(backupBadge(s.backup ?? { connected: false })),
      errors: http.freshness !== 'fresh'
        ? { state: 'unknown', label: 'No recent data' }
        : issues.length ? { state: http.severity === 'critical' ? 'critical' : 'warning', label: `${issues.length} open` }
        : { state: 'ok', label: 'None' },
    },
  };
}

const sites: Site[] = [
  site('s1', { host: 'shop.mitratraders.in', clientId: 'c1', http: ['ok', 2], tls: ['warning', 2], dns: ['ok', 2],
    sslLabel: 'Expires in 9 days', hosting: { state: 'ok', label: 'Active' },
    backup: { connected: true, jobReportedComplete: true },
    issues: [['TLS_EXPIRING', 'SSL certificate expires in 9 days', 2]] }),
  site('s2', { host: 'mitratraders.in', clientId: 'c1', http: ['ok', 3], tls: ['ok', 3], dns: ['ok', 3], hosting: { state: 'ok', label: 'Active' } }),
  site('s3', { host: 'www.mitratraders.in', clientId: 'c1', http: ['ok', 3], tls: ['ok', 3], dns: ['ok', 3], hosting: { state: 'ok', label: 'Active' } }),
  site('s4', { host: 'kalaghar-jewels.com', clientId: 'c2', http: ['critical', 4], tls: ['ok', 4], dns: ['ok', 4],
    hosting: { state: 'unknown', label: 'Connector offline' },
    issues: [['HTTP_503', 'Site returns 503 Service Unavailable', 4]] }),
  site('s5', { host: 'booking.kalaghar-jewels.com', clientId: 'c2', http: ['ok', 4], tls: ['ok', 4], dns: ['ok', 4] }),
  site('s6', { host: 'greenfield-school.edu.in', clientId: 'c3', http: ['ok', 47], tls: ['ok', 47], dns: ['ok', 47],
    issues: [['MONITOR_STALE', 'No fresh readings for 47 minutes', 47]] }),
  site('s7', { host: 'anandchem.co.in', clientId: 'c4', http: ['ok', 1], tls: ['ok', 1], dns: ['ok', 1],
    hosting: { state: 'ok', label: 'Active' }, backup: { connected: true, restoreVerified: true } }),
  site('s8', { host: 'portal.anandchem.co.in', clientId: 'c4', http: ['ok', 1], tls: ['ok', 1], dns: ['ok', 1], hosting: { state: 'ok', label: 'Active' } }),
  site('s9', { host: 'skyline-realty.in', clientId: 'c5', http: ['critical', 6], tls: ['unknown', 6], dns: ['critical', 6],
    issues: [['DNS_SERVFAIL', 'DNS lookups fail (SERVFAIL)', 6]] }),
  site('s10', { host: 'newclient-store.in', clientId: 'c6', active: false, http: ['unknown', null], tls: ['unknown', null], dns: ['unknown', null] }),
];

const clientSpecs: Array<Omit<Client, 'sites' | 'lane'>> = [
  { id: 'c1', name: 'Mitra Traders', technician: 'Tina R.', tags: ['Maintenance contract'], openTickets: 1 },
  { id: 'c2', name: 'Kalaghar Jewels', technician: 'Tina R.', tags: ['VIP'], openTickets: 1 },
  { id: 'c3', name: 'Greenfield School', technician: null, tags: [], openTickets: 0 },
  { id: 'c4', name: 'Anand Chemicals', technician: 'Arjun M.', tags: [], openTickets: 0 },
  { id: 'c5', name: 'Skyline Realty', technician: 'Arjun M.', tags: ['Renewal risk'], openTickets: 1 },
  { id: 'c6', name: 'New Client Store', technician: null, tags: ['Onboarding'], openTickets: 0 },
];

export const demoClients: Client[] = clientSpecs.map((c) => {
  const own = sites.filter((s) => s.clientId === c.id);
  return { ...c, sites: own, lane: rollUp(own.map((s) => s.lane)) };
});

export const demoSites = sites;

export const demoTickets: Ticket[] = [
  { id: 't1', title: '503 on homepage', host: 'kalaghar-jewels.com', client: 'Kalaghar Jewels', state: 'investigating', assignee: 'Tina R.', hasApproval: false },
  { id: 't2', title: 'Renew SSL certificate', host: 'shop.mitratraders.in', client: 'Mitra Traders', state: 'quote_pending', assignee: 'Tina R.', hasApproval: false },
  { id: 't3', title: 'Fix DNS delegation', host: 'skyline-realty.in', client: 'Skyline Realty', state: 'new', assignee: null, hasApproval: false },
  { id: 't4', title: 'Wrong A record after migration', host: 'portal.anandchem.co.in', client: 'Anand Chemicals', state: 'verifying', assignee: 'Arjun M.', hasApproval: true },
];
