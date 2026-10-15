// Maps API board rows to the card view models. Lanes come from the server;
// this only formats component badges and never upgrades missing data.
import type { ComponentState } from '@upfixy/core';
import type { ApiCheck, ApiClient, ApiSite } from './api';
import type { Client, Component, Issue, Site } from './demo-data';
import { HELP } from './help';

const OK_LABEL: Record<ApiCheck['kind'], string> = { dns: 'Resolving', tls: 'Valid', http: 'None' };

export function checkComponent(c: ApiCheck | undefined): Component {
  if (!c) return { state: 'unknown', label: 'Not checked yet' };
  if (c.freshness !== 'fresh') return { state: 'unknown', label: c.freshness === 'stale' ? 'Stale reading' : 'Not checked yet' };
  const map: Partial<Record<ApiCheck['severity'], ComponentState>> = { ok: 'ok', warning: 'warning', critical: 'critical' };
  const state = map[c.severity] ?? 'unknown';
  if (state === 'unknown') return { state, label: 'Inconclusive' };
  return { state, label: state === 'ok' ? OK_LABEL[c.kind] : c.severity === 'critical' ? 'Failing' : 'Needs attention' };
}

export function toSite(s: ApiSite): Site {
  // Several targets (apex + www) may report the same kind; show the worst fresh one.
  const rank = { critical: 3, warning: 2, unknown: 1, not_applicable: 0, ok: 0 } as const;
  const worst = (kind: ApiCheck['kind']) =>
    s.checks.filter((c) => c.kind === kind).sort((a, b) =>
      (b.freshness === 'fresh' ? rank[b.severity] : 1) - (a.freshness === 'fresh' ? rank[a.severity] : 1))[0];

  const issues: Issue[] = s.checks
    .filter((c) => c.freshness === 'fresh' && (c.severity === 'warning' || c.severity === 'critical'))
    .map((c) => {
      const code = c.result_code.toUpperCase();
      return { code, title: HELP[code]?.title ?? `${c.kind.toUpperCase()} check ${c.severity === 'critical' ? 'failing' : 'needs attention'}`, observedAt: new Date(c.observed_at) };
    });
  const observed = s.checks.map((c) => new Date(c.observed_at).getTime());

  return {
    id: s.id,
    host: s.display_name,
    clientId: s.client_id,
    lane: s.lane,
    lastVerifiedAt: s.last_verified_at ? new Date(s.last_verified_at) : null,
    lastObservedAt: observed.length ? new Date(Math.max(...observed)) : null,
    issues,
    components: {
      domain: checkComponent(worst('dns')),
      hosting: { state: 'not_connected', label: 'Not connected' },
      ssl: checkComponent(worst('tls')),
      security: { state: 'unknown', label: 'Not assessed' },
      backup: { state: 'not_connected', label: 'Not connected' },
      errors: (() => {
        const http = checkComponent(worst('http'));
        return http.state === 'ok' ? { state: 'ok', label: 'None' } : http;
      })(),
    },
  };
}

export function toClient(c: ApiClient): Client {
  return { id: c.id, name: c.name, lane: c.lane, technician: null, tags: [], openTickets: 0, sites: c.websites.map(toSite) };
}
