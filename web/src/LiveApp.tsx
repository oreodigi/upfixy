import { useCallback, useEffect, useState } from 'react';
import type { TicketState } from '@upfixy/core';
import { api, ApiError, session, type ApiClient, type ApiSite, type ApiTicket, type ApiWorkspace } from './api';
import { toClient, toSite } from './live-mapping';
import type { Issue, Ticket } from './demo-data';
import { ClientCard, SiteCard, HealthBoard, SiteList, WorkBoard, HelpDialog } from './components';

type View = 'clients' | 'websites' | 'work';

export default function LiveApp() {
  const [token, setToken] = useState(session.token());
  if (!token) return <AuthScreen onSignedIn={(t, ws) => { session.setToken(t); session.setWorkspace(ws); setToken(t); }} />;
  return <Workspace onSignOut={() => { session.setToken(null); session.setWorkspace(null); setToken(null); }} />;
}

function AuthScreen({ onSignedIn }: { onSignedIn: (token: string, ws: string | null) => void }) {
  const [mode, setMode] = useState<'login' | 'register'>('register');
  const [form, setForm] = useState({ email: '', password: '', account_type: 'solo', workspace_name: '' });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value });

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      if (mode === 'register') {
        const r = await api<{ token: string; workspace_id: string }>('POST', 'auth/register', form, { workspace: false });
        onSignedIn(r.token, r.workspace_id);
      } else {
        const r = await api<{ token: string }>('POST', 'auth/login', { email: form.email, password: form.password }, { workspace: false });
        onSignedIn(r.token, null);
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Upfixy is not reachable. Is the API running?');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="auth">
      <form className="auth__card" onSubmit={submit}>
        <span className="logo" aria-hidden="true">U</span>
        <h1>{mode === 'register' ? 'Create your Upfixy account' : 'Sign in to Upfixy'}</h1>
        {mode === 'register' && (
          <fieldset className="choice">
            <legend>Who are the websites for?</legend>
            <label><input type="radio" name="t" checked={form.account_type === 'solo'} onChange={() => setForm({ ...form, account_type: 'solo' })} /> For myself</label>
            <label><input type="radio" name="t" checked={form.account_type === 'agency'} onChange={() => setForm({ ...form, account_type: 'agency' })} /> For my clients or team</label>
          </fieldset>
        )}
        <label className="field">Email<input type="email" autoComplete="email" required value={form.email} onChange={set('email')} /></label>
        <label className="field">Password
          <input type="password" autoComplete={mode === 'register' ? 'new-password' : 'current-password'} required minLength={mode === 'register' ? 12 : 1} value={form.password} onChange={set('password')} />
        </label>
        {mode === 'register' && (
          <label className="field">{form.account_type === 'agency' ? 'Agency name' : 'Workspace name'}
            <input required maxLength={120} value={form.workspace_name} onChange={set('workspace_name')} />
          </label>
        )}
        {error && <p className="error" role="alert">{error}</p>}
        <button className="btn" disabled={busy}>{mode === 'register' ? 'Create account' : 'Sign in'}</button>
        <button type="button" className="link" onClick={() => { setMode(mode === 'register' ? 'login' : 'register'); setError(null); }}>
          {mode === 'register' ? 'I already have an account' : 'Create a new account'}
        </button>
      </form>
    </div>
  );
}

function Workspace({ onSignOut }: { onSignOut: () => void }) {
  const [workspaces, setWorkspaces] = useState<ApiWorkspace[]>([]);
  const [ws, setWs] = useState<string | null>(session.workspace());
  const [view, setView] = useState<View>('websites');
  const [mode, setMode] = useState<'board' | 'list'>('board');
  const [clients, setClients] = useState<ApiClient[]>([]);
  const [sites, setSites] = useState<ApiSite[]>([]);
  const [tickets, setTickets] = useState<ApiTicket[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [help, setHelp] = useState<{ issue: Issue; host: string } | null>(null);

  const current = workspaces.find((w) => w.id === ws);
  const isAgency = current?.kind === 'agency';

  useEffect(() => {
    api<{ data: ApiWorkspace[] }>('GET', 'workspaces', undefined, { workspace: false })
      .then((r) => {
        setWorkspaces(r.data);
        const pick = r.data.find((w) => w.id === ws)?.id ?? r.data[0]?.id ?? null;
        session.setWorkspace(pick); setWs(pick);
        if (r.data.find((w) => w.id === pick)?.kind === 'agency') setView('clients');
      })
      .catch((e) => (e instanceof ApiError && e.status === 401 ? onSignOut() : setError(e.message)));
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const load = useCallback(async () => {
    if (!ws) return;
    setError(null);
    try {
      const [s, t] = await Promise.all([
        api<{ data: ApiSite[] }>('GET', 'boards/websites'),
        api<{ data: ApiTicket[] }>('GET', 'tickets'),
      ]);
      setSites(s.data); setTickets(t.data);
      if (isAgency) setClients((await api<{ data: ApiClient[] }>('GET', 'boards/clients')).data);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    }
  }, [ws, isAgency]);

  useEffect(() => { void load(); }, [load]);

  const onMove = async (id: string, to: TicketState) => {
    const t = tickets.find((x) => x.id === id)!;
    try {
      await api('POST', `tickets/${id}/transition`, { to, version: t.version });
      await load();
      return null;
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) await load();
      return e instanceof Error ? e.message : String(e);
    }
  };

  const workTickets: Ticket[] = tickets.map((t) => ({
    id: t.id, title: t.title, host: t.website, client: '', state: t.state, assignee: null, hasApproval: false,
  }));
  const views: Array<{ id: View; label: string }> = [
    ...(isAgency ? [{ id: 'clients' as View, label: 'Clients' }] : []),
    { id: 'websites', label: 'Websites' },
    { id: 'work', label: isAgency ? 'Work queue' : 'Issues' },
  ];
  const siteModels = sites.map(toSite);
  const clientModels = clients.map(toClient);

  return (
    <div className="app">
      <header className="top">
        <div className="top__brand">
          <span className="logo" aria-hidden="true">U</span>
          <div>
            <p className="top__name">Upfixy</p>
            {workspaces.length > 1 ? (
              <label className="ws-switch">
                <span className="sr-only">Workspace</span>
                <select value={ws ?? ''} onChange={(e) => { session.setWorkspace(e.target.value); setWs(e.target.value); setClients([]); }}>
                  {workspaces.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                </select>
              </label>
            ) : <p className="top__ws">{current?.name ?? 'Loading…'}</p>}
          </div>
        </div>
        <nav aria-label="Main" className="tabs">
          {views.map((v) => (
            <button key={v.id} type="button" className="tab" aria-current={view === v.id ? 'page' : undefined} onClick={() => setView(v.id)}>{v.label}</button>
          ))}
          <button type="button" className="tab" onClick={onSignOut}>Sign out</button>
        </nav>
      </header>

      <main className="main">
        {error && <p className="error" role="alert">{error}</p>}
        {view === 'clients' && <AddClient onAdded={load} />}
        {view !== 'work' && <AddWebsite clients={isAgency ? clients : []} onAdded={load} />}

        {view !== 'work' && (
          <div className="toolbar">
            <button type="button" className="btn btn--quiet" onClick={() => void load()}>Refresh readings</button>
            <span className="hint">Shows the latest stored results. Live re-checks arrive with the monitoring worker.</span>
            <div className="seg" role="group" aria-label="Layout">
              <button type="button" aria-pressed={mode === 'board'} onClick={() => setMode('board')}>Board</button>
              <button type="button" aria-pressed={mode === 'list'} onClick={() => setMode('list')}>List</button>
            </div>
          </div>
        )}

        {view === 'clients' && (clientModels.length === 0
          ? <Empty text="No clients yet. Clients group the websites you look after for one customer." />
          : mode === 'board'
            ? <HealthBoard items={clientModels} noun="Clients" render={(c) => <ClientCard client={c} onHelp={(issue, host) => setHelp({ issue, host })} />} />
            : <SiteList sites={clientModels.flatMap((c) => c.sites)} clients={clientModels} />)}
        {view === 'websites' && (siteModels.length === 0
          ? <Empty text="Add your first website above. No passwords or hosting details needed for basic monitoring." />
          : mode === 'board'
            ? <HealthBoard items={siteModels} noun="Websites" render={(s) => <SiteCard site={s} onHelp={(issue, host) => setHelp({ issue, host })} />} />
            : <SiteList sites={siteModels} clients={isAgency ? clientModels : undefined} />)}
        {view === 'work' && (workTickets.length === 0
          ? <Empty text="No open requests." />
          : <WorkBoard tickets={workTickets} onMove={onMove} />)}
      </main>
      <HelpDialog issue={help?.issue ?? null} host={help?.host ?? ''} onClose={() => setHelp(null)} />
    </div>
  );
}

function AddWebsite({ clients, onAdded }: { clients: ApiClient[]; onAdded: () => void }) {
  const [url, setUrl] = useState('');
  const [client, setClient] = useState('');
  const [msg, setMsg] = useState<{ kind: 'error' | 'ok'; text: string } | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    try {
      const r = await api<{ target: { scheme: string; host: string; path: string }; note: string | null }>(
        'POST', 'websites', { url, client_id: client || null });
      setMsg({ kind: 'ok', text: `Added ${r.target.host}. First checks will appear once monitoring runs.${r.note ? ' ' + r.note : ''}` });
      setUrl('');
      onAdded();
    } catch (err) {
      setMsg({ kind: 'error', text: err instanceof Error ? err.message : String(err) });
    }
  }

  return (
    <form className="add" onSubmit={submit}>
      <label className="field add__url">Add a website
        <input inputMode="url" placeholder="shop.example.com" required value={url} onChange={(e) => setUrl(e.target.value)} />
      </label>
      {clients.length > 0 && (
        <label className="field">Client
          <select value={client} onChange={(e) => setClient(e.target.value)}>
            <option value="">No client</option>
            {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
      )}
      <button className="btn">Add website</button>
      {msg && <p className={msg.kind === 'error' ? 'error' : 'ok'} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
    </form>
  );
}

function AddClient({ onAdded }: { onAdded: () => void }) {
  const [name, setName] = useState('');
  const [msg, setMsg] = useState<{ kind: 'error' | 'ok'; text: string } | null>(null);
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    try {
      await api('POST', 'clients', { name });
      setMsg({ kind: 'ok', text: `Added client ${name}.` });
      setName('');
      onAdded();
    } catch (err) {
      setMsg({ kind: 'error', text: err instanceof Error ? err.message : String(err) });
    }
  }
  return (
    <form className="add" onSubmit={submit}>
      <label className="field add__url">Add a client
        <input placeholder="Company name" required maxLength={160} value={name} onChange={(e) => setName(e.target.value)} />
      </label>
      <button className="btn">Add client</button>
      {msg && <p className={msg.kind === 'error' ? 'error' : 'ok'} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
    </form>
  );
}

function Empty({ text }: { text: string }) {
  return <p className="empty">{text}</p>;
}
