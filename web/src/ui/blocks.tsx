// Composite blocks shared by the dashboard and boards. Real data only.
import { useState } from 'react';
import { TICKET_LABELS, type HealthLane, type TicketState } from '@upfixy/core';
import { Activity, CircleAlert, CircleCheck, Globe, Inbox, LifeBuoy, Lock, Network, ShieldCheck, type LucideIcon } from 'lucide-react';
import { api, type ApiActivity, type ApiIncident, type ApiOverview } from '../api';
import { useWs } from '../app-context';
import { since } from '../components';
import { HELP } from '../help';
import { href, onLinkClick } from '../router';
import { EmptyState, StatusBadge, type UiStatus } from './kit';

export const LANE_STATUS: Record<HealthLane, UiStatus> = {
  healthy: 'healthy', warning: 'warning', critical: 'critical', unknown: 'unknown', needs_setup: 'needs_setup',
};
export const LANE_COLOR: Record<HealthLane, string> = {
  healthy: '#16a34a', warning: '#f59e0b', critical: '#dc2626', unknown: '#64748b', needs_setup: '#cbd5e1',
};
export const KIND_LABEL: Record<string, string> = { http: 'Website', tls: 'SSL', dns: 'Domain / DNS' };
export const KIND_ICON: Record<string, LucideIcon> = { http: Globe, tls: Lock, dns: Network };

const issueTitle = (code: string, kind: string, severity: string) =>
  HELP[code]?.title ?? `${KIND_LABEL[kind] ?? kind} check ${severity === 'critical' ? 'failing' : 'needs attention'}`;

/** Incidents first (they have a lifecycle), then other failing or warning checks. */
export function AttentionQueue({ incidents, issues, onChanged, limit = 6 }: {
  incidents: ApiIncident[]; issues: ApiOverview['issues']; onChanged: () => void; limit?: number;
}) {
  const { role, isStaff, openHelp, setError } = useWs();
  const [busy, setBusy] = useState<string | null>(null);
  const canAck = isStaff || role === 'client_owner';
  const incidentKeys = new Set(incidents.map((i) => `${i.website_id}-${i.root_kind}`));
  const rest = issues.filter((i) => !incidentKeys.has(`${i.website_id}-${i.kind}`));
  async function ack(id: string) {
    setBusy(id);
    try { await api('POST', `incidents/${id}/acknowledge`); onChanged(); }
    catch (e) { setError(e instanceof Error ? e.message : String(e)); }
    finally { setBusy(null); }
  }
  if (!incidents.length && !rest.length) {
    return <EmptyState icon={ShieldCheck} title="Nothing needs attention">No failing or warning checks right now. Unknown readings are listed on the websites board.</EmptyState>;
  }
  const rows = [
    ...incidents.map((i) => ({ key: `i${i.id}`, inc: i, iss: null })),
    ...rest.map((i) => ({ key: `c${i.target_id}${i.kind}`, inc: null, iss: i })),
  ];
  return (
    <ul className="ux-list">
      {rows.slice(0, limit).map(({ key, inc, iss }) => {
        const sev = inc ? 'critical' : iss!.severity === 'critical' ? 'critical' : 'warning';
        const kind = inc ? inc.root_kind : iss!.kind;
        const Icon = KIND_ICON[kind] ?? CircleAlert;
        const host = inc ? inc.host : iss!.host;
        const code = inc ? inc.result_code : iss!.result_code.toUpperCase();
        const title = inc ? inc.title : issueTitle(code, iss!.kind, iss!.severity);
        const at = inc ? inc.last_failure_at : iss!.observed_at;
        return (
          <li key={key}>
            <span className={`ux-list__icon ux-tint--${sev}`} aria-hidden="true"><Icon /></span>
            <div className="ux-list__main">
              <a className="ux-list__title" href={href({ name: 'website', id: inc ? inc.website_id : iss!.website_id })} onClick={onLinkClick}>{host}</a>
              <span className="ux-list__meta">{title}</span>
              <span className="ux-list__meta">
                {inc ? <>Incident · failing since {since(new Date(inc.first_failure_at))}{inc.acknowledged_at ? ' · acknowledged' : ''}</> : <>Reading {since(new Date(at))}</>}
              </span>
              <span style={{ display: 'flex', flexWrap: 'wrap', gap: 12, marginTop: 6 }}>
                <button type="button" className="ux-link" onClick={() => openHelp({ code, title, observedAt: new Date(at), host }, host)}>Help me understand</button>
                {inc && inc.status === 'open' && !inc.acknowledged_at && canAck && (
                  <button type="button" className="ux-btn ux-btn--ghost ux-btn--sm" disabled={busy === inc.id} onClick={() => void ack(inc.id)}>Acknowledge</button>
                )}
              </span>
            </div>
            <div className="ux-list__side"><StatusBadge status={sev} label={inc ? 'Incident' : undefined} /></div>
          </li>
        );
      })}
    </ul>
  );
}

const ACT: Record<string, { icon: LucideIcon; tone: UiStatus; text: (a: ApiActivity) => string }> = {
  incident_opened: { icon: CircleAlert, tone: 'critical', text: (a) => `Incident opened${a.detail ? `: ${HELP[a.detail.toUpperCase()]?.title ?? a.detail}` : ''}` },
  incident_resolved: { icon: CircleCheck, tone: 'healthy', text: () => 'Incident resolved after good checks' },
};

/** Recorded events only: incident lifecycle and request stage changes. */
export function ActivityTimeline({ items, limit = 8 }: { items: ApiActivity[] | undefined; limit?: number }) {
  if (!items) return <div className="ux-skel" style={{ height: 180 }} aria-hidden="true" />;
  if (!items.length) return <EmptyState icon={Inbox} title="No activity yet">Incidents and request updates appear here.</EmptyState>;
  return (
    <ul className="ux-list">
      {items.slice(0, limit).map((a, i) => {
        const def = ACT[a.kind];
        const state = a.kind.startsWith('ticket_') ? (a.kind.slice(7) as TicketState) : null;
        const Icon = def?.icon ?? (state ? LifeBuoy : Activity);
        const tone: UiStatus = def?.tone ?? (state === 'resolved' || state === 'closed' ? 'healthy' : state === 'investigating' ? 'investigating' : 'info');
        const text = def ? def.text(a) : state ? `${a.detail ?? 'Request'} → ${TICKET_LABELS[state] ?? state}` : a.kind;
        return (
          <li key={`${a.kind}${a.at}${i}`}>
            <span className={`ux-list__icon ux-tint--${tone}`} aria-hidden="true"><Icon /></span>
            <div className="ux-list__main">
              <span className="ux-list__title" style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>{text}</span>
              <span className="ux-list__meta">
                {a.ticket_id
                  ? <a href={href({ name: 'work', ticket: a.ticket_id })} onClick={onLinkClick} style={{ color: 'inherit' }}>{a.website}</a>
                  : <a href={href({ name: 'website', id: a.website_id })} onClick={onLinkClick} style={{ color: 'inherit' }}>{a.website}</a>}
              </span>
            </div>
            <time className="ux-list__side" dateTime={a.at}>{since(new Date(a.at))}</time>
          </li>
        );
      })}
    </ul>
  );
}
