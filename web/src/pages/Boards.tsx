// UX-02 Agency client board and UX-03 website board (BRD-001/002/004).
// Lanes are COMPUTED health and never draggable; support workflow appears only
// as separate request badges. Search, lane filters, saved views and the list
// alternative are unchanged in behaviour.
import { useEffect, useMemo, useRef, useState } from 'react';
import type { HealthLane } from '@upfixy/core';
import {
  Activity, Bell, Building2, CircleAlert, CircleCheck, CircleHelp, Globe, LayoutGrid, LifeBuoy, List, Lock, Microscope,
  Network, Plug, Plus, ShieldCheck, Stethoscope, TriangleAlert, UserRound, Wallet, HardDrive, Users, ArrowRight,
} from 'lucide-react';
import { api, ApiError, rupees, type ApiIncident, type ApiNotification, type ApiOverview, type ApiSavedView, type ApiSiteMetrics, type PaymentStatus } from '../api';
import { useWs } from '../app-context';
import { LANES, LANE_LABEL, ago, since, worstComponents } from '../components';
import type { Client, Component, Issue, Site } from '../demo-data';
import { filterClients, filterSites, isFiltered, laneCounts, type Filters } from '../filters';
import { toClient, toSite } from '../live-mapping';
import { href, navigate, onLinkClick } from '../router';
import {
  Accordion, EmptyState, MetricCard, Monogram, Panel, Sparkline, StatusBadge, TimeSeriesChart, fmtMs, fmtPct, useMetrics, type UiStatus,
} from '../ui/kit';
import { ActivityTimeline, AttentionQueue, LANE_STATUS } from '../ui/blocks';
import { CertList } from './Overview';

type Board = 'clients' | 'websites';

function initialFilters(): Filters {
  const q = new URLSearchParams(window.location.search);
  const lane = q.get('lane') as HealthLane | null;
  return {
    ...(lane && (LANES as string[]).includes(lane) ? { lanes: [lane] } : {}),
    ...(q.get('q') ? { q: q.get('q')! } : {}),
  };
}

const COMP_STATUS: Record<Component['state'], UiStatus> = { ok: 'healthy', warning: 'warning', critical: 'critical', unknown: 'unknown', not_connected: 'not_connected' };
const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

// ------------------------------------------------------------------ toolbar (legacy, used by the work queue until UX-04)
/** Search, lane chips, client filter, layout and saved views. */
function Toolbar({ board, filters, setFilters, counts, shown, total, extra }: {
  board: Board | 'work'; filters: Filters; setFilters: (f: Filters) => void;
  counts?: Record<HealthLane, number>; shown: number; total: number; extra?: React.ReactNode;
}) {
  const views = useSavedViews(board, filters, setFilters);
  return (
    <div className="filters">
      <div className="filters__row">
        <label className="search">
          <span className="sr-only">Search</span>
          <input type="search" placeholder={board === 'work' ? 'Search requests' : 'Search'}
            value={filters.q ?? ''} onChange={(e) => { setFilters({ ...filters, q: e.target.value }); views.clear(); }} />
        </label>
        {extra}
        {views.control('views', 'btn btn--quiet btn--sm')}
      </div>
      {counts && <LaneFilter counts={counts} selected={filters.lanes ?? []} onChange={(lanes) => { setFilters({ ...filters, lanes }); views.clear(); }} />}
      <p className="hint" role="status">
        {isFiltered(filters) ? `Showing ${shown} of ${total}. ` : ''}
        {isFiltered(filters) && <button type="button" className="link" onClick={() => { setFilters({ layout: filters.layout }); views.clear(); }}>Clear filters</button>}
      </p>
    </div>
  );
}
export { Toolbar };

/** Saved views (BRD-004): select, save, delete. */
function useSavedViews(board: Board | 'work', filters: Filters, setFilters: (f: Filters) => void) {
  const { setError } = useWs();
  const [views, setViews] = useState<ApiSavedView[]>([]);
  const [active, setActive] = useState('');
  const load = () => api<{ data: ApiSavedView[] }>('GET', `views?board=${board}`).then((r) => setViews(r.data)).catch(() => undefined);
  useEffect(() => { void load(); }, [board]); // eslint-disable-line react-hooks/exhaustive-deps
  async function save() {
    const name = window.prompt('Name this view (for example "Needs attention")')?.trim();
    if (!name) return;
    try {
      const r = await api<{ id: string }>('POST', 'views', { board, name, filters });
      await load(); setActive(r.id);
    } catch (e) { setError(e instanceof ApiError ? e.message : String(e)); }
  }
  async function remove() {
    const v = views.find((x) => x.id === active);
    if (!v || !window.confirm(`Delete the view "${v.name}"?`)) return;
    await api('DELETE', `views/${v.id}`).catch((e) => setError(e.message));
    setActive(''); await load();
  }
  const control = (selectClass: string, btnClass: string) => (
    <>
      <label className={selectClass}>
        <span className="sr-only">Saved views</span>
        <select className={selectClass === 'views' ? undefined : 'ux-select'} value={active} onChange={(e) => {
          const v = views.find((x) => x.id === e.target.value);
          setActive(e.target.value);
          setFilters(v ? { ...v.filters, layout: filters.layout } : { layout: filters.layout });
        }}>
          <option value="">{views.length ? 'Saved views' : 'No saved views'}</option>
          {views.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
        </select>
      </label>
      {active
        ? <button type="button" className={btnClass} onClick={remove}>Delete view</button>
        : isFiltered(filters) && <button type="button" className={btnClass} onClick={save}>Save view</button>}
    </>
  );
  return { control, clear: () => setActive('') };
}

/** Lane filter (desktop): toggle lanes; counts are totals before lane filtering. */
function LaneFilter({ counts, selected, onChange }: { counts: Record<HealthLane, number>; selected: HealthLane[]; onChange: (l: HealthLane[]) => void }) {
  const total = LANES.reduce((a, l) => a + counts[l], 0);
  return (
    <div className="ux-tabs ux-desktop-only" role="group" aria-label="Show health">
      <button type="button" className="ux-tab" aria-pressed={selected.length === 0} onClick={() => onChange([])}>All<span className="ux-count">{total}</span></button>
      {BOARD_ORDER.map((l) => {
        const on = selected.includes(l);
        const Icon = LANE_ICON[l];
        return (
          <button key={l} type="button" className={`ux-tab ux-tab--${l}`} aria-pressed={on}
            onClick={() => onChange(on ? selected.filter((x) => x !== l) : [...selected, l])}>
            <Icon aria-hidden="true" />{LANE_LABEL[l]}<span className="ux-count">{counts[l]}</span>
          </button>
        );
      })}
    </div>
  );
}

const BOARD_ORDER: HealthLane[] = ['healthy', 'warning', 'critical', 'unknown', 'needs_setup'];
const URGENCY: HealthLane[] = ['critical', 'warning', 'unknown', 'needs_setup', 'healthy'];
const LANE_ICON: Record<HealthLane, typeof CircleCheck> = { healthy: CircleCheck, warning: TriangleAlert, critical: CircleAlert, unknown: CircleHelp, needs_setup: Plus };

/** Board columns on desktop. Unknown and Needs setup share one slate column (both mean "no fresh readings"); each card still says which. */
const COLUMNS: Array<{ key: string; lanes: HealthLane[]; title: string; hint: string; tone: HealthLane }> = [
  { key: 'healthy', lanes: ['healthy'], title: 'Healthy', hint: 'All fresh checks passing', tone: 'healthy' },
  { key: 'warning', lanes: ['warning'], title: 'Warning', hint: 'Needs a look soon', tone: 'warning' },
  { key: 'critical', lanes: ['critical'], title: 'Critical', hint: 'Failing now', tone: 'critical' },
  { key: 'unknown', lanes: ['unknown', 'needs_setup'], title: 'No fresh readings', hint: 'Unknown or not set up yet', tone: 'unknown' },
];

/** Cards rendered per column before "Show all": keeps a 200-card board fast on phones (NFR-02). */
const LANE_PAGE = 6;

/**
 * Health board. Desktop: four columns. Phones: ONE lane at a time with a lane
 * selector (never tiny side-by-side columns).
 */
function HealthColumns<T extends { lane: HealthLane; id: string }>({ items, render, noun, lanes, counts }: {
  items: T[]; render: (t: T) => React.ReactNode; noun: string; lanes?: HealthLane[]; counts: Record<HealthLane, number>;
}) {
  const [open, setOpen] = useState<Record<string, boolean>>({});
  // Phones: the user's pick, else a deep-linked lane, else the most urgent non-empty lane.
  const [chosen, setMobileLane] = useState<HealthLane | null>(null);
  const mobileLane: HealthLane = chosen ?? lanes?.[0] ?? URGENCY.find((l) => counts[l] > 0) ?? 'healthy';
  const cols = COLUMNS.filter((c) => !lanes?.length || c.lanes.some((l) => lanes.includes(l)));
  const mobileItems = items.filter((i) => i.lane === mobileLane);
  return (
    <>
      <div className="ux-mobile-only ux-lanepick" role="group" aria-label="Show lane">
        <div className="ux-tabs ux-tabs--scroll">
          {URGENCY.filter((l) => !lanes?.length || lanes.includes(l)).map((l) => {
            const Icon = LANE_ICON[l];
            return (
              <button key={l} type="button" className={`ux-tab ux-tab--${l}`} aria-pressed={mobileLane === l} onClick={() => setMobileLane(l)}>
                <Icon aria-hidden="true" />{LANE_LABEL[l]}<span className="ux-count">{counts[l]}</span>
              </button>
            );
          })}
        </div>
      </div>
      <div className="ux-mobile-only ux-mlane" role={mobileItems.length ? 'list' : undefined} aria-label={`${noun}: ${LANE_LABEL[mobileLane]}`}>
        {mobileItems.length
          ? (open.m ? mobileItems : mobileItems.slice(0, LANE_PAGE)).map((i) => <div role="listitem" key={i.id}>{render(i)}</div>)
          : <LaneEmpty lane={mobileLane} noun={noun} />}
        {!open.m && mobileItems.length > LANE_PAGE && (
          <button type="button" className="ux-btn ux-btn--ghost ux-more" onClick={() => setOpen({ ...open, m: true })}>Show all {mobileItems.length} {LANE_LABEL[mobileLane].toLowerCase()}</button>
        )}
      </div>
      <div className="ux-board ux-desktop-only" style={{ ['--lanes' as string]: cols.length }} role="list" aria-label={`${noun} by health`}>
        {cols.map((c) => {
          const inLane = items.filter((i) => c.lanes.includes(i.lane));
          const visible = open[c.key] ? inLane : inLane.slice(0, LANE_PAGE);
          const Icon = LANE_ICON[c.tone];
          return (
            <section key={c.key} className={`ux-lane ux-lane--${c.tone}`} role="listitem" aria-label={`${c.title}: ${inLane.length}`}>
              <header className="ux-lane__head">
                <span className="ux-lane__icon" aria-hidden="true"><Icon /></span>
                <div style={{ flex: 1, minWidth: 0 }}><h2>{c.title}</h2><p>{c.hint}</p></div>
                <span className="ux-count">{inLane.length}</span>
              </header>
              {inLane.length ? visible.map((i) => <div key={i.id}>{render(i)}</div>) : <LaneEmpty lane={c.tone} noun={noun} />}
              {inLane.length > visible.length && (
                <button type="button" className="ux-btn ux-btn--ghost ux-more" onClick={() => setOpen({ ...open, [c.key]: true })}>
                  Show all {inLane.length}
                </button>
              )}
            </section>
          );
        })}
      </div>
    </>
  );
}

function LaneEmpty({ lane, noun }: { lane: HealthLane; noun: string }) {
  const Icon = LANE_ICON[lane];
  const n = noun.toLowerCase();
  const text: Record<HealthLane, string> = {
    healthy: `No ${n} are fully healthy right now.`,
    warning: `No ${n} with warnings.`,
    critical: `No ${n} failing. Great!`,
    unknown: `Every ${n.replace(/s$/, '')} has fresh readings.`,
    needs_setup: `Nothing waiting for first checks.`,
  };
  return <div className="ux-lane__empty"><Icon aria-hidden="true" /><strong>All clear</strong>{text[lane]}</div>;
}

// ------------------------------------------------------------------ add dialog
export function AddButton({ kind, variant = 'primary' }: { kind: 'website' | 'client'; variant?: 'primary' | 'ghost' }) {
  const { clients, isAgency, reload } = useWs();
  const ref = useRef<HTMLDialogElement>(null);
  const [value, setValue] = useState('');
  const [client, setClient] = useState('');
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (kind === 'client') {
        await api('POST', 'clients', { name: value });
        setMsg({ kind: 'ok', text: `Added ${value}.` });
      } else {
        const r = await api<{ id: string; target: { host: string }; note: string | null }>('POST', 'websites', { url: value, client_id: client || null });
        setMsg({ kind: 'ok', text: `Added ${r.target.host}. It shows as "Needs setup" until the first checks run.${r.note ? ' ' + r.note : ''}` });
      }
      setValue('');
      await reload();
    } catch (err) { setMsg({ kind: 'error', text: err instanceof Error ? err.message : String(err) }); }
  }

  return (
    <>
      <button type="button" className={`ux-btn ux-btn--${variant}`} onClick={() => { setMsg(null); ref.current?.showModal(); }}>
        <Plus aria-hidden="true" />{kind === 'client' ? 'Add client' : 'Add website'}
      </button>
      <dialog ref={ref} className="help" aria-labelledby={`add-${kind}`}>
        <h2 id={`add-${kind}`}>{kind === 'client' ? 'Add a client' : 'Add a website'}</h2>
        <form className="stack" onSubmit={submit}>
          {kind === 'website' && <p>No passwords or hosting details needed. Upfixy checks the public address only until you verify ownership.</p>}
          <label className="field">{kind === 'client' ? 'Company name' : 'Website address'}
            <input autoFocus required value={value} onChange={(e) => setValue(e.target.value)}
              {...(kind === 'website' ? { inputMode: 'url' as const, placeholder: 'shop.example.com' } : { maxLength: 160 })} />
          </label>
          {kind === 'website' && isAgency && clients.length > 0 && (
            <label className="field">Client
              <select value={client} onChange={(e) => setClient(e.target.value)}>
                <option value="">No client (your own site)</option>
                {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </label>
          )}
          {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
          <div className="help__actions">
            <button className="btn">{kind === 'client' ? 'Add client' : 'Add website'}</button>
            <button type="button" className="btn btn--quiet" onClick={() => ref.current?.close()}>Done</button>
          </div>
        </form>
      </dialog>
    </>
  );
}

// ------------------------------------------------------------------ shared pieces
function BoardBar({ board, filters, setFilters, counts, shown, total, extra, placeholder }: {
  board: Board; filters: Filters; setFilters: (f: Filters) => void; counts: Record<HealthLane, number>;
  shown: number; total: number; extra?: React.ReactNode; placeholder: string;
}) {
  const views = useSavedViews(board, filters, setFilters);
  const layout = filters.layout ?? 'board';
  return (
    <div className="ux-boardbar">
      <div className="ux-toolbar">
        <label className="sr-only" htmlFor={`${board}-q`}>Search</label>
        <input id={`${board}-q`} type="search" className="ux-input ux-input--search" placeholder={placeholder}
          value={filters.q ?? ''} onChange={(e) => { setFilters({ ...filters, q: e.target.value }); views.clear(); }} />
        {extra}
        <span className="ux-toolbar__grow ux-desktop-only" />
        <span className="ux-desktop-only" style={{ display: 'contents' }}>{views.control('ux-viewsel', 'ux-btn ux-btn--ghost ux-btn--sm')}</span>
        <div className="ux-seg" role="group" aria-label="Layout">
          <button type="button" aria-pressed={layout === 'board'} onClick={() => setFilters({ ...filters, layout: 'board' })}><LayoutGrid aria-hidden="true" />Board</button>
          <button type="button" aria-pressed={layout === 'list'} onClick={() => setFilters({ ...filters, layout: 'list' })}><List aria-hidden="true" />List</button>
        </div>
      </div>
      <LaneFilter counts={counts} selected={filters.lanes ?? []} onChange={(lanes) => { setFilters({ ...filters, lanes }); views.clear(); }} />
      <p className="ux-note" role="status" style={{ margin: '8px 0 12px', minHeight: 1 }}>
        {isFiltered(filters) && <>Showing {shown} of {total}. <button type="button" className="ux-link" onClick={() => { setFilters({ layout: filters.layout }); views.clear(); }}>Clear filters</button></>}
      </p>
    </div>
  );
}

function CompChip({ label, icon: Icon, c }: { label: string; icon: typeof Globe; c: Component }) {
  const st = COMP_STATUS[c.state];
  return (
    <span className={`ux-chip ${st === 'not_connected' ? 'ux-status--not_connected' : `ux-tint--${st}`}`} title={`${label}: ${c.label}`}>
      <Icon aria-hidden="true" />{label}<span className="sr-only">: {c.label}</span>
    </span>
  );
}

function IssueLinks({ issues, host, max = 2 }: { issues: Array<Issue & { host?: string }>; host: string; max?: number }) {
  const { openHelp } = useWs();
  if (!issues.length) return null;
  return (
    <ul className="ux-issues">
      {issues.slice(0, max).map((i) => (
        <li key={(i.host ?? '') + i.code}>
          <span>{i.title}{i.host && i.host !== host ? <span className="ux-issues__host"> · {i.host}</span> : null}</span>
          <button type="button" className="ux-link" onClick={() => openHelp(i, i.host ?? host)}>Help me understand</button>
        </li>
      ))}
      {issues.length > max && <li className="ux-issues__more">+{issues.length - max} more on the details page</li>}
    </ul>
  );
}

function RequestBadge({ n }: { n: number }) {
  if (!n) return null;
  return <span className="ux-chip ux-tint--investigating"><LifeBuoy aria-hidden="true" />{plural(n, 'open request')}</span>;
}

/** Website list alternative (tables become key/value cards on phones). */
function WebsiteTable({ sites, metrics, clientName }: { sites: Site[]; metrics: Record<string, ApiSiteMetrics> | undefined; clientName?: (id: string | null) => string | undefined }) {
  const sorted = [...sites].sort((a, b) => URGENCY.indexOf(a.lane) - URGENCY.indexOf(b.lane) || a.host.localeCompare(b.host));
  if (!sorted.length) return <EmptyState icon={Globe} title="No websites match">Try clearing the filters.</EmptyState>;
  return (
    <div className="ux-panel" style={{ padding: '10px 14px' }}>
      <div className="ux-table-wrap" tabIndex={0} role="region" aria-label="Websites table">
        <table className="ux-table ux-table--cards">
          <thead>
            <tr><th scope="col">Website</th>{clientName && <th scope="col">Client</th>}<th scope="col">Health</th><th scope="col">Domain</th><th scope="col">SSL</th>
              <th scope="col" className="num">Availability 24 h</th><th scope="col" className="num">Response</th><th scope="col">Last check</th></tr>
          </thead>
          <tbody>
            {sorted.map((s) => {
              const m = metrics?.[s.id];
              return (
                <tr key={s.id}>
                  <td style={{ gridColumn: '1 / -1' }}><a href={s.href} onClick={onLinkClick} style={{ overflowWrap: 'anywhere' }}>{s.host}</a></td>
                  {clientName && <td data-label="Client">{clientName(s.clientId) ?? '—'}</td>}
                  <td><StatusBadge status={LANE_STATUS[s.lane]} /></td>
                  <td data-label="Domain">{s.components.domain.label}</td>
                  <td data-label="SSL">{sslText(s, m)}</td>
                  <td className="num" data-label="Availability">{fmtPct(m?.availability_pct, 1)}</td>
                  <td className="num" data-label="Response">{fmtMs(m?.last_ms ?? m?.avg_ms)}</td>
                  <td data-label="Checked">{ago(s.lastVerifiedAt, s.lastObservedAt).replace(/^Checked /, '')}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function sslText(s: Site, m: ApiSiteMetrics | undefined) {
  if (s.components.ssl.state === 'unknown') return s.components.ssl.label;
  const d = m?.tls_days_left;
  if (d === null || d === undefined) return s.components.ssl.label;
  return d < 0 ? 'Expired' : `${s.components.ssl.label} · ${d} days left`;
}

/** Fresh warning/critical checks across the caller's sites, in the overview shape. */
function issuesFromSites(sites: ReturnType<typeof useWs>['sites']): ApiOverview['issues'] {
  return sites.flatMap((s) => s.checks
    .filter((c) => c.freshness === 'fresh' && (c.severity === 'critical' || c.severity === 'warning'))
    .map((c) => ({ ...c, website_id: s.id, website: s.display_name, client_id: s.client_id })))
    .sort((a, b) => (a.severity === b.severity ? 0 : a.severity === 'critical' ? -1 : 1));
}

function useOpenIncidents(wsId: string | undefined) {
  const [list, setList] = useState<ApiIncident[]>([]);
  const load = () => api<{ data: ApiIncident[] }>('GET', 'incidents?status=open').then((r) => setList(r.data)).catch(() => undefined);
  useEffect(() => { void load(); }, [wsId]); // eslint-disable-line react-hooks/exhaustive-deps
  return [list, load] as const;
}

// ================================================================== UX-03 website board
function WebsiteCard({ site, m, client }: { site: Site; m: ApiSiteMetrics | undefined; client?: string }) {
  const { role, canVerify, openVerify, report, setError } = useWs();
  const [busy, setBusy] = useState(false);
  const canAnalyze = ['owner', 'admin', 'technician', 'client_owner'].includes(role) && !!site.lastVerifiedAt && site.issues.length > 0;
  async function analyze() {
    setBusy(true);
    try {
      const r = await api<{ id: string }>('POST', `websites/${site.id}/diagnose`);
      navigate({ name: 'diagnostic', id: r.id });
    } catch (e) { setError(e instanceof Error ? e.message : String(e)); setBusy(false); }
  }
  const stale = !site.lastVerifiedAt;
  return (
    <article className={`ux-card ux-card--${site.lane}`} aria-label={`${site.host}, ${LANE_LABEL[site.lane]}`}>
      <div className="ux-card__head">
        <Monogram name={site.host} />
        <div className="ux-card__title">
          <a href={site.href} onClick={onLinkClick}>{site.host}</a>
          <span>{client ?? (site.hosts && site.hosts.length > 1 ? `${site.hosts.length} addresses` : 'Website')}</span>
        </div>
      </div>
      <div style={{ marginTop: 10 }}><StatusBadge status={LANE_STATUS[site.lane]} /></div>
      <ul className="ux-card__rows">
        <li><Activity aria-hidden="true" /><span className="k">Availability 24 h</span>
          <span className="v">{fmtPct(m?.availability_pct, 1)}</span></li>
        <li><Lock aria-hidden="true" /><span className="k">SSL</span>
          <span className={`ux-v ux-tint--${COMP_STATUS[site.components.ssl.state]}`}>{m?.tls_days_left !== null && m?.tls_days_left !== undefined && site.components.ssl.state !== 'unknown'
            ? (m.tls_days_left < 0 ? 'Expired' : `${m.tls_days_left} days`) : site.components.ssl.label}</span></li>
        <li><Network aria-hidden="true" /><span className="k">DNS</span>
          <span className={`ux-v ux-tint--${COMP_STATUS[site.components.domain.state]}`}>{site.components.domain.label}</span></li>
        <li><Globe aria-hidden="true" /><span className="k">Response</span>
          <span className="v">{fmtMs(m?.last_ms)}</span></li>
        <li><HardDrive aria-hidden="true" /><span className="k">Backups</span>
          <span className="ux-v ux-status--not_connected">Not connected</span></li>
      </ul>
      {m && m.ms_series.some((v) => v !== null) && (
        <div className="ux-card__spark" aria-hidden="true"><Sparkline values={m.ms_series} color="#7c3aed" /><span>response, 24 h</span></div>
      )}
      <IssueLinks issues={site.issues} host={site.host} />
      <div className="ux-chips" style={{ marginTop: site.openRequests ? 10 : 0 }}><RequestBadge n={site.openRequests ?? 0} /></div>
      {(canAnalyze || (canVerify && !site.verifiedHosts?.length) || role !== 'billing') && (
        <div className="ux-card__actions">
          {canAnalyze && (
            <button type="button" className="ux-btn ux-btn--primary ux-btn--sm" disabled={busy} onClick={() => void analyze()}>
              <Stethoscope aria-hidden="true" />Analyze<span className="sr-only"> {site.host}</span>
            </button>
          )}
          {canVerify && !site.verifiedHosts?.length && <button type="button" className="ux-link" onClick={() => openVerify({ id: site.id, name: site.host })}>Verify ownership</button>}
          {role !== 'billing' && <button type="button" className="ux-link" onClick={() => report(site.id, site.host)}>Report a problem</button>}
        </div>
      )}
      <div className="ux-card__foot">
        <span className={stale ? 'ux-stale' : undefined}>{ago(site.lastVerifiedAt, site.lastObservedAt)}</span>
      </div>
    </article>
  );
}

function NotificationList({ items }: { items: ApiNotification[] | null }) {
  if (!items) return <div className="ux-skel" style={{ height: 120 }} aria-hidden="true" />;
  if (!items.length) return <EmptyState icon={Bell} title="No alerts">Alerts appear when an incident opens or resolves.</EmptyState>;
  return (
    <ul className="ux-list">
      {items.slice(0, 5).map((n) => {
        const open = n.kind === 'incident_opened';
        return (
          <li key={n.id}>
            <span className={`ux-list__icon ux-tint--${open ? 'critical' : 'healthy'}`} aria-hidden="true">{open ? <CircleAlert /> : <CircleCheck />}</span>
            <div className="ux-list__main">
              <a className="ux-list__title" href={href({ name: 'website', id: n.website_id })} onClick={onLinkClick}>{n.website}</a>
              <span className="ux-list__meta">{open ? n.title : `Resolved: ${n.title}`}</span>
            </div>
            <span className="ux-list__side">
              <time dateTime={n.created_at}>{since(new Date(n.created_at))}</time>
              {!n.read_at && <span className="ux-chip ux-tint--info">New</span>}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

function useNotifications(wsId: string | undefined) {
  const [items, setItems] = useState<ApiNotification[] | null>(null);
  useEffect(() => { api<{ data: ApiNotification[] }>('GET', 'notifications').then((r) => setItems(r.data)).catch(() => setItems([])); }, [wsId]);
  return items;
}

function WebsiteKpis({ counts, total, avail }: { counts: Record<HealthLane, number>; total: number; avail: { pct: number | null; spark: Array<number | null> } }) {
  return (
    <div className="ux-kpis ux-kpis--strip" style={{ ['--kpi-n' as string]: 6 }} tabIndex={0} role="region" aria-label="Key figures">
      <MetricCard tone="blue" icon={Globe} label="Websites" value={total} sub="Monitored by public checks" />
      <MetricCard tone="mint" icon={CircleCheck} label="Healthy" value={counts.healthy} sub={total ? `${Math.round((counts.healthy / total) * 100)}% of websites` : '—'} />
      <MetricCard tone="orange" icon={TriangleAlert} label="Warnings" value={counts.warning} sub={counts.warning ? 'Check before they fail' : 'No warnings'} />
      <MetricCard tone="coral" icon={CircleAlert} label="Critical" value={counts.critical} sub={counts.critical ? 'Failing now' : 'Nothing failing'} />
      <MetricCard tone="violet" icon={CircleHelp} label="No fresh readings" value={counts.unknown + counts.needs_setup}
        sub={counts.needs_setup ? `${counts.needs_setup} waiting for first checks` : 'Shown as Unknown, never Healthy'} />
      <MetricCard tone="aqua" icon={Activity} label="Availability 24 h" value={fmtPct(avail.pct, 2)} spark={avail.spark}
        sub={avail.pct === null ? 'No readings yet' : 'Successful HTTP checks'} />
    </div>
  );
}

export function WebsitesBoard() {
  const { sites, clients, isAgency, role, loaded, workspace } = useWs();
  const [filters, setFilters] = useState<Filters>(initialFilters);
  const metrics = useMetrics(workspace?.id);
  const notes = useNotifications(workspace?.id);
  const models = useMemo(() => sites.map(toSite), [sites]);
  const clientName = (id: string | null) => clients.find((c) => c.id === id)?.name ?? '';
  const shown = filterSites(models, filters, clientName);
  const counts = laneCounts(filterSites(models, { ...filters, lanes: [] }, clientName));
  const allCounts = laneCounts(models);
  const canAdd = ['owner', 'admin'].includes(role);
  const avail = useMemo(() => {
    const s = metrics?.series ?? [];
    const n = s.reduce((a, p) => a + p.checks, 0);
    const up = s.reduce((a, p) => a + ((p.availability_pct ?? 0) / 100) * p.checks, 0);
    return { pct: n ? (100 * up) / n : null, spark: s.map((p) => p.availability_pct) };
  }, [metrics]);
  const tls = useMemo(() => Object.entries(metrics?.websites ?? {})
    .filter(([, m]) => m.tls_days_left !== null)
    .map(([id, m]) => ({ id, days: m.tls_days_left!, host: sites.find((s) => s.id === id)?.display_name ?? '' }))
    .sort((a, b) => a.days - b.days), [metrics, sites]);

  const rail = (
    <aside className="ux-rail" aria-label="Alerts and renewals">
      <Accordion title="Recent alerts" count={notes?.filter((n) => !n.read_at).length}>
        <NotificationList items={notes} />
        <a className="ux-link" href="/alerts" onClick={onLinkClick} style={{ marginTop: 10 }}>All alerts<ArrowRight aria-hidden="true" /></a>
      </Accordion>
      <Accordion title="Certificate renewals">
        <CertList items={tls} />
      </Accordion>
      <Accordion title="Recent activity" defaultOpenDesktop={false}>
        <ActivityTimeline items={metrics?.activity} limit={6} />
      </Accordion>
    </aside>
  );

  const head = (title: string, sub: string) => (
    <header className="ux-head">
      <div><h1>{title}</h1><p>{sub}</p></div>
      <div className="ux-head__actions">{canAdd && <AddButton kind="website" />}</div>
    </header>
  );

  if (loaded && sites.length === 1 && !isFiltered(filters)) {
    const s = models[0];
    const m = metrics?.websites[s.id];
    const Icon = LANE_ICON[s.lane];
    const tone = ({ healthy: 'mint', warning: 'orange', critical: 'coral', unknown: 'violet', needs_setup: 'violet' } as const)[s.lane];
    const days = s.components.ssl.state === 'unknown' ? null : m?.tls_days_left ?? null;
    return (
      <div className="ux-page">
        {head('Your website', 'Live health from public checks. Connect your hosting or DNS later for deeper diagnostics.')}
        <div className="ux-kpis ux-kpis--strip" style={{ ['--kpi-n' as string]: 5 }} tabIndex={0} role="region" aria-label="Key figures">
          <MetricCard tone={tone} icon={Icon} label="Health" value={LANE_LABEL[s.lane]} sub={ago(s.lastVerifiedAt, s.lastObservedAt)} />
          <MetricCard tone="aqua" icon={Activity} label="Availability 24 h" value={fmtPct(m?.availability_pct, 2)} sub={m ? `${m.http_checks} HTTP checks` : 'No readings yet'} />
          <MetricCard tone="violet" icon={Globe} label="Response time" value={fmtMs(m?.last_ms)} spark={m?.ms_series} sub={m?.avg_ms ? `Average ${fmtMs(m.avg_ms)}` : 'No readings yet'} />
          <MetricCard tone="orange" icon={Lock} label="SSL certificate" value={days === null ? '—' : days < 0 ? 'Expired' : `${days} days`} sub={days === null ? s.components.ssl.label : 'Until it expires'} />
          <MetricCard tone="blue" icon={LifeBuoy} label="Open requests" value={s.openRequests ?? 0} href={role !== 'billing' ? '/work' : undefined} sub={s.issues.length ? `${plural(s.issues.length, 'current problem')}` : 'No current problems'} />
        </div>
        <div className="ux-grid ux-grid--rail">
          <div className="ux-spotlight" aria-label={`${s.host} overview`} style={{ display: 'grid', gap: 16, alignContent: 'start' }}>
            <WebsiteCard site={s} m={m} />
            {metrics && m && (
              <Panel title="Response time" sub={`Last ${metrics.window_hours} hours · hourly average of HTTP checks · gaps mean no readings`}
                action={<a className="ux-link" href={s.href} onClick={onLinkClick}>Open details<ArrowRight aria-hidden="true" /></a>}>
                <TimeSeriesChart label={`Average response time per hour for ${s.host}, milliseconds`} unit="Avg response (ms)" color="#7c3aed"
                  points={metrics.series.map((p, i) => ({ t: p.hour, v: m.ms_series[i] ?? null }))} format={(v) => `${Math.round(v)} ms`} />
              </Panel>
            )}
          </div>
          {rail}
        </div>
      </div>
    );
  }
  const attention = allCounts.critical + allCounts.warning;
  return (
    <div className="ux-page">
      {head(isAgency ? 'Websites' : 'My websites',
        models.length === 0 ? 'Add a website to start monitoring.'
          : attention ? `${attention} of ${models.length} need attention. Lanes come from fresh readings and can't be dragged.`
            : `Nothing failing across ${models.length} websites.`)}
      {sites.length === 0 && loaded ? (
        <EmptyState icon={Globe} title="No websites yet">{canAdd ? 'Use “Add website” to start: no passwords needed for basic monitoring.' : 'Websites you have access to will appear here.'}</EmptyState>
      ) : (
        <>
          <WebsiteKpis counts={allCounts} total={models.length} avail={avail} />
          <div className="ux-grid ux-grid--rail">
            <div style={{ minWidth: 0 }}>
              <BoardBar board="websites" filters={filters} setFilters={setFilters} counts={counts} shown={shown.length} total={models.length}
                placeholder={isAgency ? 'Search websites or clients' : 'Search websites'}
                extra={isAgency && clients.length > 0 && (
                  <label className="ux-desktop-only"><span className="sr-only">Client</span>
                    <select className="ux-select" value={filters.client_id ?? ''} onChange={(e) => setFilters({ ...filters, client_id: e.target.value || undefined })}>
                      <option value="">All clients</option>
                      {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                  </label>
                )} />
              {filters.layout === 'list'
                ? <WebsiteTable sites={shown} metrics={metrics?.websites} clientName={isAgency ? clientName : undefined} />
                : <HealthColumns items={shown} noun="Websites" lanes={filters.lanes} counts={counts}
                    render={(s) => <WebsiteCard site={s} m={metrics?.websites[s.id]} client={isAgency ? clientName(s.clientId) || 'Own website' : undefined} />} />}
            </div>
            {rail}
          </div>
        </>
      )}
    </div>
  );
}

// ================================================================== UX-02 client board
function ClientCard({ client }: { client: Client }) {
  const issues = client.sites.flatMap((s) => s.issues.map((i) => ({ ...i, host: i.host ?? s.host })));
  const impacted = client.sites.filter((s) => s.lane !== 'healthy').length;
  const times = (xs: Array<Date | null>) => xs.filter(Boolean).map((d) => d!.getTime());
  const verified = times(client.sites.map((s) => s.lastVerifiedAt));
  const observed = times(client.sites.map((s) => s.lastObservedAt));
  // Any site without a fresh reading means the client as a whole isn't freshly verified.
  const allFresh = verified.length === client.sites.length && verified.length > 0;
  const oldest = allFresh ? new Date(Math.min(...verified)) : null;
  const newestObserved = observed.length ? new Date(Math.max(...observed)) : null;
  const staff = client.staff ?? [];
  const w = client.sites.length ? worstComponents(client.sites) : null;
  return (
    <article className={`ux-card ux-card--${client.lane}`} aria-label={`${client.name}, ${LANE_LABEL[client.lane]}`}>
      <div className="ux-card__head">
        <Monogram name={client.name} />
        <div className="ux-card__title">
          <a href={client.href} onClick={onLinkClick}>{client.name}</a>
          <span>{plural(client.sites.length, 'website')}{impacted > 0 && ` · ${impacted} need attention`}</span>
        </div>
      </div>
      <div style={{ marginTop: 10 }}><StatusBadge status={LANE_STATUS[client.lane]} /></div>
      {w && (
        <div className="ux-chips" aria-label="Worst status by area">
          <CompChip label="Web" icon={Globe} c={w.errors} />
          <CompChip label="DNS" icon={Network} c={w.domain} />
          <CompChip label="SSL" icon={Lock} c={w.ssl} />
          <CompChip label="Backup" icon={HardDrive} c={w.backup} />
        </div>
      )}
      <IssueLinks issues={issues} host="" />
      {client.openTickets > 0 && <div className="ux-chips"><RequestBadge n={client.openTickets} /></div>}
      <div className="ux-card__foot">
        <span className="ux-mini">
          {staff.length
            ? <><span className="ux-avatar" aria-hidden="true">{staff[0].charAt(0).toUpperCase()}</span><span style={{ overflowWrap: 'anywhere' }}>{staff[0].split('@')[0]}{staff.length > 1 ? ` +${staff.length - 1}` : ''}</span></>
            : <><UserRound aria-hidden="true" width={14} />No technician</>}
        </span>
        <span>{ago(oldest, newestObserved)}</span>
      </div>
    </article>
  );
}

function TechWorkload() {
  const { tickets } = useWs();
  const open = tickets.filter((t) => t.state !== 'resolved' && t.state !== 'closed');
  const by = new Map<string, number>();
  for (const t of open) by.set(t.assignee_email ?? '', (by.get(t.assignee_email ?? '') ?? 0) + 1);
  const rows = [...by.entries()].filter(([k]) => k).sort((a, b) => b[1] - a[1]);
  const max = Math.max(1, ...rows.map(([, n]) => n));
  const unassigned = by.get('') ?? 0;
  if (!open.length) return <EmptyState icon={Users} title="No open requests">Workload appears when requests are assigned.</EmptyState>;
  return (
    <ul className="ux-list">
      {rows.map(([email, n]) => (
        <li key={email} style={{ alignItems: 'center' }}>
          <span className="ux-avatar" aria-hidden="true" style={{ width: 32, height: 32, fontSize: 13 }}>{email.charAt(0).toUpperCase()}</span>
          <div className="ux-list__main">
            <span className="ux-list__title" style={{ fontSize: 'var(--fs-sm)' }}>{email.split('@')[0]}</span>
            <div className="ux-bar" aria-hidden="true"><span style={{ width: `${(n / max) * 100}%` }} /></div>
          </div>
          <span className="ux-list__side" style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink)' }}><b>{n}</b> open</span>
        </li>
      ))}
      {unassigned > 0 && (
        <li style={{ alignItems: 'center' }}>
          <span className="ux-list__icon ux-tint--unknown" aria-hidden="true" style={{ width: 32, height: 32 }}><UserRound /></span>
          <div className="ux-list__main"><span className="ux-list__title" style={{ fontSize: 'var(--fs-sm)' }}>Unassigned</span></div>
          <span className="ux-list__side" style={{ fontSize: 'var(--fs-sm)', color: 'var(--ink)' }}><b>{unassigned}</b> open</span>
        </li>
      )}
    </ul>
  );
}

/** Verified payments this calendar month (owner/admin/billing only). Test-mode payments are labelled. */
function usePaidThisMonth(enabled: boolean) {
  const [v, setV] = useState<{ paise: number; n: number; test: boolean } | null>(null);
  useEffect(() => {
    if (!enabled) return;
    api<{ orders: Array<{ amount_paise: number; status: PaymentStatus; mode: string; created_at: string; refunded_paise: number }> }>('GET', 'billing/payments')
      .then((r) => {
        const now = new Date();
        const rows = r.orders.filter((o) => ['paid_verified', 'partially_refunded'].includes(o.status)
          && new Date(o.created_at).getMonth() === now.getMonth() && new Date(o.created_at).getFullYear() === now.getFullYear());
        setV({ paise: rows.reduce((a, o) => a + o.amount_paise - (o.refunded_paise ?? 0), 0), n: rows.length, test: rows.some((o) => o.mode !== 'live') || rows.length === 0 });
      }).catch(() => setV(null));
  }, [enabled]);
  return v;
}

export function ClientsBoard() {
  const { clients, sites, tickets, isStaff, role, loaded, workspace } = useWs();
  const [filters, setFilters] = useState<Filters>(initialFilters);
  const [incidents, reloadIncidents] = useOpenIncidents(workspace?.id);
  const models = useMemo(() => clients.map(toClient), [clients]);
  const shown = filterClients(models, filters);
  const counts = laneCounts(filterClients(models, { ...filters, lanes: [] }));
  const all = laneCounts(models);
  const billingVisible = ['owner', 'admin', 'billing'].includes(role);
  const paid = usePaidThisMonth(billingVisible);
  const openReq = tickets.filter((t) => !['resolved', 'closed'].includes(t.state)).length;
  const websites = models.reduce((a, c) => a + c.sites.length, 0);
  const attention = all.critical + all.warning;
  const canAddClient = isStaff && role !== 'technician';
  const unassignedClients = models.filter((c) => !(c.staff ?? []).length).length;

  return (
    <div className="ux-page">
      <header className="ux-head">
        <div>
          <h1>Client health board</h1>
          <p>{models.length === 0 ? 'A client groups the websites you look after for one customer.'
            : attention ? `${attention} of ${models.length} clients need attention. A client takes the health of its worst website.`
              : `All ${models.length} clients look fine.`}</p>
        </div>
        <div className="ux-head__actions">
          {['owner', 'admin'].includes(role) && <AddButton kind="website" variant="ghost" />}
          {canAddClient && <AddButton kind="client" />}
        </div>
      </header>
      {models.length === 0 && loaded ? (
        <EmptyState icon={Building2} title="No clients yet">A client groups the websites you look after for one customer.</EmptyState>
      ) : (
        <>
          <div className="ux-kpis ux-kpis--strip" style={{ ['--kpi-n' as string]: 5 }} tabIndex={0} role="region" aria-label="Key figures">
            <MetricCard tone="blue" icon={Building2} label="Clients" value={models.length}
              sub={unassignedClients ? `${unassignedClients} without a technician` : 'All have a technician'} />
            <MetricCard tone="aqua" icon={Globe} label="Websites" value={websites} href="/websites"
              sub={`${sites.filter((s) => s.lane === 'unknown' || s.lane === 'needs_setup').length} without fresh readings`} />
            <MetricCard tone="coral" icon={CircleAlert} label="Clients needing attention" value={attention}
              sub={`${all.critical} critical · ${all.warning} warning`} />
            <MetricCard tone="violet" icon={LifeBuoy} label="Open requests" value={openReq} href={role !== 'billing' ? '/work' : undefined}
              sub={`${tickets.filter((t) => t.state === 'quote_pending').length} quote pending`} />
            {billingVisible
              ? <MetricCard tone="mint" icon={Wallet} label="Paid this month" value={paid ? rupees(paid.paise) : '—'} href="/billing"
                  sub={paid ? `${plural(paid.n, 'verified payment')}${paid.test ? ' · test mode' : ''}` : 'Loading'} />
              : <MetricCard tone="mint" icon={ShieldCheck} label="Healthy clients" value={all.healthy}
                  sub={models.length ? `${Math.round((all.healthy / models.length) * 100)}% of clients` : '—'} />}
          </div>
          <div className="ux-grid ux-grid--rail">
            <div style={{ minWidth: 0 }}>
              <BoardBar board="clients" filters={filters} setFilters={setFilters} counts={counts} shown={shown.length} total={models.length}
                placeholder="Search clients or websites" />
              {filters.layout === 'list'
                ? <WebsiteTable sites={shown.flatMap((c) => c.sites)} metrics={undefined} clientName={(id) => models.find((c) => c.id === id)?.name} />
                : <HealthColumns items={shown} noun="Clients" lanes={filters.lanes} counts={counts} render={(c) => <ClientCard client={c} />} />}
            </div>
            <aside className="ux-rail" aria-label="Urgent issues and workload">
              <Accordion title="Urgent issues" count={incidents.length + issuesFromSites(sites).filter((i) => i.severity === 'critical').length}>
                <AttentionQueue incidents={incidents} issues={issuesFromSites(sites)} onChanged={() => void reloadIncidents()} limit={4} />
              </Accordion>
              <Accordion title="Technician workload" count={openReq}>
                <TechWorkload />
              </Accordion>
              <Accordion title="Quick actions">
                <div className="ux-quick">
                  {role !== 'billing' && <a href="/work" onClick={onLinkClick}><LifeBuoy aria-hidden="true" />Work queue</a>}
                  <a href="/websites?lane=critical" onClick={onLinkClick}><Microscope aria-hidden="true" />Critical sites</a>
                  <a href="/alerts" onClick={onLinkClick}><Bell aria-hidden="true" />Alerts</a>
                  {['owner', 'admin'].includes(role) && <a href="/integrations" onClick={onLinkClick}><Plug aria-hidden="true" />Integrations</a>}
                </div>
              </Accordion>
            </aside>
          </div>
        </>
      )}
    </div>
  );
}

