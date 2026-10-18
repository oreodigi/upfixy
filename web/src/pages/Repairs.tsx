// FIX-003..005 UI: DNS connection (SCR-09/10), exact-change preview (SCR-12), attempt progress (SCR-13), controls.
import { useEffect, useState } from 'react';
import { api, type ApiDnsConnection, type ApiRepairAttempt, type ApiRepairControls, type ApiRepairDraft, type RepairPlan, type RepairState } from '../api';
import { useWs } from '../app-context';

const errText = (e: unknown) => (e instanceof Error ? e.message : String(e));

/** The exact change the payer approves. Every field is shown; nothing is summarised away. */
export function ChangePreview({ plan }: { plan: RepairPlan }) {
  return (
    <div className="change" role="group" aria-label="Exact DNS change">
      <dl>
        <dt>Zone</dt><dd className="host">{plan.zone_name}</dd>
        <dt>Record</dt><dd><span className="host">{plan.record_name}</span> ({plan.record_type})</dd>
        <dt>Now</dt><dd><code>{plan.before.content}</code></dd>
        <dt>After</dt><dd><code>{plan.after.content}</code></dd>
        <dt>TTL / proxy</dt><dd>{plan.before.ttl === 1 ? 'Automatic' : `${plan.before.ttl} s`} / {plan.before.proxied ? 'on' : 'off'} (unchanged)</dd>
        <dt>Checked after</dt><dd>Public DNS shows the new address, then the website check runs.</dd>
        <dt>If it goes wrong</dt><dd>{plan.rollback}</dd>
      </dl>
      <p className="hint">Changes can take a few minutes to be visible everywhere. Until the checks pass, the request stays open.</p>
    </div>
  );
}

const STEPS: Array<[RepairState | 'approved', string]> = [
  ['approved', 'Approved and paid'], ['preflight', 'Checking the record'], ['executing', 'Changing the record'], ['verifying', 'Verifying'],
];
const END: Partial<Record<RepairState, string>> = { resolved: 'Resolved', rolled_back: 'Rolled back', needs_technician: 'Needs a technician', refused: 'Not run' };

function AttemptProgress({ a }: { a: ApiRepairAttempt }) {
  const order: Array<RepairState | 'approved'> = ['approved', 'preflight', 'executing', 'verifying'];
  const at = END[a.state] ? 4 : Math.max(0, order.indexOf(a.state === 'queued' ? 'approved' : a.state));
  return (
    <div>
      <ol className="steps" aria-label="Automated fix progress">
        {STEPS.map(([s, label], i) => <li key={s} className={i < at ? 'done' : i === at ? 'now' : ''} aria-current={i === at ? 'step' : undefined}>{label}</li>)}
        <li className={END[a.state] ? `done end end--${a.state}` : ''}>{END[a.state] ?? 'Resolved or escalated'}</li>
      </ol>
      {a.customer_message && <p className={a.state === 'resolved' ? 'ok' : 'notice'} role="status">{a.customer_message}</p>}
      {a.state_reason && <p className="hint">Reason: {a.state_reason}</p>}
      {a.events && a.events.length > 0 && (
        <details className="evidence"><summary>Every step ({a.events.length})</summary>
          <ol className="plain">{a.events.map((e, i) => <li key={i}><code>{e.kind}</code> {new Date(e.at).toLocaleTimeString('en-IN')}{e.detail?.ref ? ` · provider ref ${String(e.detail.ref)}` : ''}</li>)}</ol>
        </details>
      )}
    </div>
  );
}

/** Staff panel inside a request (SCR-13). */
export function RepairPanel({ ticket, websiteId, approvalId, state, onChanged }: {
  ticket: string; websiteId: string; approvalId: string | null; state: string; onChanged: () => void;
}) {
  const [conns, setConns] = useState<ApiDnsConnection[]>([]);
  const [targets, setTargets] = useState<Array<{ id: string; url: string }>>([]);
  const [drafts, setDrafts] = useState<ApiRepairDraft[]>([]);
  const [attempts, setAttempts] = useState<ApiRepairAttempt[]>([]);
  const [form, setForm] = useState({ connection_id: '', monitor_target_id: '', record_id: '', proposed_content: '' });
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);

  const load = async () => {
    const [c, w, r] = await Promise.all([
      api<{ data: ApiDnsConnection[] }>('GET', `websites/${websiteId}/dns-connections`),
      api<{ targets: Array<{ id: string; url: string }> }>('GET', `websites/${websiteId}`),
      api<{ drafts: ApiRepairDraft[]; attempts: ApiRepairAttempt[] }>('GET', `tickets/${ticket}/repairs`),
    ]);
    const live = c.data.filter((x) => !x.revoked_at);
    setConns(live); setTargets(w.targets); setDrafts(r.drafts);
    setAttempts(await Promise.all(r.attempts.map((a) => api<ApiRepairAttempt>('GET', `repair-attempts/${a.id}`))));
    setForm((f) => ({ ...f, connection_id: f.connection_id || live[0]?.id || '', monitor_target_id: f.monitor_target_id || w.targets[0]?.id || '' }));
  };
  useEffect(() => { void load().catch((e) => setMsg({ kind: 'error', text: errText(e) })); }, [ticket]); // eslint-disable-line react-hooks/exhaustive-deps
  // Poll while the executor is working on something.
  const busy = drafts.some((d) => d.state === 'reading') || attempts.some((a) => ['queued', 'preflight', 'executing', 'verifying'].includes(a.state));
  useEffect(() => {
    if (!busy) return;
    const t = window.setInterval(() => { void load().catch(() => undefined); }, 4000);
    return () => window.clearInterval(t);
  }, [busy]); // eslint-disable-line react-hooks/exhaustive-deps

  const run = (fn: () => Promise<unknown>, ok: string) => fn().then(async () => { setMsg({ kind: 'ok', text: ok }); await load(); onChanged(); })
    .catch((e) => setMsg({ kind: 'error', text: errText(e) }));
  const latest = drafts[0];
  const canQuote = latest?.state === 'ready' && ['investigating', 'waiting_for_customer', 'quote_pending'].includes(state);

  if (conns.length === 0) {
    return <section className="panel-box"><h3>Automated DNS fix</h3><p className="hint">Connect the website's Cloudflare zone (Domain tab) to offer a one-record automated fix.</p></section>;
  }
  return (
    <section className="panel-box repair" aria-labelledby="repair-h">
      <h3 id="repair-h">Automated DNS fix</h3>
      <p className="hint">One A/AAAA record, previewed from the live zone, approved and paid by the customer, then applied, verified and undone if it breaks the site.</p>
      {attempts.map((a) => <AttemptProgress key={a.id} a={a} />)}
      {latest && (
        <div>
          {latest.state === 'reading' && <p role="status">Reading the record from Cloudflare…</p>}
          {latest.state === 'refused' && <p className="error" role="alert">This change can't be automated: {latest.refusal}</p>}
          {latest.state === 'ready' && latest.plan && <ChangePreview plan={latest.plan} />}
        </div>
      )}
      {canQuote && <button type="button" className="btn" onClick={() => void run(() => api('POST', `repair-drafts/${latest.id}/quote`), 'Quote sent for approval.')}>Send this change as a quote</button>}
      {approvalId && state === 'ready_for_work' && !attempts.some((a) => a.state !== 'refused') && (
        <button type="button" className="btn" onClick={() => void run(() => api('POST', `approvals/${approvalId}/repairs`, { idempotency_key: crypto.randomUUID() }), 'Automated fix queued.')}>
          Run the approved automated fix
        </button>
      )}
      {['investigating', 'waiting_for_customer'].includes(state) && (
        <form className="stack" onSubmit={(e) => { e.preventDefault(); void run(() => api('POST', `tickets/${ticket}/repair-drafts`, form), 'Preview requested.'); }}>
          <h4>Propose a change</h4>
          <label className="field">Zone<select value={form.connection_id} onChange={(e) => setForm({ ...form, connection_id: e.target.value })}>
            {conns.map((c) => <option key={c.id} value={c.id}>{c.zone_name}</option>)}</select></label>
          <label className="field">Address to check afterwards<select value={form.monitor_target_id} onChange={(e) => setForm({ ...form, monitor_target_id: e.target.value })}>
            {targets.map((t) => <option key={t.id} value={t.id}>{t.url}</option>)}</select></label>
          <label className="field">Cloudflare record ID<input required pattern="[0-9a-f]{32}" value={form.record_id} onChange={(e) => setForm({ ...form, record_id: e.target.value.trim() })} /></label>
          <label className="field">New address (IPv4 or IPv6)<input required inputMode="decimal" value={form.proposed_content} onChange={(e) => setForm({ ...form, proposed_content: e.target.value.trim() })} /></label>
          <button className="btn btn--quiet">Preview from the live zone</button>
        </form>
      )}
      {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
    </section>
  );
}

/** SCR-09/10 minimal: scoped DNS-edit connection for one website. Owner/admin, step-up MFA. */
export function DnsConnections({ websiteId }: { websiteId: string }) {
  const { role } = useWs();
  const [rows, setRows] = useState<ApiDnsConnection[]>([]);
  const [form, setForm] = useState({ zone_id: '', zone_name: '', api_token: '' });
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const admin = ['owner', 'admin'].includes(role);
  const load = () => api<{ data: ApiDnsConnection[] }>('GET', `websites/${websiteId}/dns-connections`).then((r) => setRows(r.data)).catch(() => undefined);
  useEffect(() => { void load(); }, [websiteId]); // eslint-disable-line react-hooks/exhaustive-deps
  const run = (fn: () => Promise<unknown>, ok: string) => fn().then(() => { setMsg({ kind: 'ok', text: ok }); return load(); })
    .catch((e) => setMsg({ kind: 'error', text: errText(e) }));

  return (
    <section className="panel-box" aria-labelledby="dnsc-h">
      <h2 id="dnsc-h">DNS provider connection (for automated fixes)</h2>
      <p className="hint">Use a Cloudflare API token limited to this one zone with DNS edit permission. The token is sealed so only the repair service can use it; Upfixy never shows it again. Connecting never changes anything by itself.</p>
      {rows.length > 0 && (
        <ul className="rows">
          {rows.map((c) => (
            <li key={c.id} className={`row ${c.revoked_at ? 'row--unknown' : ''}`}>
              <div className="row__main"><span className="host">{c.zone_name}</span>
                <span className="hint">{c.revoked_at ? 'Revoked' : c.verified_at ? `Token checked ${new Date(c.verified_at).toLocaleString('en-IN')}: ${c.verify_result}` : 'Not checked yet (checked before first use)'} · version {c.secret_version}</span></div>
              {admin && !c.revoked_at && <button type="button" className="link link--danger" onClick={() => {
                if (window.confirm(`Revoke the connection to ${c.zone_name}? Queued automated fixes for it will stop.`)) void run(() => api('POST', `dns-connections/${c.id}/revoke`), 'Connection revoked.');
              }}>Revoke</button>}
            </li>
          ))}
        </ul>
      )}
      {admin && (
        <form className="stack" onSubmit={(e) => { e.preventDefault(); void run(() => api('POST', `websites/${websiteId}/dns-connections`, form), 'Connected. The token is stored sealed.').then(() => setForm({ zone_id: '', zone_name: '', api_token: '' })); }}>
          <label className="field">Zone ID<input required pattern="[0-9a-f]{32}" value={form.zone_id} onChange={(e) => setForm({ ...form, zone_id: e.target.value.trim() })} /></label>
          <label className="field">Zone name<input required placeholder="example.com" value={form.zone_name} onChange={(e) => setForm({ ...form, zone_name: e.target.value.trim() })} /></label>
          <label className="field">API token<input required type="password" autoComplete="off" value={form.api_token} onChange={(e) => setForm({ ...form, api_token: e.target.value })} /></label>
          <button className="btn">Connect zone</button>
        </form>
      )}
      {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
    </section>
  );
}

/** FIX-005 workspace kill switch (Settings). */
export function RepairControls() {
  const { role } = useWs();
  const [c, setC] = useState<ApiRepairControls | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => { api<ApiRepairControls>('GET', 'repair-controls').then(setC).catch(() => undefined); }, []);
  if (!c || !['owner', 'admin'].includes(role)) return null;
  const set = async (paused: boolean) => {
    const reason = window.prompt(paused ? 'Why pause automated fixes?' : 'Why resume automated fixes?');
    if (!reason || reason.trim().length < 3) return;
    try { setC(await api<ApiRepairControls>('POST', 'repair-controls', { paused, reason })); setMsg(paused ? 'Automated fixes paused.' : 'Automated fixes resumed for this workspace.'); }
    catch (e) { setMsg(errText(e)); }
  };
  const off = !c.platform_enabled || c.global.paused;
  return (
    <section className="panel-box" aria-labelledby="rc-h">
      <h2 id="rc-h">Automated fixes</h2>
      <p>{off ? 'Off for the whole platform until a release is approved.' : c.workspace.paused ? `Paused for this workspace: ${c.workspace.reason}` : 'Allowed for approved, paid DNS fixes.'}</p>
      <p className="hint">Pausing stops every queued and new automated change at once, even approved ones. Monitoring keeps running.</p>
      {c.workspace.paused
        ? role === 'owner' && <button type="button" className="btn btn--quiet" onClick={() => void set(false)}>Resume (owner, needs your code)</button>
        : <button type="button" className="btn btn--danger" onClick={() => void set(true)}>Pause automated fixes now</button>}
      {msg && <p className="hint" role="status">{msg}</p>}
    </section>
  );
}
