// Client and website boards (BRD-001/002/004): computed health lanes, search,
// lane/client filters, saved views, list alternative.
import { useEffect, useMemo, useRef, useState } from 'react';
import type { HealthLane } from '@upfixy/core';
import { api, ApiError, type ApiSavedView } from '../api';
import { useWs } from '../app-context';
import { ClientCard, HealthBoard, LaneChips, SiteCard, SiteList, StatusList, Title, ago, LANES } from '../components';
import { filterClients, filterSites, isFiltered, laneCounts, type Filters } from '../filters';
import { toClient, toSite } from '../live-mapping';
import { navigate } from '../router';

type Board = 'clients' | 'websites';

function initialFilters(): Filters {
  const lane = new URLSearchParams(window.location.search).get('lane') as HealthLane | null;
  return lane && (LANES as string[]).includes(lane) ? { lanes: [lane] } : {};
}

/** Search, lane chips, client filter, layout and saved views. */
function Toolbar({ board, filters, setFilters, counts, shown, total, extra }: {
  board: Board | 'work'; filters: Filters; setFilters: (f: Filters) => void;
  counts?: Record<HealthLane, number>; shown: number; total: number; extra?: React.ReactNode;
}) {
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

  return (
    <div className="filters">
      <div className="filters__row">
        <label className="search">
          <span className="sr-only">Search</span>
          <input type="search" placeholder={board === 'clients' ? 'Search clients or websites' : board === 'work' ? 'Search requests' : 'Search websites'}
            value={filters.q ?? ''} onChange={(e) => { setFilters({ ...filters, q: e.target.value }); setActive(''); }} />
        </label>
        {extra}
        <label className="views">
          <span className="sr-only">Saved views</span>
          <select value={active} onChange={(e) => {
            const v = views.find((x) => x.id === e.target.value);
            setActive(e.target.value);
            setFilters(v ? { ...v.filters } : {});
          }}>
            <option value="">{views.length ? 'Saved views' : 'No saved views'}</option>
            {views.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </select>
        </label>
        {active
          ? <button type="button" className="btn btn--quiet btn--sm" onClick={remove}>Delete view</button>
          : isFiltered(filters) && <button type="button" className="btn btn--quiet btn--sm" onClick={save}>Save view</button>}
        {board !== 'work' && (
          <div className="seg" role="group" aria-label="Layout">
            <button type="button" aria-pressed={(filters.layout ?? 'board') === 'board'} onClick={() => setFilters({ ...filters, layout: 'board' })}>Board</button>
            <button type="button" aria-pressed={filters.layout === 'list'} onClick={() => setFilters({ ...filters, layout: 'list' })}>List</button>
          </div>
        )}
      </div>
      {counts && <LaneChips counts={counts} selected={filters.lanes ?? []} onChange={(lanes) => { setFilters({ ...filters, lanes }); setActive(''); }} />}
      <p className="hint" role="status">
        {isFiltered(filters) ? `Showing ${shown} of ${total}. ` : ''}
        {isFiltered(filters) && <button type="button" className="link" onClick={() => { setFilters({ layout: filters.layout }); setActive(''); }}>Clear filters</button>}
      </p>
    </div>
  );
}
export { Toolbar };

function AddButton({ kind }: { kind: 'website' | 'client' }) {
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
      <button type="button" className="btn" onClick={() => { setMsg(null); ref.current?.showModal(); }}>
        {kind === 'client' ? 'Add client' : 'Add website'}
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

/** AC-BRD-002.1: a one-site workspace gets an overview, not five mostly empty lanes. */
function SingleSite() {
  const { sites, openHelp, openVerify, report, canVerify } = useWs();
  const s = toSite(sites[0]);
  return (
    <section className={`spotlight card card--${s.lane}`} aria-label={`${s.host} overview`}>
      <header className="card__head">
        <div>
          <h2 className="host"><Title href={s.href}>{s.host}</Title></h2>
          <p className="card__meta">{ago(s.lastVerifiedAt, s.lastObservedAt)}</p>
        </div>
      </header>
      <StatusList components={s.components} />
      {s.issues.length > 0 && (
        <ul className="issues">
          {s.issues.map((i) => (
            <li key={i.code}><span className="issues__text">{i.title}</span>
              <button type="button" className="link" onClick={() => openHelp(i, i.host ?? s.host)}>Help me understand</button></li>
          ))}
        </ul>
      )}
      <div className="card__actions">
        <a className="btn btn--sm" href={s.href} onClick={(e) => { e.preventDefault(); navigate(s.href!); }}>Open details</a>
        {canVerify && !(s.verifiedHosts?.length) && <button type="button" className="btn btn--quiet btn--sm" onClick={() => openVerify({ id: s.id, name: s.host })}>Verify ownership</button>}
        <button type="button" className="btn btn--quiet btn--sm" onClick={() => report(s.id, s.host)}>Report a problem</button>
      </div>
    </section>
  );
}

export function WebsitesBoard() {
  const { sites, clients, isAgency, role, openHelp, openVerify, report, canVerify, loaded } = useWs();
  const [filters, setFilters] = useState<Filters>(initialFilters);
  const models = useMemo(() => sites.map(toSite), [sites]);
  const clientName = (id: string | null) => clients.find((c) => c.id === id)?.name ?? '';
  const shown = filterSites(models, filters, clientName);
  const canAdd = ['owner', 'admin'].includes(role);

  if (loaded && sites.length === 1 && !isFiltered(filters)) {
    return (
      <div className="page">
        <div className="headline headline--row"><h1>Your website</h1>{canAdd && <AddButton kind="website" />}</div>
        <SingleSite />
      </div>
    );
  }
  return (
    <div className="page">
      <div className="headline headline--row">
        <h1>Websites</h1>
        {canAdd && <AddButton kind="website" />}
      </div>
      {sites.length === 0 && loaded ? (
        <p className="empty">No websites yet. {canAdd ? 'Use “Add website” to start: no passwords needed for basic monitoring.' : 'Websites you have access to will appear here.'}</p>
      ) : (
        <>
          <Toolbar board="websites" filters={filters} setFilters={setFilters} counts={laneCounts(filterSites(models, { ...filters, lanes: [] }, clientName))}
            shown={shown.length} total={models.length}
            extra={isAgency && clients.length > 0 && (
              <label className="views"><span className="sr-only">Client</span>
                <select value={filters.client_id ?? ''} onChange={(e) => setFilters({ ...filters, client_id: e.target.value || undefined })}>
                  <option value="">All clients</option>
                  {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </label>
            )} />
          {filters.layout === 'list'
            ? <SiteList sites={shown} clients={isAgency ? clients.map(toClient) : undefined} />
            : <HealthBoard items={shown} noun="Websites" lanes={filters.lanes} render={(s) => (
                <SiteCard site={s} onHelp={openHelp} actions={
                  <>
                    {canVerify && !(s.verifiedHosts?.length) && <button type="button" className="link" onClick={() => openVerify({ id: s.id, name: s.host })}>Verify ownership</button>}
                    {role !== 'billing' && <button type="button" className="link" onClick={() => report(s.id, s.host)}>Report a problem</button>}
                  </>
                } />
              )} />}
        </>
      )}
    </div>
  );
}

export function ClientsBoard() {
  const { clients, isStaff, role, openHelp, loaded } = useWs();
  const [filters, setFilters] = useState<Filters>(initialFilters);
  const models = useMemo(() => clients.map(toClient), [clients]);
  const shown = filterClients(models, filters);
  const attention = models.filter((c) => c.lane === 'critical' || c.lane === 'warning').length;
  return (
    <div className="page">
      <div className="headline headline--row">
        <h1>{models.length === 0 ? 'Clients' : attention ? `${attention} of ${models.length} clients need attention` : `All ${models.length} clients look fine`}</h1>
        {isStaff && role !== 'technician' && <AddButton kind="client" />}
      </div>
      {models.length === 0 && loaded ? (
        <p className="empty">No clients yet. A client groups the websites you look after for one customer.</p>
      ) : (
        <>
          <Toolbar board="clients" filters={filters} setFilters={setFilters} counts={laneCounts(filterClients(models, { ...filters, lanes: [] }))}
            shown={shown.length} total={models.length} />
          {filters.layout === 'list'
            ? <SiteList sites={shown.flatMap((c) => c.sites)} clients={models} />
            : <HealthBoard items={shown} noun="Clients" lanes={filters.lanes} render={(c) => <ClientCard client={c} onHelp={openHelp} />} />}
        </>
      )}
    </div>
  );
}
