import { RepairControls } from './pages/Repairs';
import { Integration, Integrations } from './pages/Integrations';
import Billing from './pages/Billing';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError, session, type ApiClient, type ApiMe, type ApiSite, type ApiTicket, type ApiWorkspace } from './api';
import { Ctx, type WorkspaceCtx } from './app-context';
import type { Issue } from './demo-data';
import { HelpDialog } from './components';
import { EmailBanner, LinkLanding, OwnershipDialog, SecurityPanel, StepUpDialog, TeamPanel, TicketDialog } from './secure';
import { navigate, onLinkClick, useRoute, type Route } from './router';
import Overview from './pages/Overview';
import { ClientsBoard, WebsitesBoard } from './pages/Boards';
import ClientDetail from './pages/ClientDetail';
import WebsiteDetail from './pages/WebsiteDetail';
import Work from './pages/Work';
import Alerts, { AlertPrefs } from './pages/Alerts';
import Diagnosis from './pages/Diagnosis';

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
    return <AuthScreen invited={!!pendingInvite} onSignedIn={(t, ws) => { session.setWorkspaces(null); session.setToken(t); session.setWorkspace(ws); setToken(t); }} />;
  }
  if (pendingInvite) return <LinkLanding kind="invite" token={pendingInvite} signedIn onDone={leaveLink} />;
  return <Workspace onSignOut={() => {
    void api('POST', 'auth/logout', undefined, { workspace: false }).catch(() => undefined);
    session.setToken(null); session.setWorkspace(null); session.setWorkspaces(null); setToken(null);
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

type NavItem = { to: string; label: string; match: Route['name'][]; icon: string };

function Workspace({ onSignOut }: { onSignOut: () => void }) {
  const route = useRoute();
  const [workspaces, setWorkspaces] = useState<ApiWorkspace[]>(session.workspaces);
  const [me, setMe] = useState<ApiMe | null>(null);
  const [ws, setWs] = useState<string | null>(session.workspace());
  const [clients, setClients] = useState<ApiClient[]>([]);
  const [sites, setSites] = useState<ApiSite[]>([]);
  const [tickets, setTickets] = useState<ApiTicket[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [help, setHelp] = useState<{ issue: Issue; host: string } | null>(null);
  const [verifySite, setVerifySite] = useState<{ id: string; name: string } | null>(null);
  const [more, setMore] = useState(false);

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
        setWorkspaces(r.data); session.setWorkspaces(r.data);
        const pick = r.data.find((w) => w.id === ws)?.id ?? r.data[0]?.id ?? null;
        session.setWorkspace(pick); setWs(pick);
      })
      .catch((e) => (e instanceof ApiError && e.status === 401 ? onSignOut() : setError(e.message)));
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Board data is requested as soon as the stored workspace is known, in
  // parallel with the workspace list (NFR-02).
  const [clientsLoaded, setClientsLoaded] = useState(false);
  // Requested without waiting for the workspace kind: a solo workspace simply
  // answers with no clients, and RLS decides what any role sees.
  const loadClients = useCallback(async () => {
    if (!ws) return;
    setClients((await api<{ data: ApiClient[] }>('GET', 'boards/clients')).data);
    setClientsLoaded(true);
  }, [ws]);
  const loadBoards = useCallback(async () => {
    if (!ws) return;
    const [s, t] = await Promise.all([
      api<{ data: ApiSite[] }>('GET', 'boards/websites'),
      api<{ data: ApiTicket[] }>('GET', 'tickets'),
    ]);
    setSites(s.data); setTickets(t.data); setLoaded(true);
  }, [ws]);
  const guard = useCallback(async (p: Promise<void>) => {
    try { await p; } catch (e) {
      if (e instanceof ApiError && e.status === 404) { session.setWorkspace(null); window.location.reload(); return; } // access removed
      setError(e instanceof Error ? e.message : String(e));
    }
  }, []);
  const reload = useCallback(async () => {
    setError(null);
    await guard(Promise.all([loadBoards(), loadClients()]).then(() => undefined));
  }, [guard, loadBoards, loadClients]);
  useEffect(() => { void guard(loadBoards()); }, [guard, loadBoards]);
  useEffect(() => { void guard(loadClients()); }, [guard, loadClients]);

  // Unread alert count for the nav badge, refreshed each minute (ALR-001 in-app inbox).
  const [unread, setUnread] = useState(0);
  const loadUnread = useCallback(() => {
    if (!ws) return;
    api<{ unread: number }>('GET', 'notifications').then((r) => setUnread(r.unread)).catch(() => undefined);
  }, [ws]);
  useEffect(() => { loadUnread(); const t = window.setInterval(loadUnread, 60_000); return () => window.clearInterval(t); }, [loadUnread]);

  const openTicket = useCallback((id: string) => navigate({ name: 'work', ticket: id }), []);
  const report = useCallback(async (siteId: string, host: string) => {
    const title = window.prompt(`What's wrong with ${host}? Describe it in a few words.`);
    if (!title?.trim()) return;
    try {
      const r = await api<{ id: string }>('POST', 'tickets', { website_id: siteId, title: title.trim() });
      await reload();
      openTicket(r.id);
    } catch (e) { setError(e instanceof Error ? e.message : String(e)); }
  }, [reload, openTicket]);

  const ctx: WorkspaceCtx = useMemo(() => ({
    me, workspace: current, role, isAgency, isStaff, canVerify, sites, clients, tickets, loaded: loaded && clientsLoaded, reload,
    openHelp: (issue, host) => setHelp({ issue, host }),
    openVerify: setVerifySite, openTicket, report: (id, host) => void report(id, host), setError,
  }), [me, current, role, isAgency, isStaff, canVerify, sites, clients, tickets, loaded, clientsLoaded, reload, openTicket, report]);

  const primary: NavItem[] = [
    { to: '/', label: 'Overview', match: ['overview'], icon: '◎' },
    ...(isAgency ? [{ to: '/clients', label: 'Clients', match: ['clients', 'client'] as Route['name'][], icon: '▦' }] : []),
    { to: '/websites', label: 'Websites', match: ['websites', 'website', 'diagnostic'], icon: '◱' },
    ...(role !== 'billing' ? [{ to: '/work', label: isAgency ? 'Work queue' : 'Issues', match: ['work'] as Route['name'][], icon: '☰' }] : []),
  ];
  const secondary: NavItem[] = [
    { to: '/alerts', label: unread ? `Alerts (${unread})` : 'Alerts', match: ['alerts'], icon: '🔔' },
    ...(canManageTeam ? [{ to: '/team', label: 'Team', match: ['team'] as Route['name'][], icon: '👥' }] : []),
    ...(['owner', 'admin', 'billing'].includes(role) ? [{ to: '/billing', label: 'Billing', match: ['billing'] as Route['name'][], icon: '₹' }] : []),
    ...(['owner', 'admin'].includes(role) ? [{ to: '/integrations', label: 'Integrations', match: ['integrations', 'integration'] as Route['name'][], icon: '⇄' }] : []),
    { to: '/settings', label: 'Settings', match: ['settings'], icon: '⚙' },
  ];
  const isOn = (n: NavItem) => n.match.includes(route.name);
  const link = (n: NavItem, cls: string) => (
    <a key={n.to} href={n.to} className={cls} aria-current={isOn(n) ? 'page' : undefined}
      onClick={(e) => { onLinkClick(e); setMore(false); }}>
      <span className="nav__icon" aria-hidden="true">{n.icon}</span><span className="nav__label">{n.label}</span>
    </a>
  );

  const switcher = workspaces.length > 1 ? (
    <label className="ws-switch">
      <span className="sr-only">Workspace</span>
      <select value={ws ?? ''} onChange={(e) => {
        // Drop everything from the previous workspace before loading the next (AC-ACC-004.1).
        setClients([]); setSites([]); setTickets([]); setLoaded(false); setClientsLoaded(false); setVerifySite(null); setHelp(null); setMore(false);
        session.setWorkspace(e.target.value); setWs(e.target.value);
        navigate('/', { replace: true });
      }}>
        {workspaces.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
      </select>
    </label>
  ) : <p className="top__ws">{current?.name ?? 'Loading…'}</p>;

  // Pages a role can't use fall back to the overview rather than an empty screen.
  const r = route;
  const blocked = (r.name === 'clients' || r.name === 'client') && !isAgency
    || r.name === 'team' && !canManageTeam
    || r.name === 'work' && role === 'billing'
    || (r.name === 'integrations' || r.name === 'integration') && !['owner', 'admin'].includes(role)
    || r.name === 'billing' && !['owner', 'admin', 'billing'].includes(role);

  return (
    <Ctx.Provider value={ctx}>
      <div className="app">
        <a className="skip" href="#main">Skip to content</a>
        <StepUpDialog />
        <header className="top">
          <div className="top__brand">
            <span className="logo" aria-hidden="true">U</span>
            <div><p className="top__name">Upfixy</p>{switcher}</div>
          </div>
          <nav aria-label="Main" className="nav nav--top">
            {[...primary, ...secondary].map((n) => link(n, 'tab'))}
            <button type="button" className="tab" onClick={onSignOut}>Sign out</button>
          </nav>
        </header>
        <EmailBanner me={me} />

        <main className="main" id="main" tabIndex={-1}>
          {error && <p className="error" role="alert">{error}</p>}
          {!current ? <p className="hint" role="status">Loading…</p>
            : blocked || r.name === 'not_found' ? <NotFound />
            : r.name === 'overview' ? <Overview />
            : r.name === 'clients' ? <ClientsBoard />
            : r.name === 'client' ? <ClientDetail id={r.id} />
            : r.name === 'websites' ? <WebsitesBoard />
            : r.name === 'website' ? <WebsiteDetail id={r.id} tab={r.tab} />
            : r.name === 'work' ? <Work />
            : r.name === 'team' ? <TeamPanel clients={clients} myRole={role} />
            : r.name === 'alerts' ? <Alerts onRead={loadUnread} />
            : r.name === 'diagnostic' ? <Diagnosis id={r.id} />
            : r.name === 'integrations' ? <Integrations />
            : r.name === 'billing' ? <Billing />
            : r.name === 'integration' ? <Integration id={r.id} />
            : r.name === 'settings' ? (me ? <div className="page"><SecurityPanel me={me} onChange={() => void loadMe()} /><AlertPrefs /><RepairControls /></div> : null)
            : null}
        </main>

        <nav aria-label="Main" className="nav nav--bottom">
          {primary.map((n) => link(n, 'bnav'))}
          <button type="button" className="bnav" aria-expanded={more} aria-controls="more-sheet" onClick={() => setMore(!more)}
            aria-current={secondary.some(isOn) ? 'page' : undefined}>
            <span className="nav__icon" aria-hidden="true">⋯</span><span className="nav__label">More{unread ? <span className="badge" aria-label={`${unread} unread alerts`}>{unread}</span> : null}</span>
          </button>
        </nav>
        {more && (
          <div className="sheet" id="more-sheet" role="dialog" aria-label="More" onKeyDown={(e) => e.key === 'Escape' && setMore(false)}>
            <div className="sheet__inner">
              {workspaces.length > 1 && <div className="sheet__ws">{switcher}</div>}
              {secondary.map((n) => link(n, 'sheet__item'))}
              <button type="button" className="sheet__item" onClick={onSignOut}><span className="nav__icon" aria-hidden="true">↪</span><span className="nav__label">Sign out</span></button>
              <button type="button" className="btn btn--quiet" onClick={() => setMore(false)} autoFocus>Close</button>
            </div>
          </div>
        )}

        <HelpDialog issue={help?.issue ?? null} host={help?.host ?? ''} onClose={() => setHelp(null)} />
        <OwnershipDialog websiteId={verifySite?.id ?? null} name={verifySite?.name ?? ''} onClose={() => { setVerifySite(null); void reload(); }} />
        <TicketDialog ticketId={r.name === 'work' ? r.ticket ?? null : null} role={role} meId={me?.id ?? null}
          onClose={() => navigate('/work', { replace: true })} onChanged={() => void reload()} />
      </div>
    </Ctx.Provider>
  );
}

function NotFound() {
  return <div className="page"><h1>Page not found</h1><p>This page doesn't exist or isn't available to your role. <a href="/" onClick={onLinkClick}>Go to the overview</a></p></div>;
}
