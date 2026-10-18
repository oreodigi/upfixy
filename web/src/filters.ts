// Board filters (BRD-004). Pure functions over data the API already returned,
// which row-level security has filtered, so search can never surface a name
// the user isn't allowed to see (AC-BRD-004.1).
import type { HealthLane, TicketState } from '@upfixy/core';

export interface Filters {
  q?: string;
  lanes?: HealthLane[];
  client_id?: string;
  assignee?: 'me' | 'unassigned' | 'any';
  states?: TicketState[];
  layout?: 'board' | 'list';
}

const norm = (s: string) => s.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').trim();

export function matchesText(q: string | undefined, ...fields: Array<string | null | undefined>): boolean {
  if (!q || !q.trim()) return true;
  const terms = norm(q).split(/\s+/);
  const hay = norm(fields.filter(Boolean).join(' '));
  return terms.every((t) => hay.includes(t));
}

export interface SiteLike { lane: HealthLane; host: string; clientId: string | null; hosts?: string[] }
export interface ClientLike { lane: HealthLane; name: string; sites: Array<{ host: string }> }
export interface TicketLike { title: string; host: string; client: string; state: TicketState; assigneeId?: string | null }

export function filterSites<T extends SiteLike>(sites: T[], f: Filters, clientName?: (id: string | null) => string): T[] {
  return sites.filter((s) =>
    (!f.lanes?.length || f.lanes.includes(s.lane))
    && (!f.client_id || s.clientId === f.client_id)
    && matchesText(f.q, s.host, ...(s.hosts ?? []), clientName?.(s.clientId)));
}

export function filterClients<T extends ClientLike>(clients: T[], f: Filters): T[] {
  return clients.filter((c) =>
    (!f.lanes?.length || f.lanes.includes(c.lane))
    && matchesText(f.q, c.name, ...c.sites.map((s) => s.host)));
}

export function filterTickets<T extends TicketLike>(tickets: T[], f: Filters, me: string | null): T[] {
  return tickets.filter((t) =>
    (!f.states?.length || f.states.includes(t.state))
    && (f.assignee === 'me' ? (t.assigneeId ?? null) === me : f.assignee === 'unassigned' ? !t.assigneeId : true)
    && matchesText(f.q, t.title, t.host, t.client));
}

export const isFiltered = (f: Filters) => !!(f.q?.trim() || f.lanes?.length || f.client_id || f.states?.length
  || (f.assignee && f.assignee !== 'any'));

/** Count per lane BEFORE lane filtering, so the lane chips always show totals. */
export function laneCounts(items: Array<{ lane: HealthLane }>): Record<HealthLane, number> {
  const out: Record<HealthLane, number> = { critical: 0, warning: 0, unknown: 0, needs_setup: 0, healthy: 0 };
  for (const i of items) out[i.lane]++;
  return out;
}
