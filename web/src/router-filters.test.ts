import { describe, it, expect } from 'vitest';
import { parse, href } from './router';
import { filterSites, filterClients, filterTickets, matchesText, laneCounts, isFiltered } from './filters';

const id = '0b8f1c2e-1111-4222-8333-444455556666';

describe('router', () => {
  it.each([
    ['/', { name: 'overview' }],
    ['/clients', { name: 'clients' }],
    [`/clients/${id}`, { name: 'client', id }],
    [`/websites/${id}`, { name: 'website', id, tab: undefined }],
    [`/websites/${id}/ssl`, { name: 'website', id, tab: 'ssl' }],
    ['/work/', { name: 'work', ticket: undefined }],
    ['/websites/not-a-uuid', { name: 'not_found' }],
    ['/../../etc/passwd', { name: 'not_found' }],
  ])('parses %s', (path, route) => expect(parse(path)).toEqual(route));

  it('round-trips through href', () => {
    for (const r of [{ name: 'website', id, tab: 'activity' }, { name: 'client', id }, { name: 'work', ticket: id }] as const) {
      const url = new URL(href(r), 'http://x');
      expect(parse(url.pathname, url.search)).toEqual(r);
    }
  });
});

describe('filters', () => {
  const sites = [
    { lane: 'critical' as const, host: 'kalaghar-jewels.com', clientId: 'c1' },
    { lane: 'healthy' as const, host: 'anandchem.co.in', clientId: 'c2' },
    { lane: 'warning' as const, host: 'shop.mitratraders.in', clientId: 'c1' },
  ];
  const name = (c: string | null) => ({ c1: 'Kalaghar Jewels', c2: 'Anand Chemicals' } as Record<string, string>)[c ?? ''] ?? '';

  it('search matches every term across host and client name, ignoring case and accents', () => {
    expect(filterSites(sites, { q: 'KALAGHAR' }, name).map((s) => s.host)).toEqual(['kalaghar-jewels.com', 'shop.mitratraders.in']);
    expect(filterSites(sites, { q: 'kalaghar shop' }, name).map((s) => s.host)).toEqual(['shop.mitratraders.in']);
    expect(matchesText('café', 'Cafe Mocha')).toBe(true);
  });

  it('lane and client filters combine', () => {
    expect(filterSites(sites, { lanes: ['critical', 'warning'], client_id: 'c1' }).length).toBe(2);
    expect(filterSites(sites, { lanes: ['healthy'], client_id: 'c1' }).length).toBe(0);
  });

  it('clients match on their own sites', () => {
    const clients = [{ lane: 'warning' as const, name: 'Mitra', sites: [{ host: 'shop.mitratraders.in' }] }];
    expect(filterClients(clients, { q: 'mitratraders' })).toHaveLength(1);
  });

  it('ticket filters: assigned to me, unassigned, stages', () => {
    const t = [
      { title: 'SSL', host: 'a', client: 'X', state: 'new' as const, assigneeId: 'me' },
      { title: '503', host: 'b', client: 'Y', state: 'quote_pending' as const, assigneeId: null },
    ];
    expect(filterTickets(t, { assignee: 'me' }, 'me')).toHaveLength(1);
    expect(filterTickets(t, { assignee: 'unassigned' }, 'me')[0].title).toBe('503');
    expect(filterTickets(t, { states: ['quote_pending'] }, 'me')).toHaveLength(1);
  });

  it('lane counts and "is filtered"', () => {
    expect(laneCounts(sites)).toEqual({ critical: 1, warning: 1, unknown: 0, needs_setup: 0, healthy: 1 });
    expect(isFiltered({ layout: 'list', assignee: 'any' })).toBe(false);
    expect(isFiltered({ q: ' ' })).toBe(false);
    expect(isFiltered({ lanes: ['critical'] })).toBe(true);
  });
});

describe('settings route', () => {
  it('serves /settings and keeps the old /security link working', () => {
    expect(parse('/settings')).toEqual({ name: 'settings' });
    expect(parse('/security')).toEqual({ name: 'settings' });
    expect(href({ name: 'settings' })).toBe('/settings');
  });
});
