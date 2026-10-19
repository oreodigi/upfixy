// UX-01 / SCR-02 Dashboard: what needs attention now, from real readings only.
// Health (computed lanes) and support workflow (request stages) are shown in
// separate panels and never mixed.
import { useEffect, useMemo, useState } from 'react';
import { TICKET_LABELS, TICKET_STATES, type HealthLane, type TicketState } from '@upfixy/core';
import {
  Activity, CalendarClock, CircleAlert, CircleCheck, Gauge, Globe, LifeBuoy, ListTodo, Lock, TriangleAlert, ArrowRight,
} from 'lucide-react';
import { api, type ApiIncident, type ApiOverview } from '../api';
import { useWs } from '../app-context';
import { LANES, LANE_LABEL, since } from '../components';
import { href, navigate, onLinkClick } from '../router';
import { Donut, MetricCard, Panel, ResponsiveTabs, StatusBadge, TimeSeriesChart, fmtMs, fmtPct, useMetrics, Monogram } from '../ui/kit';
import { ActivityTimeline, AttentionQueue, LANE_COLOR, LANE_STATUS } from '../ui/blocks';
import { AddButton } from './Boards';
import { cap } from '../ui/Shell';

type MTab = 'trends' | 'websites' | 'activity' | 'requests';

function greeting() {
  const h = Number(new Date().toLocaleString('en-IN', { hour: 'numeric', hour12: false, timeZone: 'Asia/Kolkata' }));
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

export default function Overview() {
  const { isStaff, isAgency, role, workspace, me, sites, clients, setError } = useWs();
  const [o, setO] = useState<ApiOverview | null>(null);
  const [incidents, setIncidents] = useState<ApiIncident[]>([]);
  const [chart, setChart] = useState<'availability' | 'response'>('availability');
  const [mtab, setMtab] = useState<MTab>('trends');
  const metrics = useMetrics(workspace?.id);
  const loadIncidents = () => api<{ data: ApiIncident[] }>('GET', 'incidents?status=open').then((r) => setIncidents(r.data)).catch(() => undefined);
  const loadOverview = () => api<ApiOverview>('GET', 'overview').then(setO).catch((e) => setError(e.message));

  useEffect(() => { void loadOverview(); void loadIncidents(); }, [workspace?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const series = metrics?.series ?? [];
  const totals = useMemo(() => {
    const checks = series.reduce((a, p) => a + p.checks, 0);
    const up = series.reduce((a, p) => a + (p.availability_pct === null ? 0 : (p.availability_pct / 100) * p.checks), 0);
    const msW = series.reduce((a, p) => a + (p.avg_ms === null ? 0 : p.avg_ms * p.checks), 0);
    const msN = series.reduce((a, p) => a + (p.avg_ms === null ? 0 : p.checks), 0);
    return { checks, availability: checks ? (100 * up) / checks : null, avgMs: msN ? msW / msN : null };
  }, [series]);
  const tls = useMemo(() => Object.entries(metrics?.websites ?? {})
    .filter(([, m]) => m.tls_days_left !== null)
    .map(([id, m]) => ({ id, days: m.tls_days_left!, host: sites.find((s) => s.id === id)?.display_name ?? '' }))
    .sort((a, b) => a.days - b.days), [metrics, sites]);

  const availSpark = series.map((p) => p.availability_pct);
  const minAvail = Math.min(100, ...series.map((p) => p.availability_pct ?? 100));
  const availDomain: [number, number] = [Math.max(0, Math.floor((minAvail - 1) / 5) * 5), 100];
  const failedBars = series.map((p) => (p.availability_pct === null ? null : Math.round(p.checks * (1 - p.availability_pct / 100))));
  if (!o) return <DashboardSkeleton />;
  const lanes = o.websites.by_lane;
  const total = o.websites.total;
  const noFresh = lanes.unknown + lanes.needs_setup;
  const expiring = tls.filter((t) => t.days <= 30);
  const openByState = TICKET_STATES.filter((s) => s !== 'resolved' && s !== 'closed').map((s) => [s, o.requests.by_state[s] ?? 0] as [TicketState, number]);
  const openRequests = openByState.reduce((a, [, n]) => a + n, 0);
  const canAdd = ['owner', 'admin'].includes(role);
  const headline = total === 0
    ? 'Add your first website to start monitoring.'
    : lanes.critical + lanes.warning === 0
      ? (noFresh ? `Nothing failing, but ${noFresh} of ${total} websites have no fresh readings.` : `All ${total} websites look healthy.`)
      : `${lanes.critical + lanes.warning} of ${total} websites need attention.`;
  const name = cap(me?.email.split('@')[0] ?? '');

  const siteRows = sites
    .filter((s) => s.lane !== 'healthy')
    .sort((a, b) => LANES.indexOf(a.lane) - LANES.indexOf(b.lane))
    .slice(0, 6);
  const clientOf = (id: string | null) => clients.find((c) => c.id === id)?.name;

  return (
    <div className="ux-page" data-mtab={mtab}>
      <header className="ux-head">
        <div>
          <h1>{greeting()}{name ? `, ${name}` : ''}</h1>
          <p>{headline}<span className="ux-desktop-only"> Health comes from fresh readings only: missing or old readings show as Unknown, never Healthy.</span></p>
        </div>
        <div className="ux-head__actions">
          {role !== 'billing' && <a className="ux-btn ux-btn--ghost" href="/work" onClick={onLinkClick}><ListTodo aria-hidden="true" />{isStaff ? 'Work queue' : 'Issues'}</a>}
          {canAdd && <AddButton kind="website" />}
        </div>
      </header>

      <div className="ux-kpis" style={{ ['--kpi-n' as string]: 6 }}>
        <MetricCard tone="blue" icon={Globe} label="Websites monitored" value={total} href="/websites"
          sub={noFresh ? `${noFresh} without fresh readings` : 'All have fresh readings'} subIcon={noFresh ? CircleAlert : CircleCheck} />
        <MetricCard tone="mint" icon={CircleCheck} label="Healthy" value={lanes.healthy} href="/websites?lane=healthy"
          spark={availSpark} sparkDomain={availDomain} trendLabel="Trend: availability of HTTP checks per hour, last 24 hours"
          sub={total ? `${Math.round((lanes.healthy / total) * 100)}% of websites` : 'No websites yet'} />
        <MetricCard tone="orange" icon={TriangleAlert} label="Warnings" value={lanes.warning} href="/websites?lane=warning"
          sub={lanes.warning ? 'Check before they fail' : 'No warnings'} />
        <MetricCard tone="coral" icon={CircleAlert} label="Critical" value={lanes.critical} href="/websites?lane=critical"
          bars={failedBars} trendLabel="Trend: failed HTTP checks per hour, last 24 hours"
          sub={incidents.length ? `${incidents.length} open incident${incidents.length === 1 ? '' : 's'}` : 'No open incidents'} />
        <MetricCard tone="violet" icon={Lock} label="SSL expiring ≤ 30 days" value={metrics ? expiring.length : '—'}
          sub={!metrics ? 'Loading readings' : expiring.length ? `Soonest in ${expiring[0].days} day${expiring[0].days === 1 ? '' : 's'}` : tls.length ? `Soonest in ${tls[0].days} days` : 'No certificate readings'} subIcon={CalendarClock} />
        <MetricCard tone="aqua" icon={LifeBuoy} label="Open requests" value={openRequests} href={role !== 'billing' ? '/work' : undefined}
          sub={o.requests.awaiting_quote_decision.length ? `${o.requests.awaiting_quote_decision.length} awaiting a quote decision` : 'None awaiting a decision'} />
      </div>

      <div className="ux-grid ux-grid--dash1">
        <Panel title="Needs attention now" sub={`${incidents.length} open incident${incidents.length === 1 ? '' : 's'} · an incident opens after 3 failed checks in a row`}
          className="ux-attn" action={<a className="ux-link ux-viewall" href="/alerts" onClick={onLinkClick}>View all<ArrowRight aria-hidden="true" /></a>}>
          <AttentionQueue incidents={incidents} issues={o.issues} onChanged={() => { void loadIncidents(); void loadOverview(); }} limit={5} />
        </Panel>
        <div className="ux-mobile-only ux-mtabs">
          <ResponsiveTabs label="Dashboard sections" value={mtab} onChange={setMtab} tabs={[
            { key: 'trends', label: 'Trends' }, { key: 'websites', label: 'Websites' },
            { key: 'activity', label: 'Activity' }, { key: 'requests', label: 'Requests', count: openRequests },
          ]} />
        </div>
        <Panel title="Monitoring overview" className="ux-chartpanel ux-mt-trends" id="trends" sub={metrics ? `HTTP checks of all your websites · last ${metrics.window_hours} hours, hourly` : 'Loading…'}
          action={
            <div className="ux-seg" role="group" aria-label="Chart metric">
              <button type="button" aria-pressed={chart === 'availability'} onClick={() => setChart('availability')}><Activity aria-hidden="true" />Availability</button>
              <button type="button" aria-pressed={chart === 'response'} onClick={() => setChart('response')}><Gauge aria-hidden="true" />Response time</button>
            </div>
          }>
          {metrics
            ? chart === 'availability'
              ? <TimeSeriesChart label="Availability per hour, percent of HTTP checks that succeeded" unit="Availability (%)" color="#16a34a"
                  points={series.map((p) => ({ t: p.hour, v: p.availability_pct, n: p.checks }))} format={(v) => `${Math.round(v * 10) / 10}%`} height={256}
                  domain={availDomain} />
              : <TimeSeriesChart label="Average response time per hour, milliseconds" unit="Avg response (ms)" color="#2563eb"
                  points={series.map((p) => ({ t: p.hour, v: p.avg_ms, n: p.checks }))} format={(v) => `${Math.round(v)} ms`} height={256} />
            : <div className="ux-skel" style={{ height: 256 }} aria-hidden="true" />}
          <div className="ux-legendrow">
            <span><i style={{ background: '#16a34a' }} aria-hidden="true" />Availability <b>{fmtPct(totals.availability)}</b></span>
            <span><i style={{ background: '#2563eb' }} aria-hidden="true" />Avg response <b>{fmtMs(totals.avgMs)}</b></span>
            <span><i style={{ background: '#94a3b8' }} aria-hidden="true" />HTTP checks <b>{totals.checks.toLocaleString('en-IN')}</b></span>
            <span className="ux-chartnote">Gaps mean no readings</span>
          </div>
        </Panel>
      </div>

      <div className="ux-grid ux-grid--dash2">
        <Panel title="Top affected websites" className="ux-mt-websites" sub="Availability and response, last 24 hours"
          action={<a className="ux-link ux-viewall" href="/websites" onClick={onLinkClick}>View all<ArrowRight aria-hidden="true" /></a>}>
          {siteRows.length === 0
            ? <p className="ux-note" style={{ marginTop: 0 }}>Every website is healthy right now.</p>
            : (
              <div className="ux-table-wrap">
                <table className="ux-table ux-table--cards ux-top">
                  <thead><tr><th scope="col">Website</th><th scope="col">Health</th><th scope="col" className="num">Uptime</th><th scope="col" className="num">Response</th><th scope="col" className="num">SSL</th></tr></thead>
                  <tbody>
                    {siteRows.map((s) => {
                      const m = metrics?.websites[s.id];
                      const sslDays = m?.tls_days_left;
                      const av = m?.availability_pct;
                      const avTone = av === null || av === undefined ? 'unknown' : av >= 99.5 ? 'healthy' : av >= 97 ? 'warning' : 'critical';
                      const msTone = !m?.avg_ms ? 'unknown' : m.avg_ms < 800 ? 'healthy' : m.avg_ms < 1500 ? 'warning' : 'critical';
                      return (
                        <tr key={s.id}>
                          <td>
                            <span style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                              <Monogram name={s.display_name} size={32} />
                              <span style={{ minWidth: 0 }}>
                                <a href={href({ name: 'website', id: s.id })} onClick={onLinkClick} style={{ overflowWrap: 'anywhere' }}>{s.display_name}</a>
                                {isAgency && clientOf(s.client_id) && <span style={{ display: 'block', fontSize: 12, color: 'var(--ink-3)' }}>{clientOf(s.client_id)}</span>}
                              </span>
                            </span>
                          </td>
                          <td><StatusBadge status={LANE_STATUS[s.lane]} /></td>
                          <td className="num" data-label="Uptime"><span className={`ux-num-pill ux-tint--${avTone}`}>{fmtPct(av, 1)}</span></td>
                          <td className="num" data-label="Response"><span className={`ux-num-pill ux-tint--${msTone}`}>{fmtMs(m?.avg_ms)}</span></td>
                          <td className="num" data-label="SSL">{sslDays === null || sslDays === undefined ? '—' : sslDays < 0 ? 'Expired' : `${sslDays} d`}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
        </Panel>
        <Panel title="Health breakdown" sub="Websites by computed health" className="ux-mt-trends">
          <Donut centerLabel="websites" total={total} segments={LANES.slice().reverse().map((l: HealthLane) => ({ key: l, label: LANE_LABEL[l], value: lanes[l], color: LANE_COLOR[l] }))}
            legendAction={(s) => (lanes[s.key as HealthLane] ? () => navigate(`/websites?lane=${s.key}`) : undefined)} />
          <h3 className="ux-h3">Current problems by area</h3>
          <IssueAreas issues={o.issues} />
        </Panel>
      </div>

      <div className="ux-grid ux-grid--dash4">
        <Panel title="Support requests" className="ux-mt-requests" sub="Workflow stage, separate from website health"
          action={role !== 'billing' ? <a className="ux-link ux-viewall" href="/work" onClick={onLinkClick}>{isStaff ? 'Work queue' : 'Issues'}<ArrowRight aria-hidden="true" /></a> : undefined}>
          <ol className="ux-pipeline" aria-label="Open requests by stage">
            {openByState.map(([s, n]) => (
              <li key={s} data-empty={n === 0 || undefined}>
                <span>{TICKET_LABELS[s]}</span>
                <i aria-hidden="true"><em style={{ width: `${openRequests ? (n / openRequests) * 100 : 0}%` }} /></i>
                <b>{n}</b>
              </li>
            ))}
          </ol>
          {o.requests.awaiting_quote_decision.length > 0 && (
            <>
              <h3 className="ux-h3">{isStaff ? 'Waiting for the customer to decide' : 'Waiting for your decision'}</h3>
              <ul className="ux-list ux-list--compact">
                {o.requests.awaiting_quote_decision.map((t) => (
                  <li key={t.id}>
                    <span className="ux-list__icon ux-tint--info" aria-hidden="true"><LifeBuoy /></span>
                    <div className="ux-list__main">
                      <a className="ux-list__title" href={href({ name: 'work', ticket: t.id })} onClick={onLinkClick}>{t.title}</a>
                      <span className="ux-list__meta">{t.website}</span>
                    </div>
                    {!isStaff && <span className="ux-list__side"><StatusBadge status="info" label="Review quote" /></span>}
                  </li>
                ))}
              </ul>
            </>
          )}
        </Panel>
        <Panel title={isStaff ? 'Your work and renewals' : 'Certificate renewals'} className={isStaff ? 'ux-mt-requests' : 'ux-mt-trends'}
          sub={isStaff ? 'Requests assigned to you, and certificates that expire soonest' : 'Days until each certificate expires'}>
          {isStaff && (
            o.requests.assigned_to_me.length === 0 ? <p className="ux-note" style={{ marginTop: 0, marginBottom: 10 }}>Nothing assigned to you.</p> : (
              <ul className="ux-list ux-list--compact" style={{ marginBottom: 10 }}>
                {o.requests.assigned_to_me.map((t) => (
                  <li key={t.id}>
                    <span className="ux-list__icon ux-tint--investigating" aria-hidden="true"><ListTodo /></span>
                    <div className="ux-list__main">
                      <a className="ux-list__title" href={href({ name: 'work', ticket: t.id })} onClick={onLinkClick}>{t.title}</a>
                      <span className="ux-list__meta">{t.website}</span>
                    </div>
                    <span className="ux-list__side">{TICKET_LABELS[t.state]}</span>
                  </li>
                ))}
              </ul>
            )
          )}
          <CertList items={isStaff ? tls.slice(0, 3) : tls} />
        </Panel>
        <Panel title="Recent activity" className="ux-mt-activity" sub="Incidents and request updates">
          <ActivityTimeline items={metrics?.activity} limit={5} />
        </Panel>
      </div>
      {metrics && <p className="ux-note">Readings updated {since(new Date(metrics.generated_at))}. Times in IST.</p>}
    </div>
  );
}

function IssueAreas({ issues }: { issues: ApiOverview['issues'] }) {
  const kinds: Array<[string, string, string]> = [['http', 'Website (HTTP)', '#2563eb'], ['dns', 'Domain / DNS', '#0891b2'], ['tls', 'SSL certificate', '#7c3aed']];
  const max = Math.max(1, ...kinds.map(([k]) => issues.filter((i) => i.kind === k).length));
  return (
    <ul className="ux-pipeline" aria-label="Failing or warning checks by area">
      {kinds.map(([k, label, c]) => {
        const n = issues.filter((i) => i.kind === k).length;
        return (
          <li key={k} data-empty={n === 0 || undefined}>
            <span>{label}</span>
            <i aria-hidden="true"><em style={{ width: `${(n / max) * 100}%`, background: c }} /></i>
            <b>{n}</b>
          </li>
        );
      })}
    </ul>
  );
}

export function CertList({ items }: { items: Array<{ id: string; host: string; days: number }> }) {
  if (!items.length) return <p className="ux-note" style={{ marginTop: 0 }}>No certificate readings yet.</p>;
  return (
    <ul className="ux-list">
      {items.slice(0, 5).map((t) => {
        const st = t.days < 0 ? 'critical' : t.days <= 14 ? 'critical' : t.days <= 30 ? 'warning' : 'healthy';
        return (
          <li key={t.id}>
            <span className={`ux-list__icon ux-tint--${st}`} aria-hidden="true"><Lock /></span>
            <div className="ux-list__main">
              <a className="ux-list__title" href={href({ name: 'website', id: t.id, tab: 'ssl' })} onClick={onLinkClick}>{t.host}</a>
              <span className="ux-list__meta">{t.days < 0 ? 'Expired' : `Expires in ${t.days} day${t.days === 1 ? '' : 's'}`}</span>
            </div>
            <span className="ux-list__side"><StatusBadge status={st} label={t.days < 0 ? 'Expired' : t.days <= 30 ? 'Renew soon' : 'Valid'} /></span>
          </li>
        );
      })}
    </ul>
  );
}

function DashboardSkeleton() {
  return (
    <div className="ux-page" role="status" aria-label="Loading dashboard">
      <div className="ux-skel" style={{ height: 56, width: '50%', marginBottom: 18 }} />
      <div className="ux-kpis" style={{ ['--kpi-n' as string]: 6 }}>{Array.from({ length: 6 }, (_, i) => <div key={i} className="ux-skel" style={{ height: 112 }} />)}</div>
      <div className="ux-skel" style={{ height: 300 }} />
    </div>
  );
}
