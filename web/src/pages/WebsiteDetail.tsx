// UX-04 / SCR-07 Website detail: header, health tiles, tabs. Every value comes from the API with its
// source and age; areas Upfixy cannot see say so ("Not connected", "Not assessed"), never "healthy".
import { useEffect, useMemo, useState } from 'react';
import { TICKET_LABELS } from '@upfixy/core';
import {
  Activity, ArrowRight, BadgeCheck, ExternalLink, FileSearch, Gauge, Globe, HardDrive, History as HistoryIcon, Lightbulb, Lock,
  MessageSquareWarning, Network, RefreshCw, ShieldAlert, ShieldQuestion, Stethoscope,
} from 'lucide-react';
import { api, ApiError, type ApiCheck, type ApiHistoryRow, type ApiIncident, type ApiWebsiteDetail } from '../api';
import { useWs } from '../app-context';
import { ago, since } from '../components';
import { HELP } from '../help';
import { toSite } from '../live-mapping';
import { href, navigate, onLinkClick } from '../router';
import { EmptyState, KebabMenu, MetricCard, Monogram, Panel, StatusBadge, TimeSeriesChart, fmtMs, fmtPct, useMetrics, type UiStatus } from '../ui/kit';
import { LANE_STATUS } from '../ui/blocks';
import { DnsConnections } from './Repairs';
import { ProviderPanel } from './Integrations';

const TABS = [
  ['overview', 'Overview'], ['domain', 'Domain'], ['ssl', 'SSL'], ['errors', 'Errors'],
  ['hosting', 'Hosting'], ['security', 'Security'], ['backups', 'Backups'], ['activity', 'Activity'],
] as const;
type Tab = (typeof TABS)[number][0];
const KIND_OF: Partial<Record<Tab, ApiCheck['kind']>> = { domain: 'dns', ssl: 'tls', errors: 'http' };
const KIND_LABEL: Record<ApiCheck['kind'], string> = { dns: 'Domain (DNS)', tls: 'SSL certificate', http: 'Website response' };
const KIND_ICON = { dns: Network, tls: Lock, http: Globe };
const SEV_LABEL: Record<ApiCheck['severity'], string> = { ok: 'OK', warning: 'Warning', critical: 'Failing', unknown: 'Inconclusive', not_applicable: 'Not applicable' };
const SEV_STATUS: Record<ApiCheck['severity'], UiStatus> = { ok: 'healthy', warning: 'warning', critical: 'critical', unknown: 'unknown', not_applicable: 'unknown' };
const SOURCE: Record<ApiCheck['source'], string> = { public: 'Public check', connector: 'Provider connection', user: 'Reported by you' };
const COMP_STATUS: Record<string, UiStatus> = { ok: 'healthy', warning: 'warning', critical: 'critical', unknown: 'unknown', not_connected: 'not_connected' };

const NOT_CONNECTED: Partial<Record<Tab, { title: string; body: string; icon: typeof HardDrive }>> = {
  hosting: { icon: HardDrive, title: 'Hosting is not connected', body: 'Upfixy sees only what anyone on the internet sees unless a read-only hosting connection (WHM reseller or cPanel) is linked to this website and its ownership is verified. Owners and admins connect providers under Integrations.' },
  security: { icon: ShieldQuestion, title: 'Security is not assessed yet', body: 'Public checks don’t cover malware or vulnerable software. This stays “Not assessed”, never “Secure”, until a scanner or hosting connection reports.' },
  backups: { icon: HardDrive, title: 'Backups are not connected', body: 'Upfixy shows backup status only when a hosting or backup provider reports it. Until then we can’t tell whether backups exist, so we don’t claim they do.' },
};
const fmtWhen = (iso: string) => new Date(iso).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata' });

function Readings({ checks }: { checks: ApiCheck[] }) {
  const { openHelp } = useWs();
  if (!checks.length) return <EmptyState icon={Gauge} title="No readings yet">They appear after the first scheduled check.</EmptyState>;
  return (
    <ul className="ux-list ux-list--compact">
      {checks.map((c) => {
        const code = c.result_code.toUpperCase();
        const stale = c.freshness !== 'fresh';
        const Icon = KIND_ICON[c.kind];
        const st: UiStatus = stale ? 'stale' : SEV_STATUS[c.severity];
        const h = HELP[code];
        return (
          <li key={`${c.target_id}-${c.kind}`}>
            <span className={`ux-list__icon ux-tint--${stale ? 'unknown' : st}`} aria-hidden="true"><Icon /></span>
            <div className="ux-list__main">
              <span className="ux-list__title">{KIND_LABEL[c.kind]}{c.severity !== 'ok' && !stale && h ? `: ${h.title}` : ''}</span>
              <span className="ux-list__meta">{c.host} · {SOURCE[c.source]} · {since(new Date(c.observed_at))}</span>
              {c.severity !== 'ok' && !stale && (
                <span className="ux-list__actions">
                  <button type="button" className="ux-link" onClick={() => openHelp({ code, title: h?.title ?? code, observedAt: new Date(c.observed_at), host: c.host }, c.host)}>Help me understand</button>
                </span>
              )}
            </div>
            <span className="ux-list__side"><StatusBadge status={st} label={stale ? 'Old reading' : SEV_LABEL[c.severity]} /></span>
          </li>
        );
      })}
    </ul>
  );
}

/** Key facts from a reading's stored evidence, in plain terms (advanced view: staff and solo owners). */
function EvidenceFacts({ r }: { r: ApiHistoryRow }) {
  const d = (r.detail ?? {}) as Record<string, any>;
  const facts: Array<[string, string]> = [['Evidence ID', r.id], ['Cycle', String(r.cycle_no)], ['Checked from', r.region ?? 'n/a']];
  if (r.kind === 'http') {
    for (const [i, h] of (d.chain ?? []).entries()) {
      facts.push([i === 0 ? 'Request' : `Redirect ${i}`, `${h.url} → ${h.status ?? h.error ?? h.blocked}${h.ms ? ` in ${h.ms} ms` : ''}`]);
    }
    if (d.ms_ttfb !== undefined) facts.push(['Time to first byte', `${d.ms_ttfb} ms`]);
  }
  if (r.kind === 'tls' && d.not_after) {
    facts.push(['Issued to', `${d.subject_cn ?? '?'}${d.sans?.length ? ` (${d.sans.join(', ')})` : ''}`], ['Issued by', d.issuer_cn ?? '?'],
      ['Valid until', `${new Date(d.not_after).toLocaleDateString('en-IN', { dateStyle: 'medium' })} (${d.days_left} days)`], ['Fingerprint', String(d.sha256 ?? '').slice(0, 23) + '…']);
  }
  if (r.kind === 'dns') {
    for (const [ip, x] of Object.entries<any>(d.resolvers ?? {})) facts.push([`Resolver ${ip}`, x.status === 'answered' ? `${x.rcode}, ${x.addresses} address(es)` : x.status]);
    if (d.dnssec) facts.push(['DNSSEC', d.dnssec === 'validated' ? 'Validated by resolvers' : 'Not validated (unsigned or not checked)']);
    if (d.disagreement) facts.push(['Note', 'Resolvers disagreed; this is not treated as a cause.']);
  }
  if (d.attempts > 1) facts.push(['Attempts this cycle', String(d.attempts)]);
  return <dl className="kv">{facts.map(([k, v]) => <div key={k} style={{ display: 'contents' }}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>;
}

function History({ id, kind }: { id: string; kind?: ApiCheck['kind'] }) {
  const [rows, setRows] = useState<ApiHistoryRow[] | null>(null);
  useEffect(() => {
    setRows(null);
    api<{ data: ApiHistoryRow[] }>('GET', `websites/${id}/history?limit=50${kind ? `&kind=${kind}` : ''}`)
      .then((r) => setRows(r.data)).catch(() => setRows([]));
  }, [id, kind]);
  if (!rows) return <div className="ux-skel" style={{ height: 160 }} role="status" aria-label="Loading history" />;
  if (!rows.length) return <EmptyState icon={HistoryIcon} title="No history yet">Readings are kept for each scheduled check.</EmptyState>;
  return <HistoryTable rows={rows} kind={kind} />;
}

function HistoryTable({ rows, kind }: { rows: ApiHistoryRow[]; kind?: ApiCheck['kind'] }) {
  const { role } = useWs();
  const advanced = ['owner', 'admin', 'technician'].includes(role); // §6 "View raw logs / advanced details"
  return (
    <div className="ux-table-wrap" tabIndex={0} role="region" aria-label="Reading history">
      <table className="ux-table ux-table--cards">
        <caption className="sr-only">Reading history, newest first</caption>
        <thead><tr><th scope="col">When</th><th scope="col">Address</th>{!kind && <th scope="col">Check</th>}<th scope="col">Result</th><th scope="col">Source</th>{advanced && <th scope="col">Evidence</th>}</tr></thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id}>
              <td style={{ whiteSpace: 'nowrap' }}>{fmtWhen(r.observed_at)}</td>
              <td data-label="Address">{r.host}</td>
              {!kind && <td data-label="Check">{KIND_LABEL[r.kind]}</td>}
              <td><StatusBadge status={SEV_STATUS[r.severity]} label={`${SEV_LABEL[r.severity]}${r.severity !== 'ok' ? ` (${r.result_code})` : ''}`} /></td>
              <td data-label="Source">{SOURCE[r.source]}</td>
              {advanced && <td><details className="ux-details"><summary>{r.id.slice(0, 8)}</summary><EvidenceFacts r={r} /></details></td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Diagnoses({ id }: { id: string }) {
  const [rows, setRows] = useState<Array<{ id: string; status: string; created_at: string; observations: number; ai_status: string | null }> | null>(null);
  useEffect(() => { api<{ data: typeof rows }>('GET', `websites/${id}/diagnostics`).then((r) => setRows(r.data)).catch(() => setRows([])); }, [id]);
  if (!rows) return <div className="ux-skel" style={{ height: 80 }} aria-hidden="true" />;
  if (!rows.length) return <p className="ux-note" style={{ marginTop: 0 }}>No diagnoses yet. “Analyze &amp; find solution” creates one from the latest readings.</p>;
  return (
    <ul className="ux-list ux-list--compact">
      {rows.slice(0, 4).map((r) => (
        <li key={r.id}>
          <span className="ux-list__icon ux-tint--investigating" aria-hidden="true"><Stethoscope /></span>
          <div className="ux-list__main">
            <a className="ux-list__title" href={href({ name: 'diagnostic', id: r.id })} onClick={onLinkClick}>Diagnosis · {r.observations} measured {r.observations === 1 ? 'problem' : 'problems'}</a>
            <span className="ux-list__meta">{fmtWhen(r.created_at)}{r.ai_status === 'succeeded' ? ' · with AI explanation' : ''}</span>
          </div>
        </li>
      ))}
    </ul>
  );
}

export default function WebsiteDetail({ id, tab: rawTab }: { id: string; tab?: string }) {
  const { openHelp, openVerify, report, canVerify, role, sites, workspace } = useWs();
  const [d, setD] = useState<ApiWebsiteDetail | null>(null);
  const [missing, setMissing] = useState(false);
  const [incidents, setIncidents] = useState<ApiIncident[]>([]);
  const [recheck, setRecheck] = useState<string | null>(null);
  const [polls, setPolls] = useState(0);
  const [busy, setBusy] = useState(false);
  const metrics = useMetrics(workspace?.id);
  const tab: Tab = (TABS.find(([t]) => t === rawTab)?.[0]) ?? 'overview';

  const load = () => api<ApiWebsiteDetail>('GET', `websites/${id}`).then(setD)
    .catch((e) => { if (e instanceof ApiError && e.status === 404) setMissing(true); });
  const loadIncidents = () => api<{ data: ApiIncident[] }>('GET', `incidents?status=all&website_id=${id}`)
    .then((r) => setIncidents(r.data.slice(0, 5))).catch(() => undefined);
  useEffect(() => { setMissing(false); setRecheck(null); void load(); void loadIncidents(); }, [id, sites]); // eslint-disable-line react-hooks/exhaustive-deps

  // After "Check now", refresh every 10 s for up to 2 minutes so the new result appears (KB-003).
  useEffect(() => {
    if (polls <= 0) return;
    const t = window.setTimeout(() => { void load(); setPolls(polls - 1); }, 10_000);
    return () => window.clearTimeout(t);
  }, [polls]); // eslint-disable-line react-hooks/exhaustive-deps

  const m = metrics?.websites[id];
  const series = useMemo(() => (metrics && m ? metrics.series.map((p, i) => ({ t: p.hour, v: m.ms_series[i] ?? null })) : []), [metrics, m]);

  async function analyze() {
    setBusy(true);
    try {
      const r = await api<{ id: string }>('POST', `websites/${id}/diagnose`);
      navigate({ name: 'diagnostic', id: r.id });
    } catch (e) { setRecheck(e instanceof Error ? e.message : String(e)); setBusy(false); }
  }
  async function checkNow() {
    try {
      const r = await api<{ queued: number; message: string }>('POST', `websites/${id}/recheck`);
      setRecheck(r.message);
      if (r.queued > 0) setPolls(12);
    } catch (e) { setRecheck(e instanceof Error ? e.message : String(e)); }
  }

  if (missing) {
    return <EmptyState icon={Globe} title="Website not found">It may have been removed, or you no longer have access. <a className="ux-link" href="/websites" onClick={onLinkClick}>Back to websites</a></EmptyState>;
  }
  if (!d) return <div className="ux-page" role="status" aria-label="Loading website"><div className="ux-skel" style={{ height: 120, marginBottom: 16 }} /><div className="ux-skel" style={{ height: 110 }} /></div>;

  const site = toSite({ ...d, hosts: d.targets.map((t) => t.host), open_requests: 0, coverage: d.coverage ?? {} });
  const kind = KIND_OF[tab];
  const checks = kind ? d.checks.filter((c) => c.kind === kind) : d.checks;
  const verified = d.verified_hosts.length > 0;
  const canAnalyze = ['owner', 'admin', 'technician', 'client_owner'].includes(role);
  const hasFresh = d.checks.some((c) => c.freshness === 'fresh');
  const top = site.issues[0];
  const ssl = site.components.ssl;
  const sslDays = ssl.state !== 'unknown' ? m?.tls_days_left ?? null : null;
  const tabHref = (t: Tab) => href({ name: 'website', id, tab: t });

  return (
    <div className="ux-page">
      <nav className="ux-crumbs" aria-label="Breadcrumb">
        <a href="/websites" onClick={onLinkClick}>Websites</a>
        {d.client_id && <><span aria-hidden="true">/</span><a href={href({ name: 'client', id: d.client_id })} onClick={onLinkClick}>{d.client_name}</a></>}
        <span aria-hidden="true">/</span><span aria-current="page">{d.display_name}</span>
      </nav>

      <header className="ux-sitehead">
        <div className="ux-sitehead__id">
          <Monogram name={d.display_name} size={56} />
          <div style={{ minWidth: 0 }}>
            <h1>{d.display_name}<a className="ux-extlink" href={d.targets[0]?.url ?? `https://${d.display_name}/`} target="_blank" rel="noopener noreferrer" aria-label={`Open ${d.display_name} in a new tab`}><ExternalLink aria-hidden="true" /></a></h1>
            <div className="ux-sitehead__meta">
              <StatusBadge status={LANE_STATUS[d.lane]} />
              <StatusBadge status={verified ? 'healthy' : 'not_connected'} label={verified ? 'Ownership verified' : 'Public checks only'} />
              <span className={site.lastVerifiedAt ? undefined : 'ux-stale'}>{ago(site.lastVerifiedAt, site.lastObservedAt)}</span>
            </div>
          </div>
        </div>
        <div className="ux-sitehead__actions">
          {role !== 'billing' && <button type="button" className="ux-btn ux-btn--ghost" onClick={() => void checkNow()}><RefreshCw aria-hidden="true" />Check now</button>}
          {top && <button type="button" className="ux-btn ux-btn--soft ux-desktop-only" onClick={() => openHelp(top, top.host ?? d.display_name)}><Lightbulb aria-hidden="true" />Help me understand</button>}
          {canAnalyze && (hasFresh
            ? <button type="button" className="ux-btn ux-btn--primary" disabled={busy} onClick={() => void analyze()}><Stethoscope aria-hidden="true" />Analyze &amp; find solution</button>
            : <span className="ux-note" style={{ margin: 0 }}>Analyze needs fresh readings: use Check now first.</span>)}
          <KebabMenu label={`More actions for ${d.display_name}`} items={[
            canVerify && !verified && { label: 'Verify ownership', icon: BadgeCheck, onSelect: () => openVerify({ id: d.id, name: d.display_name }) },
            role !== 'billing' && { label: 'Report a problem', icon: MessageSquareWarning, onSelect: () => report(d.id, d.display_name) },
            { label: 'Reading history', icon: FileSearch, onSelect: () => navigate(tabHref('activity'), { replace: true }) },
          ]} />
        </div>
        <p className="ux-sitehead__status" role="status">{recheck ?? 'Showing stored results. “Check now” queues a live check.'}</p>
      </header>

      <div className="ux-kpis ux-kpis--strip" style={{ ['--kpi-n' as string]: 6 }} tabIndex={0} role="region" aria-label="Health by area">
        <MetricCard tone="mint" icon={Activity} label="Uptime 24 h" value={fmtPct(m?.availability_pct, 2)} sub={m ? `${m.http_checks} HTTP checks` : 'No readings yet'} />
        <MetricCard tone="blue" icon={Gauge} label="Response time" value={fmtMs(m?.last_ms)} spark={m?.ms_series} trendLabel="Trend: response time per hour, last 24 hours"
          sub={m?.avg_ms ? `Average ${fmtMs(m.avg_ms)}` : 'No readings yet'} />
        <MetricCard tone="orange" icon={Lock} label="SSL certificate" value={sslDays === null ? ssl.label : sslDays < 0 ? 'Expired' : `${sslDays} days`} sub={sslDays === null ? 'No certificate reading' : 'Until it expires'} />
        <MetricCard tone="aqua" icon={Network} label="Domain (DNS)" value={site.components.domain.label} sub={site.components.domain.state === 'ok' ? 'Answers from public resolvers' : 'See the Domain tab'} />
        <MetricCard tone="violet" icon={ShieldQuestion} label="Security" value="Not assessed" sub="Public checks don’t scan for malware" />
        <MetricCard tone="coral" icon={HardDrive} label="Backups" value="Not connected" sub="No backup provider linked" />
      </div>

      <div className="ux-tabbar" role="tablist" aria-label="Website areas">
        {TABS.map(([t, label]) => (
          <a key={t} role="tab" aria-selected={tab === t} href={tabHref(t)}
            onClick={(e) => { e.preventDefault(); navigate(tabHref(t), { replace: true }); }}>{label}</a>
        ))}
      </div>

      <div role="tabpanel" aria-label={TABS.find(([t]) => t === tab)![1]}>
        {tab === 'overview' && (
          <div className="ux-grid ux-grid--dash1">
            <div style={{ display: 'grid', gap: 16, alignContent: 'start', minWidth: 0 }}>
              <Panel title="Problems found" sub={site.issues.length ? 'From the latest fresh readings' : 'Nothing failing in the latest readings'}>
                {site.issues.length === 0 ? <EmptyState icon={BadgeCheck} title="No current problems">Checks will keep running every few minutes.</EmptyState> : (
                  <ul className="ux-list ux-list--compact">
                    {site.issues.map((i) => {
                      const sev = d.checks.find((c) => c.result_code.toUpperCase() === i.code && (!i.host || c.host === i.host))?.severity;
                      const st: UiStatus = sev === 'critical' ? 'critical' : 'warning';
                      return (
                        <li key={`${i.host}-${i.code}`}>
                          <span className={`ux-list__icon ux-tint--${st}`} aria-hidden="true">{st === 'critical' ? <ShieldAlert /> : <Lightbulb />}</span>
                          <div className="ux-list__main">
                            <span className="ux-list__title">{i.title}</span>
                            <span className="ux-list__meta">{i.host} · observed {since(i.observedAt)}</span>
                            <span className="ux-list__actions">
                              <button type="button" className="ux-link" onClick={() => openHelp(i, i.host ?? d.display_name)}>Help me understand</button>
                              {canAnalyze && hasFresh && <button type="button" className="ux-link" disabled={busy} onClick={() => void analyze()}>Analyze &amp; find solution</button>}
                            </span>
                          </div>
                          <span className="ux-list__side"><StatusBadge status={st} /></span>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </Panel>
              <Panel title="Response time" className="ux-chartpanel" sub={metrics ? `Last ${metrics.window_hours} hours · hourly average of HTTP checks · gaps mean no readings` : 'Loading…'}>
                {metrics ? (m && series.some((p) => p.v !== null)
                  ? <TimeSeriesChart label={`Average response time per hour for ${d.display_name}, milliseconds`} unit="Avg response (ms)" color="#7c3aed" points={series} format={(v) => `${Math.round(v)} ms`} height={220} />
                  : <EmptyState icon={Gauge} title="No readings in this period">The chart appears once HTTP checks have run.</EmptyState>)
                  : <div className="ux-skel" style={{ height: 220 }} aria-hidden="true" />}
              </Panel>
              {incidents.length > 0 && (
                <Panel title="Incidents" sub="Opened after 3 failed checks in a row; resolved after 2 good ones">
                  <ul className="ux-list ux-list--compact">
                    {incidents.map((i) => (
                      <li key={i.id}>
                        <span className={`ux-list__icon ux-tint--${i.status === 'open' ? 'critical' : 'healthy'}`} aria-hidden="true">{i.status === 'open' ? <ShieldAlert /> : <BadgeCheck />}</span>
                        <div className="ux-list__main">
                          <span className="ux-list__title">{i.title}</span>
                          <span className="ux-list__meta">{i.status === 'open' ? `Failing since ${fmtWhen(i.first_failure_at)}` : `Resolved ${fmtWhen(i.resolved_at!)}`}{i.acknowledged_at ? ' · acknowledged' : ''}</span>
                        </div>
                        <span className="ux-list__side"><StatusBadge status={i.status === 'open' ? 'critical' : 'healthy'} label={i.status === 'open' ? 'Open' : 'Resolved'} /></span>
                      </li>
                    ))}
                  </ul>
                </Panel>
              )}
            </div>
            <div className="ux-rail">
              <Panel title="Status by area" sub="What Upfixy can see, and what it can’t">
                <ul className="ux-arealist">
                  {([['Domain', 'domain', Network], ['SSL', 'ssl', Lock], ['Errors', 'errors', Globe], ['Hosting', 'hosting', HardDrive], ['Security', 'security', ShieldAlert], ['Backup', 'backup', HardDrive]] as const).map(([label, key, Icon]) => {
                    const c = site.components[key];
                    return (
                      <li key={key}><Icon aria-hidden="true" /><span>{label}</span><StatusBadge status={COMP_STATUS[c.state] ?? 'unknown'} label={c.label} /></li>
                    );
                  })}
                </ul>
              </Panel>
              <Panel title="Addresses checked">
                <ul className="ux-list ux-list--compact">
                  {d.targets.map((t) => (
                    <li key={t.id}>
                      <span className="ux-list__icon ux-tint--info" aria-hidden="true"><Globe /></span>
                      <div className="ux-list__main"><span className="ux-list__title" style={{ overflowWrap: 'anywhere' }}>{t.url}</span>
                        <span className="ux-list__meta">Every {Math.round(t.interval_seconds / 60)} min{t.state !== 'active' ? ` · ${t.state.replace('_', ' ')}` : ''}</span></div>
                      <span className="ux-list__side"><StatusBadge status={d.verified_hosts.includes(t.host) ? 'healthy' : 'not_connected'} label={d.verified_hosts.includes(t.host) ? 'Verified' : 'Public only'} /></span>
                    </li>
                  ))}
                </ul>
              </Panel>
              <Panel title="Support requests" action={role !== 'billing' ? <a className="ux-link ux-viewall" href="/work" onClick={onLinkClick}>All<ArrowRight aria-hidden="true" /></a> : undefined}>
                {d.requests.length === 0 ? <p className="ux-note" style={{ marginTop: 0 }}>No requests for this website.</p> : (
                  <ul className="ux-list ux-list--compact">
                    {d.requests.map((r) => (
                      <li key={r.id}>
                        <span className="ux-list__icon ux-tint--investigating" aria-hidden="true"><MessageSquareWarning /></span>
                        <div className="ux-list__main"><a className="ux-list__title" href={href({ name: 'work', ticket: r.id })} onClick={onLinkClick}>{r.title}</a>
                          <span className="ux-list__meta">{TICKET_LABELS[r.state]} · updated {since(new Date(r.updated_at))}</span></div>
                      </li>
                    ))}
                  </ul>
                )}
              </Panel>
              <Panel title="Diagnoses"><Diagnoses id={id} /></Panel>
            </div>
          </div>
        )}
        {kind && (
          <div style={{ display: 'grid', gap: 16 }}>
            <Panel title="Latest readings" sub="Newest result for each address"><Readings checks={checks} /></Panel>
            {tab === 'domain' && <ProviderPanel websiteId={id} kinds={['cloudflare']} />}
            {tab === 'ssl' && <ProviderPanel websiteId={id} kinds={['whm_reseller', 'cpanel']} />}
            <Panel title="History" sub="Every stored reading, newest first"><History id={id} kind={kind} /></Panel>
            {tab === 'domain' && ['owner', 'admin', 'technician'].includes(role) && <DnsConnections websiteId={id} />}
          </div>
        )}
        {NOT_CONNECTED[tab] && (() => {
          const n = NOT_CONNECTED[tab]!;
          const note = (
            <section className="ux-panel ux-notconnected" aria-labelledby="nc-h">
              <span className="ux-notconnected__icon" aria-hidden="true"><n.icon /></span>
              <div>
                <h2 id="nc-h">{n.title}</h2>
                <p>{n.body}</p>
                {tab === 'hosting' && ['owner', 'admin'].includes(role) && <a className="ux-btn ux-btn--ghost ux-btn--sm" href="/integrations" onClick={onLinkClick}>Go to Integrations<ArrowRight aria-hidden="true" /></a>}
              </div>
            </section>
          );
          return tab === 'hosting' ? <ProviderPanel websiteId={id} kinds={['whm_reseller', 'cpanel']} fallback={note} /> : note;
        })()}
        {tab === 'activity' && <Panel title="All readings" sub="Every check for every address, newest first"><History id={id} /></Panel>}
        {!verified && tab !== 'overview' && !NOT_CONNECTED[tab] && (
          <p className="ux-note">Only public checks are shown. Verify ownership to see private readings from provider connections.</p>
        )}
      </div>
    </div>
  );
}
