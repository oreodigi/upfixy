// M1 hardening UI: step-up, security settings, team, ownership, quotes.
import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';
import { TICKET_LABELS } from '@upfixy/core';
import {
  api, ApiError, rupees, setStepUpHandler,
  type ApiAction, type ApiClaim, type ApiInvitation, type ApiMe, type ApiMember, type ApiTarget, type ApiTicketDetail,
} from './api';

const errText = (e: unknown) => (e instanceof Error ? e.message : String(e));

/** Shared <dialog> shell: focus-trapped by the browser, closes on Escape. */
function Modal({ open, title, onClose, children }: { open: boolean; title: string; onClose: () => void; children: React.ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    if (open && !ref.current?.open) ref.current?.showModal();
    if (!open && ref.current?.open) ref.current.close();
  }, [open]);
  return (
    <dialog ref={ref} className="help" onClose={onClose} aria-label={title}>
      <h2>{title}</h2>
      {children}
    </dialog>
  );
}

function Copy({ text }: { text: string }) {
  const [done, setDone] = useState(false);
  return (
    <button type="button" className="btn btn--quiet btn--sm" onClick={() => navigator.clipboard?.writeText(text).then(() => setDone(true))}>
      {done ? 'Copied' : 'Copy'}
    </button>
  );
}

// ---------------------------------------------------------------- step-up
/** Mount once. Sensitive requests that need a fresh code pause here, then retry. */
export function StepUpDialog() {
  const [pending, setPending] = useState<((ok: boolean) => void) | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setStepUpHandler(() => new Promise<boolean>((resolve) => { setCode(''); setError(null); setPending(() => resolve); }));
    return () => setStepUpHandler(null);
  }, []);

  const finish = (ok: boolean) => { pending?.(ok); setPending(null); };

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    try {
      await api('POST', 'mfa/verify', { code }, { workspace: false, retried: true });
      finish(true);
    } catch (err) {
      setError(errText(err));
    }
  }

  return (
    <Modal open={pending !== null} title="Confirm it's you" onClose={() => finish(false)}>
      <form onSubmit={submit} className="stack">
        <p>Enter the 6-digit code from your authenticator app to continue. You won't be asked again for 15 minutes.</p>
        <label className="field">Authentication code
          <input inputMode="numeric" autoComplete="one-time-code" autoFocus required maxLength={20} value={code} onChange={(e) => setCode(e.target.value)} />
        </label>
        {error && <p className="error" role="alert">{error}</p>}
        <div className="help__actions">
          <button className="btn">Confirm</button>
          <button type="button" className="btn btn--quiet" onClick={() => finish(false)}>Cancel</button>
        </div>
      </form>
    </Modal>
  );
}

// ------------------------------------------------------------ email banner
export function EmailBanner({ me }: { me: ApiMe | null }) {
  const [msg, setMsg] = useState<string | null>(null);
  if (!me || me.email_verified) return null;
  return (
    <p className="banner" role="status">
      Confirm your email address ({me.email}) to approve work and invite people.{' '}
      <button type="button" className="link" onClick={() => api('POST', 'auth/verify-email/resend', undefined, { workspace: false })
        .then(() => setMsg('New link sent. Check your inbox.')).catch((e) => setMsg(errText(e)))}>Send a new link</button>
      {msg && <span> {msg}</span>}
    </p>
  );
}

// -------------------------------------------------------------- security
export function SecurityPanel({ me, onChange }: { me: ApiMe; onChange: () => void }) {
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string; svg: string } | null>(null);
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function start() {
    setError(null);
    try {
      const r = await api<{ secret: string; otpauth_url: string }>('POST', 'mfa/setup', undefined, { workspace: false });
      setSetup({ ...r, svg: await QRCode.toString(r.otpauth_url, { type: 'svg', margin: 1, width: 180 }) });
    } catch (e) { setError(errText(e)); }
  }
  async function confirm(e: React.FormEvent) {
    e.preventDefault();
    try {
      const r = await api<{ recovery_codes: string[] }>('POST', 'mfa/confirm', { code }, { workspace: false });
      setCodes(r.recovery_codes); setSetup(null); setCode(''); onChange();
    } catch (err) { setError(errText(err)); }
  }
  async function disable(e: React.FormEvent) {
    e.preventDefault();
    try {
      await api('POST', 'mfa/disable', { code }, { workspace: false });
      setCode(''); onChange();
    } catch (err) { setError(errText(err)); }
  }
  async function signOutEverywhere() {
    await api('POST', 'auth/logout-all', undefined, { workspace: false }).catch(() => undefined);
    window.location.reload();
  }

  return (
    <section className="panel">
      <h1>Security</h1>
      <h2>Two-step verification</h2>
      {codes && (
        <div className="ok stack">
          <p><strong>Two-step verification is on.</strong> Save these recovery codes somewhere safe. Each works once if you lose your phone. They won't be shown again.</p>
          <ul className="codes">{codes.map((c) => <li key={c}>{c}</li>)}</ul>
          <Copy text={codes.join('\n')} />
        </div>
      )}
      {!me.mfa_enabled && !setup && !codes && (
        <div className="stack">
          <p>Required for inviting people, changing roles, revoking ownership proof and approving paid work.</p>
          <button type="button" className="btn" onClick={start}>Turn on two-step verification</button>
        </div>
      )}
      {setup && (
        <form className="stack" onSubmit={confirm}>
          <p>Scan this code with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…), then enter the 6-digit code it shows.</p>
          <div className="qr" dangerouslySetInnerHTML={{ __html: setup.svg }} aria-label="QR code for your authenticator app" role="img" />
          <p className="hint">Can't scan? Enter this key manually: <code>{setup.secret}</code> <Copy text={setup.secret} /></p>
          <label className="field">Code from the app
            <input inputMode="numeric" autoComplete="one-time-code" required maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} />
          </label>
          <button className="btn">Turn on</button>
        </form>
      )}
      {me.mfa_enabled && !codes && (
        <form className="stack" onSubmit={disable}>
          <p className="ok">Two-step verification is on.</p>
          <label className="field">To turn it off, enter a current code or a recovery code
            <input required maxLength={20} value={code} onChange={(e) => setCode(e.target.value)} />
          </label>
          <button className="btn btn--quiet">Turn off two-step verification</button>
        </form>
      )}
      {error && <p className="error" role="alert">{error}</p>}
      <h2>Sessions</h2>
      <p>Signed in on a device you no longer use? Sign out everywhere, including this device.</p>
      <button type="button" className="btn btn--quiet" onClick={signOutEverywhere}>Sign out everywhere</button>
    </section>
  );
}

// ------------------------------------------------------------------ team
const ROLES: Record<string, string> = {
  owner: 'Owner', admin: 'Admin', technician: 'Technician', billing: 'Billing',
  client_owner: 'Client owner', client_staff: 'Client staff',
};

export function TeamPanel({ clients, myRole }: { clients: Array<{ id: string; name: string }>; myRole: string }) {
  const [members, setMembers] = useState<ApiMember[]>([]);
  const [invites, setInvites] = useState<ApiInvitation[]>([]);
  const [form, setForm] = useState({ email: '', role: 'technician', client_id: '' });
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const isAdmin = myRole === 'owner' || myRole === 'admin';
  const roleOptions = isAdmin ? ['admin', 'technician', 'billing', 'client_owner', 'client_staff'] : ['client_staff'];
  const needsClient = form.role === 'client_owner' || form.role === 'client_staff';

  const load = () => Promise.all([
    api<{ data: ApiMember[] }>('GET', 'members').then((r) => setMembers(r.data)),
    api<{ data: ApiInvitation[] }>('GET', 'invitations').then((r) => setInvites(r.data)),
  ]).catch((e) => setMsg({ kind: 'error', text: errText(e) }));
  useEffect(() => { void load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const act = (fn: () => Promise<unknown>, ok: string) => fn().then(() => { setMsg({ kind: 'ok', text: ok }); return load(); })
    .catch((e) => setMsg({ kind: 'error', text: errText(e) }));

  return (
    <section className="panel">
      <h1>Team</h1>
      {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
      <form className="add" onSubmit={(e) => { e.preventDefault(); void act(() => api('POST', 'invitations', { ...form, client_id: needsClient ? form.client_id : null }), `Invitation sent to ${form.email}.`); }}>
        <label className="field add__url">Invite by email
          <input type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
        </label>
        <label className="field">Role
          <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
            {roleOptions.map((r) => <option key={r} value={r}>{ROLES[r]}</option>)}
          </select>
        </label>
        {needsClient && (
          <label className="field">Client
            <select required value={form.client_id} onChange={(e) => setForm({ ...form, client_id: e.target.value })}>
              <option value="">Choose…</option>
              {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </label>
        )}
        <button className="btn">Send invitation</button>
      </form>

      <div className="table-wrap">
        <table className="list">
          <thead><tr><th scope="col">Member</th><th scope="col">Role</th><th scope="col">Sign-in</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
          <tbody>
            {members.map((m) => (
              <tr key={m.user_id} className={m.suspended_at ? 'muted' : ''}>
                <th scope="row">{m.email}{m.suspended_at && <span className="tag">Access removed</span>}</th>
                <td>
                  {isAdmin && !m.suspended_at ? (
                    <select aria-label={`Role for ${m.email}`} value={m.role}
                      onChange={(e) => void act(() => api('PATCH', `members/${m.user_id}`, { role: e.target.value }), `${m.email} is now ${ROLES[e.target.value]}.`)}>
                      {Object.entries(ROLES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                  ) : ROLES[m.role]}
                </td>
                <td>{m.mfa_enabled ? 'Two-step on' : 'Password only'}{m.email_verified ? '' : ', email unconfirmed'}</td>
                <td>
                  {isAdmin && (m.suspended_at
                    ? <button type="button" className="link" onClick={() => void act(() => api('PATCH', `members/${m.user_id}`, { active: true }), `Restored access for ${m.email}.`)}>Restore access</button>
                    : <button type="button" className="link link--danger" onClick={() => window.confirm(`Remove ${m.email}'s access to this workspace? Their open requests will be unassigned.`)
                        && void act(() => api('PATCH', `members/${m.user_id}`, { active: false }), `Removed ${m.email}'s access.`)}>Remove access</button>)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {invites.some((i) => !i.accepted_at && !i.revoked_at) && (
        <>
          <h2>Pending invitations</h2>
          <ul className="plain">
            {invites.filter((i) => !i.accepted_at && !i.revoked_at).map((i) => (
              <li key={i.id}>
                {i.email}, {ROLES[i.role]}, expires {new Date(i.expires_at).toLocaleDateString('en-IN')}{' '}
                <button type="button" className="link" onClick={() => void act(() => api('POST', `invitations/${i.id}/revoke`), `Invitation to ${i.email} revoked.`)}>Revoke</button>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  );
}

// --------------------------------------------------------- ownership
export function OwnershipDialog({ websiteId, name, onClose }: { websiteId: string | null; name: string; onClose: () => void }) {
  const [targets, setTargets] = useState<ApiTarget[]>([]);
  const [claims, setClaims] = useState<ApiClaim[]>([]);
  const [host, setHost] = useState('');
  const [method, setMethod] = useState<'dns_txt' | 'html_file'>('dns_txt');
  const [error, setError] = useState<string | null>(null);
  const [checking, setChecking] = useState(false);

  const load = async (id: string) => {
    const [w, c] = await Promise.all([
      api<{ targets: ApiTarget[] }>('GET', `websites/${id}`),
      api<{ data: ApiClaim[] }>('GET', `websites/${id}/claims`),
    ]);
    setTargets(w.targets); setClaims(c.data);
    setHost((h) => h || w.targets[0]?.host || '');
  };
  useEffect(() => { if (websiteId) { setError(null); load(websiteId).catch((e) => setError(errText(e))); } }, [websiteId]);

  const current = claims.find((c) => c.host === host && c.method === method && c.status === 'challenge_issued');
  const verified = claims.find((c) => c.host === host && c.status === 'verified');
  const isIp = /^\[|^[0-9.]+$/.test(host);

  async function start() {
    setError(null);
    try { await api('POST', `websites/${websiteId}/claims`, { host, method }); await load(websiteId!); }
    catch (e) { setError(errText(e)); }
  }
  async function check(id: string) {
    setChecking(true); setError(null);
    try {
      await api('POST', `claims/${id}/check`);
      await new Promise((r) => setTimeout(r, 1200)); // the worker usually answers within a second
      await load(websiteId!);
    } catch (e) { setError(errText(e)); } finally { setChecking(false); }
  }

  return (
    <Modal open={websiteId !== null} title={`Verify ownership of ${name}`} onClose={onClose}>
      <div className="stack">
        <p>Monitoring works without this. Verification proves you control the address, which unlocks deeper checks and lets fixes go ahead.</p>
        {targets.length > 1 && (
          <label className="field">Address
            <select value={host} onChange={(e) => setHost(e.target.value)}>
              {targets.map((t) => <option key={t.id} value={t.host}>{t.host}</option>)}
            </select>
          </label>
        )}
        {verified ? (
          <p className="ok">{host} is verified until {new Date(verified.proof_expires_at!).toLocaleDateString('en-IN')}.</p>
        ) : (
          <>
            <fieldset className="choice">
              <legend>How do you want to prove it?</legend>
              <label><input type="radio" name="m" checked={method === 'dns_txt'} disabled={isIp} onChange={() => setMethod('dns_txt')} /> Add a DNS record (best if you manage the domain)</label>
              <label><input type="radio" name="m" checked={method === 'html_file'} onChange={() => setMethod('html_file')} /> Upload a small file to the website</label>
            </fieldset>
            {!current && <button type="button" className="btn" onClick={start}>Get instructions</button>}
            {current?.instructions && 'type' in current.instructions && (
              <div className="instructions">
                <p>Add this record wherever your domain's DNS is managed:</p>
                <dl>
                  <dt>Type</dt><dd>TXT</dd>
                  <dt>Name</dt><dd><code>{current.instructions.name}</code> <Copy text={current.instructions.name} /></dd>
                  <dt>Value</dt><dd><code>{current.instructions.value}</code> <Copy text={current.instructions.value} /></dd>
                </dl>
              </div>
            )}
            {current?.instructions && 'url' in current.instructions && (
              <div className="instructions">
                <p>Create a plain-text file at this address containing exactly this line. Redirects are not followed.</p>
                <dl>
                  <dt>Address</dt><dd><code>{current.instructions.url}</code> <Copy text={current.instructions.url} /></dd>
                  <dt>Contents</dt><dd><code>{current.instructions.contents}</code> <Copy text={current.instructions.contents} /></dd>
                </dl>
              </div>
            )}
            {current && (
              <>
                <button type="button" className="btn" disabled={checking} onClick={() => check(current.id)}>{checking ? 'Checking…' : 'Check now'}</button>
                {current.last_check_result && <p className="hint" role="status">Last check: {current.last_check_result}</p>}
                <p className="hint">These instructions expire on {new Date(current.challenge_expires_at).toLocaleDateString('en-IN')}.</p>
              </>
            )}
          </>
        )}
        {error && <p className="error" role="alert">{error}</p>}
        <div className="help__actions"><button type="button" className="btn btn--quiet" onClick={onClose}>Close</button></div>
      </div>
    </Modal>
  );
}

// ---------------------------------------------------------------- tickets
export function TicketDialog({ ticketId, role, onClose, onChanged }: {
  ticketId: string | null; role: string; onClose: () => void; onChanged: () => void;
}) {
  const [t, setT] = useState<ApiTicketDetail | null>(null);
  const [targets, setTargets] = useState<ApiTarget[]>([]);
  const [actions, setActions] = useState<ApiAction[]>([]);
  const [form, setForm] = useState({ monitor_target_id: '', action_code: '', description: '', amount_rupees: '' });
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const staff = ['owner', 'admin', 'technician'].includes(role);

  const load = async (id: string) => {
    const d = await api<ApiTicketDetail>('GET', `tickets/${id}`);
    setT(d);
    if (staff) {
      const [w, a] = await Promise.all([api<{ targets: ApiTarget[] }>('GET', `websites/${d.website_id}`), api<{ data: ApiAction[] }>('GET', 'actions')]);
      setTargets(w.targets); setActions(a.data);
      setForm((f) => ({ ...f, monitor_target_id: f.monitor_target_id || w.targets[0]?.id || '', action_code: f.action_code || a.data[0]?.code || '' }));
    }
  };
  useEffect(() => { if (ticketId) { setMsg(null); load(ticketId).catch((e) => setMsg({ kind: 'error', text: errText(e) })); } }, [ticketId]); // eslint-disable-line react-hooks/exhaustive-deps

  const run = (fn: () => Promise<unknown>, ok: string) => fn()
    .then(() => { setMsg({ kind: 'ok', text: ok }); onChanged(); return load(ticketId!); })
    .catch((e) => setMsg({ kind: 'error', text: errText(e) }));

  const custom = actions.find((a) => a.code === form.action_code)?.price_paise === null;
  const q = t?.quote;
  const canQuote = staff && t && ['investigating', 'waiting_for_customer', 'quote_pending'].includes(t.state);

  return (
    <Modal open={ticketId !== null} title={t?.title ?? 'Request'} onClose={onClose}>
      {t && (
        <div className="stack">
          <p className="hint">Stage: {TICKET_LABELS[t.state]}</p>
          {q ? (
            <div className="quote">
              <h3>Quote</h3>
              <dl>
                <dt>Work</dt><dd>{q.action.label}</dd>
                <dt>Address</dt><dd className="host">{q.target.url}</dd>
                <dt>Scope</dt><dd>{q.description}</dd>
                <dt>Price</dt><dd>{q.amount_paise === 0 ? 'No charge' : rupees(q.amount_paise)}</dd>
                <dt>Valid until</dt><dd>{new Date(q.expires_at).toLocaleString('en-IN')}</dd>
              </dl>
              {!q.ownership_verified && <p className="banner">Ownership of {q.target.host} isn't verified yet, so this can't be approved or started.</p>}
              {q.approved
                ? <p className="ok">Approved{q.payment_required ? '. Waiting for payment before work can start.' : '.'}</p>
                : !staff && t.state === 'quote_pending' && q.ownership_verified && (
                  <button type="button" className="btn" onClick={() => void run(
                    () => api('POST', `tickets/${t.id}/approvals`, { quote_id: q.id, fingerprint: q.fingerprint }),
                    q.amount_paise === 0 ? 'Approved.' : 'Approved. Payment comes next.')}>
                    Approve {q.amount_paise === 0 ? 'this work' : rupees(q.amount_paise)}
                  </button>
                )}
            </div>
          ) : <p>No quote yet.</p>}

          {canQuote && (
            <form className="stack" onSubmit={(e) => {
              e.preventDefault();
              void run(() => api('POST', `tickets/${t.id}/quotes`, {
                monitor_target_id: form.monitor_target_id, action_code: form.action_code, description: form.description,
                amount_paise: custom ? Math.round(Number(form.amount_rupees) * 100) : null,
              }), q ? 'New quote issued. The previous quote and any approval were cancelled.' : 'Quote issued.');
            }}>
              <h3>{q ? 'Replace the quote' : 'Issue a quote'}</h3>
              <label className="field">Address
                <select required value={form.monitor_target_id} onChange={(e) => setForm({ ...form, monitor_target_id: e.target.value })}>
                  {targets.map((x) => <option key={x.id} value={x.id}>{x.url}</option>)}
                </select>
              </label>
              <label className="field">Work
                <select required value={form.action_code} onChange={(e) => setForm({ ...form, action_code: e.target.value })}>
                  {actions.map((a) => <option key={a.code} value={a.code}>{a.label}{a.price_paise === null ? '' : `: ${a.price_paise === 0 ? 'no charge' : rupees(a.price_paise)}`}</option>)}
                </select>
              </label>
              {custom && (
                <label className="field">Amount (₹)
                  <input inputMode="decimal" required value={form.amount_rupees} onChange={(e) => setForm({ ...form, amount_rupees: e.target.value })} />
                </label>
              )}
              <label className="field">Exact scope, as the customer will see it
                <textarea required maxLength={1000} rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
              </label>
              <button className="btn">{q ? 'Issue new quote' : 'Issue quote'}</button>
            </form>
          )}
          {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
          <div className="help__actions"><button type="button" className="btn btn--quiet" onClick={onClose}>Close</button></div>
        </div>
      )}
    </Modal>
  );
}

// ------------------------------------------------------ link landing pages
/** /verify-email?token=… and /accept-invite?token=… */
export function LinkLanding({ kind, token, signedIn, onDone }: {
  kind: 'verify' | 'invite'; token: string; signedIn: boolean; onDone: (workspace?: string) => void;
}) {
  const [state, setState] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const ran = useRef(false);

  useEffect(() => {
    if (ran.current || (kind === 'invite' && !signedIn)) return;
    ran.current = true;
    setBusy(true);
    const call = kind === 'verify'
      ? api('POST', 'auth/verify-email', { token }, { workspace: false }).then(() => ({ ok: true, text: 'Email confirmed. Thank you.' }))
      : api<{ workspace_id: string }>('POST', 'invitations/accept', { token }, { workspace: false })
          .then((r) => { onDone(r.workspace_id); return { ok: true, text: 'Invitation accepted.' }; });
    call.then(setState).catch((e) => setState({ ok: false, text: e instanceof ApiError ? e.message : errText(e) })).finally(() => setBusy(false));
  }, [kind, token, signedIn]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="auth">
      <div className="auth__card">
        <span className="logo" aria-hidden="true">U</span>
        <h1>{kind === 'verify' ? 'Confirm your email' : 'Join a workspace'}</h1>
        {kind === 'invite' && !signedIn && <p>Sign in or create an account with the email address this invitation was sent to, then open the link again.</p>}
        {busy && <p role="status">One moment…</p>}
        {state && <p className={state.ok ? 'ok' : 'error'} role={state.ok ? 'status' : 'alert'}>{state.text}</p>}
        <button type="button" className="btn" onClick={() => onDone()}>Continue to Upfixy</button>
      </div>
    </div>
  );
}
