// SCR-07 Website detail: one tab per area, every value with its source and age.
import { useEffect, useState } from 'react';
import { TICKET_LABELS } from '@upfixy/core';
import { api, ApiError, type ApiCheck, type ApiHistoryRow, type ApiIncident, type ApiWebsiteDetail } from '../api';
import { IncidentRows } from './Incidents';
import { useWs } from '../app-context';
import { StatusList, StatusPill, ago, since } from '../components';
import { HELP } from '../help';
import { toSite } from '../live-mapping';
import { href, navigate, onLinkClick } from '../router';

const TABS = [
  ['overview', 'Overview'], ['domain', 'Domain'], ['ssl', 'SSL'], ['errors', 'Errors'],
  ['hosting', 'Hosting'], ['security', 'Security'], ['backups', 'Backups'], ['activity', 'Activity'],
] as const;
type Tab = (typeof TABS)[number][0];
const KIND_OF: Partial<Record<Tab, ApiCheck['kind']>> = { domain: 'dns', ssl: 'tls', errors: 'http' };
const KIND_LABEL: Record<ApiCheck['kind'], string> = { dns: 'Domain (DNS)', tls: 'SSL certificate', http: 'Website response' };
const SEV_LABEL: Record<ApiCheck['severity'], string> = { ok: 'OK', warning: 'Warning', critical: 'Failing', unknown: 'Inconclusive', not_applicable: 'Not applicable' };
const SOURCE: Record<ApiCheck['source'], string> = { public: 'Public check', connector: 'Provider connection', user: 'Reported by you' };

const NOT_CONNECTED: Partial<Record<Tab, { title: string; body: string }>> = {
  hosting: { title: 'Hosting is not connected', body: 'Upfixy can only see what anyone on the internet sees. Connecting a read-only hosting account (cPanel or WHM reseller) adds disk, resource-limit and AutoSSL checks. Connections arrive in a later release.' },
  security: { title: 'Security is not assessed yet', body: 'Public checks don’t cover malware or vulnerable software. This stays “Not assessed”, never “Secure”, until a scanner or hosting connection reports.' },
  backups: { title: 'Backups are not connected', body: 'Upfixy shows backup status only when a hosting or backup provider reports it. Until then we can’t tell whether backups exist, so we don’t claim they do.' },
};

function Readings({ checks }: { checks: ApiCheck[] }) {
  if (!checks.length) return <p className="empty-inline">No readings yet. They appear after the first scheduled check.</p>;
  return (
    <ul className="rows">
      {checks.map((c) => {
        const code = c.result_code.toUpperCase();
        const stale = c.freshness !== 'fresh';
        return (
          <li key={`${c.target_id}-${c.kind}`} className={`row row--${stale ? 'unknown' : c.severity}`}>
            <div className="row__main">
              <span className="host">{c.host}</span>
              <span>{KIND_LABEL[c.kind]}: <strong>{stale ? 'Unknown (old reading)' : SEV_LABEL[c.severity]}</strong>
                {c.severity !== 'ok' && !stale && HELP[code] ? `, ${HELP[code].title}` : ''}</span>
            </div>
            <div className="row__side hint">{SOURCE[c.source]}, {since(new Date(c.observed_at))}</div>
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
  return <dl className="facts">{facts.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>;
}

function History({ id, kind }: { id: string; kind?: ApiCheck['kind'] }) {
  const [rows, setRows] = useState<ApiHistoryRow[] | null>(null);
  useEffect(() => {
    setRows(null);
    api<{ data: ApiHistoryRow[] }>('GET', `websites/${id}/history?limit=50${kind ? `&kind=${kind}` : ''}`)
      .then((r) => setRows(r.data)).catch(() => setRows([]));
  }, [id, kind]);
  if (!rows) return <p className="hint" role="status">Loading history…</p>;
  if (!rows.length) return <p className="empty-inline">No history yet.</p>;
  return <HistoryTable rows={rows} kind={kind} />;
}

function HistoryTable({ rows, kind }: { rows: ApiHistoryRow[]; kind?: ApiCheck['kind'] }) {
  const { role } = useWs();
  const advanced = ['owner', 'admin', 'technician'].includes(role); // §6 "View raw logs / advanced details"
  return (
    <div className="table-wrap" tabIndex={0} role="region" aria-label="Scrollable table">
      <table className="list">
        <caption className="sr-only">Reading history, newest first</caption>
        <thead><tr><th scope="col">When</th><th scope="col">Address</th>{!kind && <th scope="col">Check</th>}<th scope="col">Result</th><th scope="col">Source</th>{advanced && <th scope="col">Evidence</th>}</tr></thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id}>
              <td className="num">{new Date(r.observed_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}</td>
              <td className="host">{r.host}</td>
              {!kind && <td>{KIND_LABEL[r.kind]}</td>}
              <td className={`txt--${r.severity === 'ok' ? 'ok' : r.severity}`}>{SEV_LABEL[r.severity]}{r.severity !== 'ok' ? ` (${r.result_code})` : ''}</td>
              <td>{SOURCE[r.source]}</td>
              {advanced && <td><details className="evidence"><summary>{r.id.slice(0, 8)}</summary><EvidenceFacts r={r} /></details></td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function WebsiteDetail({ id, tab: rawTab }: { id: string; tab?: string }) {
  const { openHelp, openVerify, report, canVerify, role, sites } = useWs();
  const [d, setD] = useState<ApiWebsiteDetail | null>(null);
  const [missing, setMissing] = useState(false);
  const [incidents, setIncidents] = useState<ApiIncident[]>([]);
  const [recheck, setRecheck] = useState<string | null>(null);
  const [polls, setPolls] = useState(0);
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

  async function checkNow() {
    try {
      const r = await api<{ queued: number; message: string }>('POST', `websites/${id}/recheck`);
      setRecheck(r.message);
      if (r.queued > 0) setPolls(12);
    } catch (e) { setRecheck(e instanceof Error ? e.message : String(e)); }
  }

  if (missing) return <div className="page"><h1>Website not found</h1><p>It may have been removed, or you no longer have access. <a href="/websites" onClick={onLinkClick}>Back to websites</a></p></div>;
  if (!d) return <p className="hint" role="status">Loading…</p>;

  const site = toSite({ ...d, hosts: d.targets.map((t) => t.host), open_requests: 0, coverage: d.coverage ?? {} });
  const kind = KIND_OF[tab];
  const checks = kind ? d.checks.filter((c) => c.kind === kind) : d.checks;
  const verified = d.verified_hosts.length > 0;

  return (
    <div className="page">
      <nav className="crumbs" aria-label="Breadcrumb">
        {d.client_id
          ? <><a href={href({ name: 'client', id: d.client_id })} onClick={onLinkClick}>{d.client_name}</a><span aria-hidden="true"> / </span></>
          : <><a href="/websites" onClick={onLinkClick}>Websites</a><span aria-hidden="true"> / </span></>}
      </nav>
      <div className="headline headline--row">
        <div>
          <h1 className="host">{d.display_name}</h1>
          <p className="headline__sub"><StatusPill lane={d.lane} /> {ago(site.lastVerifiedAt, site.lastObservedAt)}</p>
          <p className="hint" role="status">{recheck ?? 'Showing stored results. Use “Check now” to queue a live check.'}</p>
        </div>
        <div className="actions">
          {role !== 'billing' && <button type="button" className="btn btn--quiet" onClick={() => void checkNow()}>Check now</button>}
          {canVerify && !verified && <button type="button" className="btn btn--quiet" onClick={() => openVerify({ id: d.id, name: d.display_name })}>Verify ownership</button>}
          {role !== 'billing' && <button type="button" className="btn" onClick={() => report(d.id, d.display_name)}>Report a problem</button>}
        </div>
      </div>

      <div className="tabbar" role="tablist" aria-label="Website areas">
        {TABS.map(([t, label]) => (
          <a key={t} role="tab" aria-selected={tab === t} href={href({ name: 'website', id, tab: t })}
            onClick={(e) => { e.preventDefault(); navigate(href({ name: 'website', id, tab: t }), { replace: true }); }}>{label}</a>
        ))}
      </div>

      <section className="panel-box" role="tabpanel" aria-label={TABS.find(([t]) => t === tab)![1]}>
        {tab === 'overview' && (
          <div className="split">
            <div>
              {incidents.length > 0 && (
                <>
                  <h2>Incidents</h2>
                  <IncidentRows incidents={incidents} showSite={false} onChanged={() => void loadIncidents()} />
                </>
              )}
              <h2>Status by area</h2>
              <StatusList components={site.components} />
              {site.issues.length > 0 && (
                <>
                  <h2>Problems found</h2>
                  <ul className="rows">
                    {site.issues.map((i) => (
                      <li key={`${i.host}-${i.code}`} className="row row--warning">
                        <div className="row__main"><span className="host">{i.host}</span><span>{i.title}</span></div>
                        <button type="button" className="link" onClick={() => openHelp(i, i.host ?? d.display_name)}>Help me understand</button>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </div>
            <div>
              <h2>Addresses checked</h2>
              <ul className="rows">
                {d.targets.map((t) => (
                  <li key={t.id} className="row">
                    <div className="row__main"><span className="host">{t.url}</span>
                      <span className="hint">Every {Math.round(t.interval_seconds / 60)} min{t.state !== 'active' ? `, ${t.state}` : ''}</span></div>
                    <span className={`tag ${d.verified_hosts.includes(t.host) ? 'tag--ok' : ''}`}>{d.verified_hosts.includes(t.host) ? 'Ownership verified' : 'Public checks only'}</span>
                  </li>
                ))}
              </ul>
              <h2>Requests</h2>
              {d.requests.length === 0 ? <p className="empty-inline">No requests for this website.</p> : (
                <ul className="rows">
                  {d.requests.map((r) => (
                    <li key={r.id} className="row">
                      <div className="row__main"><a href={href({ name: 'work', ticket: r.id })} onClick={onLinkClick}>{r.title}</a></div>
                      <span className="hint">{TICKET_LABELS[r.state]}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        )}
        {kind && (
          <>
            <h2>Latest readings</h2>
            <Readings checks={checks} />
            <h2>History</h2>
            <History id={id} kind={kind} />
          </>
        )}
        {NOT_CONNECTED[tab] && (
          <div className="notice">
            <h2>{NOT_CONNECTED[tab]!.title}</h2>
            <p>{NOT_CONNECTED[tab]!.body}</p>
          </div>
        )}
        {tab === 'activity' && (<><h2>All readings</h2><History id={id} /></>)}
        {!verified && tab !== 'overview' && !NOT_CONNECTED[tab] && (
          <p className="hint">Only public checks are shown. Verify ownership to see private readings from provider connections.</p>
        )}
      </section>
    </div>
  );
}
