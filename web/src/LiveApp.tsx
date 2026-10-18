import { useCallback, useEffect, useState } from 'react';
import type { TicketState } from '@upfixy/core';
import { api, ApiError, session, type ApiClient, type ApiMe, type ApiSite, type ApiTicket, type ApiWorkspace } from './api';
import { toClient, toSite } from './live-mapping';
import type { Issue, Ticket } from './demo-data';
import { ClientCard, SiteCard, HealthBoard, SiteList, WorkBoard, HelpDialog } from './components';
import { EmailBanner, LinkLanding, OwnershipDialog, SecurityPanel, StepUpDialog, TeamPanel, TicketDialog } from './secure';

type View = 'clients' | 'websites' | 'work' | 'team' | 'security';

const PENDING_INVITE = 'upfixy.pendingInvite';
const store = {
  get: (k: string) => { try { return sessionStorage.getItem(k); } catch { return null; } },
  set: (k: string, v: string | null) => { try { v === null ? sessionStorage.removeItem(k) : sessionStorage.setItem(k, v); } catch { /* unavailable */ } },
};

/** Email links land on /verify-email or /accept-invite with ?token=… */
function linkFromUrl(): { kind: 'verify' | 'invite'; token: string } | null {
  const token = new URLSearchParams(window.location.search).get('token');
  if (token && window.location.pathname === '/verify-email') return { kind: 'verify', token };
  if (token && window.location.pathname === '/accept-invite') return { kind: 'invite', token };
  return null;
}

export default function LiveApp() {
  const [token, setToken] = useState(session.token());
  const [link, setLink] = useState(() => {
    const l = linkFromUrl();
    if (l?.kind === 'invite') store.set(PENDING_INVITE, l.token); // survive sign-in / sign-up
    return l;
  });
  const pendingInvite = store.get(PENDING_INVITE);

  const leaveLink = (workspace?: string) => {
    if (workspace) session.setWorkspace(workspace);
    store.set(PENDING_INVITE, null);
    window.history.replaceState(null, '', '/');
    setLink(null);
  };

  if (link?.kind === 'verify') return <LinkLanding kind="verify" token={link.token} signedIn={!!token} onDone={leaveLink} />;
  if (!token) {
    return <AuthScreen invited={!!pendingInvite} onSignedIn={(t, ws) => { session.setToken(t); session.setWorkspace(ws); setToken(t); }} />;
  }
  if (pendingInvite) return <LinkLanding kind="invite" token={pendingInvite} signedIn onDone={leaveLink} />;
  return <Workspace onSignOut={() => {
    void api('POST', 'auth/logout', undefined, { workspace: false }).catch(() => undefined);
    session.setToken(null); session.setWorkspace(null); setToken(null);
  }} />;
}

function AuthScreen({ onSignedIn, invited }: { onSignedIn: (token: string, ws: string | null) => void; invited: boolean }) {
  const [mode, setMode] = useState<'login' | 'register'>(invited ? 'login' : 'register');
  const [form, setForm] = useState({ email: '', password: '', account_type: 'solo', workspace_name: '' });
  const [challenge, setChallenge] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value });

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      if (challenge) {
        // Second step: the challenge token can only be swapped for a full session.
        session.setToken(challenge);
        const r = await api<{ token: string }>('POST', 'auth/mfa/login', { code }, { workspace: false });
        onSignedIn(r.token, null);
      } else if (mode === 'register') {
        const r = await api<{ token: string; workspace_id: string }>('POST', 'auth/register', form, { workspace: false });
        onSignedIn(r.token, r.workspace_id);
      } else {
        const r = await api<{ mfa_required: boolean; token?: string; challenge_token?: string }>(
          'POST', 'auth/login', { email: form.email, password: form.password }, { workspace: false });
        if (r.mfa_required) setChallenge(r.challenge_token!);
        else onSignedIn(r.token!, null);
      }
    } catch (err) {
      if (challenge && err instanceof ApiError && err.status === 401) { setChallenge(null); setCode(''); }
      session.setToken(null);
      setError(err instanceof ApiError ? err.message : 'Upfixy is not reachable. Is the API running?');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="auth">
      <form className="auth__card" onSubmit={submit}>
        <span className="logo" aria-hidden="true">U</span>
        {challenge ? (
          <>
            <h1>Enter your code</h1>
            <p>Open your authenticator app and enter the 6-digit code for Upfixy. Lost your phone? Use a recovery code.</p>
            <label className="field">Authentication code
              <input inputMode="numeric" autoComplete="one-time-code" autoFocus required maxLength={20} value={code} onChange={(e) => setCode(e.target.value)} />
            </label>
          </>
        ) : (
          <>
            <h1>{mode === 'register' ? 'Create your Upfixy account' : 'Sign in to Upfixy'}</h1>
            {invited && <p className="ok">Sign in or create an account with the invited email address to join the workspace.</p>}
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
          </>
        )}
        {error && <p className="error" role="alert">{error}</p>}
        <button className="btn" disabled={busy}>{challenge ? 'Verify and sign in' : mode === 'register' ? 'Create account' : 'Sign in'}</button>
        {!challenge && (
          <button type="button" className="link" onClick={() => { setMode(mode === 'register' ? 'login' : 'register'); setError(null); }}>
            {mode === 'register' ? 'I already have an account' : 'Create a new account'}
          </button>
        )}
      </form>
    </div>
  );
}

function Workspace({ onSignOut }: { onSignOut: () => void }) {
  const [workspaces, setWorkspaces] = useState<ApiWorkspace[]>([]);
  const [me, setMe] = useState<ApiMe | null>(null);
  const [ws, setWs] = useState<string | null>(session.workspace());
  const [view, setView] = useState<View>('websites');
  const [mode, setMode] = useState<'board' | 'list'>('board');
  const [clients, setClients] = useState<ApiClient[]>([]);
  const [sites, setSites] = useState<ApiSite[]>([]);
  const [tickets, setTickets] = useState<ApiTicket[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [help, setHelp] = useState<{ issue: Issue; host: string } | null>(null);
  const [verifySite, setVerifySite] = useState<{ id: string; name: string } | null>(null);
  const [openTicket, setOpenTicket] = useState<string | null>(null);

  const current = workspaces.find((w) => w.id === ws);
  const role = current?.role ?? '';
  const isAgency = current?.kind === 'agency';
  const isStaff = ['owner', 'admin', 'technician'].includes(role);
  const canVerify = ['owner', 'admin', 'client_owner'].includes(role);
  const canManageTeam = isAgency && ['owner', 'admin', 'client_owner'].includes(role);

  const loadMe = useCallback(() => api<ApiMe>('GET', 'auth/me', undefined, { workspace: false }).then(setMe), []);

  useEffect(() => {
    void loadMe().catch(() => undefined);
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
      if (e instanceof ApiError && e.status === 404) { session.setWorkspace(null); window.location.reload(); return; } // access removed
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

  async function report(siteId: string, host: string) {
    const title = window.prompt(`What's wrong with ${host}? Describe it in a few words.`);
    if (!title?.trim()) return;
    try {
      const r = await api<{ id: string }>('POST', 'tickets', { website_id: siteId, title: title.trim() });
      await load();
      setView('work');
      setOpenTicket(r.id);
    } catch (e) { setError(e instanceof Error ? e.message : String(e)); }
  }

  const workTickets: Ticket[] = tickets.map((t) => ({
    id: t.id, title: t.title, host: t.website, client: '', state: t.state, assignee: null, hasApproval: false,
  }));
  const views: Array<{ id: View; label: string }> = [
    ...(isAgency ? [{ id: 'clients' as View, label: 'Clients' }] : []),
    { id: 'websites', label: 'Websites' },
    { id: 'work', label: isAgency ? 'Work queue' : 'Issues' },
    ...(canManageTeam ? [{ id: 'team' as View, label: 'Team' }] : []),
    { id: 'security', label: 'Security' },
  ];
  const siteModels = sites.map(toSite);
  const clientModels = clients.map(toClient);
  const siteActions = (siteId: string, host: string) => (
    <>
      {canVerify && <button type="button" className="link" onClick={() => setVerifySite({ id: siteId, name: host })}>Verify ownership</button>}
      {role !== 'billing' && <button type="button" className="link" onClick={() => void report(siteId, host)}>Report a problem</button>}
    </>
  );

  return (
    <div className="app">
      <StepUpDialog />
      <header className="top">
        <div className="top__brand">
          <span className="logo" aria-hidden="true">U</span>
          <div>
            <p className="top__name">Upfixy</p>
            {workspaces.length > 1 ? (
              <label className="ws-switch">
                <span className="sr-only">Workspace</span>
                <select value={ws ?? ''} onChange={(e) => {
                  // Drop everything from the previous workspace before loading the next (AC-ACC-004.1).
                  setClients([]); setSites([]); setTickets([]); setOpenTicket(null); setVerifySite(null);
                  session.setWorkspace(e.target.value); setWs(e.target.value);
                }}>
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
      <EmailBanner me={me} />

      <main className="main">
        {error && <p className="error" role="alert">{error}</p>}
        {view === 'security' && me && <SecurityPanel me={me} onChange={() => void loadMe()} />}
        {view === 'team' && <TeamPanel clients={clients} myRole={role} />}

        {view === 'clients' && isStaff && <AddClient onAdded={load} />}
        {(view === 'clients' || view === 'websites') && <AddWebsite clients={isAgency ? clients : []} onAdded={load} />}

        {(view === 'clients' || view === 'websites') && (
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
            ? <HealthBoard items={siteModels} noun="Websites" render={(s) => (
                <SiteCard site={s} onHelp={(issue, host) => setHelp({ issue, host })} actions={siteActions(s.id, s.host)} />)} />
            : <SiteList sites={siteModels} clients={isAgency ? clientModels : undefined} />)}
        {view === 'work' && (workTickets.length === 0
          ? <Empty text="No open requests. Use “Report a problem” on a website to start one." />
          : <WorkBoard tickets={workTickets} onMove={onMove} onOpen={setOpenTicket} canMove={isStaff} />)}
      </main>
      <HelpDialog issue={help?.issue ?? null} host={help?.host ?? ''} onClose={() => setHelp(null)} />
      <OwnershipDialog websiteId={verifySite?.id ?? null} name={verifySite?.name ?? ''} onClose={() => setVerifySite(null)} />
      <TicketDialog ticketId={openTicket} role={role} onClose={() => setOpenTicket(null)} onChanged={() => void load()} />
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
