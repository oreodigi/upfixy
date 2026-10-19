// UX-07 Support request (SCR-12/13): one place for the request's whole journey —
// Request → Analysis → Quote → Approval → Payment → Fix → Verification.
// Stage changes, assignment, quotes, approval, payment and repairs all go through the existing
// API; the server decides what is allowed (allowed_next, ownership, payment, repair controls).
// Nothing here runs a change without an explicit, current approval, even when the price is ₹0.
import { useEffect, useRef, useState } from 'react';
import { TICKET_LABELS, type TicketState } from '@upfixy/core';
import {
  BadgeCheck, CalendarClock, Check, ClipboardList, CreditCard, FileSearch, Globe, History, IndianRupee, ListChecks, Search,
  ShieldCheck, Stethoscope, UserRound, Wrench, X,
} from 'lucide-react';
import {
  api, rupees, type ApiAction, type ApiPaymentOrder, type ApiRepairAttempt, type ApiStaff, type ApiTarget, type ApiTicketDetail, type RepairPlan,
} from '../api';
import { href, onLinkClick } from '../router';
import { StatusBadge, type UiStatus } from '../ui/kit';
import { PaymentBox } from './Billing';
import { AttemptProgress, ChangePreview, RepairPanel } from './Repairs';

const errText = (e: unknown) => (e instanceof Error ? e.message : String(e));
const fmtWhen = (iso: string) => new Date(iso).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata' });

/** Workflow stage colour. Deliberately not the health palette: a stage is not a health reading. */
export const STAGE_STATUS: Record<TicketState, UiStatus> = {
  new: 'info', investigating: 'investigating', waiting_for_customer: 'warning', quote_pending: 'warning',
  ready_for_work: 'info', in_progress: 'investigating', verifying: 'investigating', resolved: 'healthy', closed: 'unknown',
};

const STEPS = [
  { key: 'request', label: 'Request', icon: ClipboardList },
  { key: 'analysis', label: 'Analysis', icon: Search },
  { key: 'quote', label: 'Quote', icon: IndianRupee },
  { key: 'approval', label: 'Approval', icon: BadgeCheck },
  { key: 'payment', label: 'Payment', icon: CreditCard },
  { key: 'fix', label: 'Fix', icon: Wrench },
  { key: 'verify', label: 'Verification', icon: ShieldCheck },
] as const;

/** Which step is current, from server facts only (stage, quote, approval, verified payment). */
function currentStep(t: ApiTicketDetail, paid: boolean): number {
  const q = t.quote;
  switch (t.state) {
    case 'new': return 0;
    case 'investigating': case 'waiting_for_customer': return q ? (q.approved ? 4 : 3) : 1;
    case 'quote_pending': return !q ? 2 : !q.approved ? 3 : q.amount_paise > 0 && !paid ? 4 : 5;
    case 'ready_for_work': case 'in_progress': return 5;
    case 'verifying': return 6;
    default: return 7; // resolved / closed: every step behind us
  }
}

function Stepper({ t, paid }: { t: ApiTicketDetail; paid: boolean }) {
  const at = currentStep(t, paid);
  const free = t.quote?.amount_paise === 0;
  return (
    <ol className="ux-journey ux-journey--7" aria-label="Request progress" tabIndex={0}>
      {STEPS.map((s, i) => {
        const Icon = s.icon;
        const label = s.key === 'payment' && free ? 'No charge' : s.label;
        const state = i < at ? 'done' : i === at ? 'now' : '';
        return (
          <li key={s.key} className={state} aria-current={i === at ? 'step' : undefined}>
            <span className="ux-journey__dot" aria-hidden="true">{i < at ? <Check /> : <Icon />}</span>
            <span>{label}<span className="sr-only">{i < at ? ' (done)' : i === at ? ' (current step)' : ' (not yet)'}</span></span>
          </li>
        );
      })}
    </ol>
  );
}

/** Who works on a request. Technicians can only take or drop it themselves (enforced in the database). */
function Assignee({ ticket, role, meId, onDone, onError }: {
  ticket: ApiTicketDetail; role: string; meId: string | null; onDone: (msg: string) => void; onError: (msg: string) => void;
}) {
  const [staff, setStaff] = useState<ApiStaff[]>([]);
  useEffect(() => {
    if (role !== 'technician') api<{ data: ApiStaff[] }>('GET', `tickets/${ticket.id}/assignable`).then((r) => setStaff(r.data)).catch(() => setStaff([]));
  }, [ticket.id, role]);
  const set = (assignee_id: string | null, ok: string) =>
    api('PATCH', `tickets/${ticket.id}/assignee`, { assignee_id, version: ticket.version }).then(() => onDone(ok)).catch((e) => onError(errText(e)));

  if (role === 'technician') {
    const mine = meId !== null && ticket.assignee_id === meId;
    return (
      <div className="ux-tk__assignee">
        <p>{mine ? 'Assigned to you.' : ticket.assignee_id ? `Assigned to ${ticket.assignee_email ?? 'another technician'}.` : 'Nobody is working on this yet.'}</p>
        {!ticket.assignee_id && meId && <button type="button" className="ux-btn ux-btn--ghost ux-btn--sm" onClick={() => void set(meId, 'You took this request.')}>Take it</button>}
        {mine && <button type="button" className="ux-btn ux-btn--ghost ux-btn--sm" onClick={() => void set(null, 'You dropped this request.')}>Drop it</button>}
      </div>
    );
  }
  return (
    <label className="ux-field">
      <span>Technician</span>
      <select value={ticket.assignee_id ?? ''} onChange={(e) => {
        const v = e.target.value || null;
        void set(v, v ? `Assigned to ${staff.find((s) => s.user_id === v)?.email ?? 'them'}.` : 'Unassigned.');
      }}>
        <option value="">Unassigned</option>
        {ticket.assignee_id && !staff.some((s) => s.user_id === ticket.assignee_id) && <option value={ticket.assignee_id}>{ticket.assignee_email ?? 'Current assignee'}</option>}
        {staff.map((s) => <option key={s.user_id} value={s.user_id}>{s.email}{s.user_id === meId ? ' (you)' : ''}</option>)}
      </select>
    </label>
  );
}

function Section({ icon: Icon, title, children, id }: { icon: typeof Globe; title: string; children: React.ReactNode; id: string }) {
  return (
    <section className="ux-tk__sec" aria-labelledby={id}>
      <h3 id={id}><span className="ux-tk__secicon" aria-hidden="true"><Icon /></span>{title}</h3>
      {children}
    </section>
  );
}

export function TicketDialog({ ticketId, role, meId = null, onClose, onChanged }: {
  ticketId: string | null; role: string; meId?: string | null; onClose: () => void; onChanged: () => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const [t, setT] = useState<ApiTicketDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [targets, setTargets] = useState<ApiTarget[]>([]);
  const [actions, setActions] = useState<ApiAction[]>([]);
  const [orders, setOrders] = useState<ApiPaymentOrder[]>([]);
  const [attempts, setAttempts] = useState<ApiRepairAttempt[]>([]);
  const [form, setForm] = useState({ monitor_target_id: '', action_code: '', description: '', amount_rupees: '' });
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const staff = ['owner', 'admin', 'technician'].includes(role);

  useEffect(() => {
    if (ticketId && !ref.current?.open) ref.current?.showModal();
    if (!ticketId && ref.current?.open) ref.current.close();
  }, [ticketId]);

  const load = async (id: string) => {
    const d = await api<ApiTicketDetail>('GET', `tickets/${id}`);
    setT(d);
    const [p, r] = await Promise.all([
      api<{ data: ApiPaymentOrder[] }>('GET', `tickets/${id}/payments`).then((x) => x.data).catch(() => []),
      api<{ attempts: ApiRepairAttempt[] }>('GET', `tickets/${id}/repairs`).then((x) => x.attempts).catch(() => []),
    ]);
    setOrders(p);
    if (!staff) setAttempts(await Promise.all(r.map((a) => api<ApiRepairAttempt>('GET', `repair-attempts/${a.id}`).catch(() => a))));
    if (staff) {
      const [w, a] = await Promise.all([api<{ targets: ApiTarget[] }>('GET', `websites/${d.website_id}`), api<{ data: ApiAction[] }>('GET', 'actions')]);
      setTargets(w.targets); setActions(a.data);
      setForm((f) => ({ ...f, monitor_target_id: f.monitor_target_id || w.targets[0]?.id || '', action_code: f.action_code || a.data[0]?.code || '' }));
    }
  };
  useEffect(() => {
    if (!ticketId) { setT(null); return; }
    setMsg(null); setLoadError(null); setT(null);
    load(ticketId).catch((e) => setLoadError(errText(e)));
  }, [ticketId]); // eslint-disable-line react-hooks/exhaustive-deps

  const run = (fn: () => Promise<unknown>, ok: string) => fn()
    .then(() => { setMsg({ kind: 'ok', text: ok }); onChanged(); return load(ticketId!); })
    .catch((e) => setMsg({ kind: 'error', text: errText(e) }));
  const refresh = () => { onChanged(); void load(ticketId!).catch(() => undefined); };

  const custom = actions.find((a) => a.code === form.action_code)?.price_paise === null;
  const q = t?.quote;
  const paid = orders.some((o) => o.status === 'paid_verified');
  const canQuote = staff && t && ['investigating', 'waiting_for_customer', 'quote_pending'].includes(t.state);
  const events = [...(t?.events ?? [])].reverse();

  return (
    <dialog ref={ref} className="ux-tk" onClose={onClose} aria-labelledby="tk-title">
      <header className="ux-tk__head">
        <div className="ux-tk__headtext">
          <p className="ux-eyebrow">Support request{t?.website ? <> · <span className="host">{t.website}</span></> : null}</p>
          <h2 id="tk-title">{t?.title ?? (loadError ? 'Request unavailable' : 'Loading request…')}</h2>
          {t && (
            <p className="ux-tk__meta">
              <StatusBadge status={STAGE_STATUS[t.state]} label={TICKET_LABELS[t.state]} />
              {t.created_at && <span><CalendarClock aria-hidden="true" /> Opened {fmtWhen(t.created_at)}</span>}
              <span><UserRound aria-hidden="true" /> {t.assignee_id ? (t.assignee_id === meId ? 'Assigned to you' : `Assigned to ${t.assignee_email ?? 'a technician'}`) : 'Unassigned'}</span>
            </p>
          )}
        </div>
        <button type="button" className="ux-iconbtn" onClick={onClose} aria-label="Close request"><X /></button>
      </header>

      {loadError && <p className="error" role="alert">{loadError}</p>}
      {!t && !loadError && <p className="hint" role="status">Loading…</p>}
      {t && (
        <>
          <Stepper t={t} paid={paid} />
          {msg && <p className={`ux-tk__msg ux-tk__msg--${msg.kind}`} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
          <div className="ux-tk__grid">
            <div className="ux-tk__main">
              <Section icon={Stethoscope} title="The problem" id="tk-problem">
                <p>{t.title}{t.website ? <> on <span className="host">{t.website}</span></> : null}.</p>
                <div className="ux-tk__links">
                  {t.diagnostic_run_id
                    ? <a className="ux-btn ux-btn--ghost ux-btn--sm" href={href({ name: 'diagnostic', id: t.diagnostic_run_id })} onClick={(e) => { onClose(); onLinkClick(e); }}><FileSearch aria-hidden="true" /> Open the diagnosis</a>
                    : <span className="hint">No diagnosis is linked to this request.</span>}
                  <a className="ux-btn ux-btn--ghost ux-btn--sm" href={href({ name: 'website', id: t.website_id })} onClick={(e) => { onClose(); onLinkClick(e); }}><Globe aria-hidden="true" /> Website status</a>
                </div>
              </Section>

              <Section icon={IndianRupee} title="Quote" id="tk-quote">
                {q ? (
                  <div className="ux-tk__quote">
                    <div className="ux-tk__price">
                      <span className="ux-tk__amount">{q.amount_paise === 0 ? '₹0' : rupees(q.amount_paise)}</span>
                      <span className="hint">{q.amount_paise === 0 ? 'No charge — still needs your approval' : 'Fixed price for the scope below'}</span>
                    </div>
                    <dl className="ux-dl">
                      <dt>Work</dt><dd>{q.action.label}</dd>
                      <dt>Address</dt><dd className="host">{q.target.url}</dd>
                      <dt>Scope</dt><dd>{q.description}</dd>
                      <dt>Valid until</dt><dd>{fmtWhen(q.expires_at)}</dd>
                    </dl>
                    {q.params?.operation && <><h4>Exact change</h4><ChangePreview plan={q.params as unknown as RepairPlan} /></>}
                  </div>
                ) : <p className="hint">No quote yet. {staff ? 'Issue one below once the cause is clear.' : 'A technician will send one if paid work is needed; you decide before anything changes.'}</p>}
              </Section>

              {q && (
                <Section icon={BadgeCheck} title="Approval and payment" id="tk-approve">
                  {!q.ownership_verified && <p className="ux-tk__note ux-tk__note--warn">Ownership of <span className="host">{q.target.host}</span> isn't verified yet, so this can't be approved or started.</p>}
                  {q.approved
                    ? <p className="ux-tk__note ux-tk__note--ok"><Check aria-hidden="true" /> Approved{q.amount_paise > 0 ? (paid ? ' and paid.' : '. Work starts after the payment is confirmed.') : ' (no charge).'}</p>
                    : !staff && t.state === 'quote_pending' && q.ownership_verified ? (
                      <div className="ux-tk__approve">
                        <p>Approving lets Upfixy make exactly the change described above{q.amount_paise > 0 ? ', after payment' : ''}. Nothing else changes.</p>
                        <button type="button" className="ux-btn ux-btn--primary" onClick={() => void run(
                          () => api('POST', `tickets/${t.id}/approvals`, { quote_id: q.id, fingerprint: q.fingerprint }),
                          q.amount_paise === 0 ? 'Approved.' : 'Approved. Payment comes next.')}>
                          <BadgeCheck aria-hidden="true" /> Approve {q.amount_paise === 0 ? 'this work (₹0)' : rupees(q.amount_paise)}
                        </button>
                      </div>
                    ) : <p className="hint">Waiting for the customer to approve this quote.</p>}
                  {q.approved && q.amount_paise > 0 && <PaymentBox ticket={t.id} approvalId={q.approval_id ?? null} amount={q.amount_paise} payer={!staff} onChanged={refresh} />}
                </Section>
              )}

              {staff ? (
                <RepairPanel ticket={t.id} websiteId={t.website_id} approvalId={q?.approved ? (q.approval_id ?? null) : null} state={t.state} onChanged={refresh} />
              ) : attempts.length > 0 && (
                <Section icon={Wrench} title="Repair progress" id="tk-repair">
                  {attempts.map((a) => <AttemptProgress key={a.id} a={a} />)}
                </Section>
              )}

              {canQuote && (
                <Section icon={ListChecks} title={q ? 'Replace the quote' : 'Issue a quote'} id="tk-form">
                  <form className="ux-form" onSubmit={(e) => {
                    e.preventDefault();
                    void run(() => api('POST', `tickets/${t.id}/quotes`, {
                      monitor_target_id: form.monitor_target_id, action_code: form.action_code, description: form.description,
                      amount_paise: custom ? Math.round(Number(form.amount_rupees) * 100) : null,
                    }), q ? 'New quote issued. The previous quote and any approval were cancelled.' : 'Quote issued.');
                  }}>
                    <div className="ux-form__row">
                      <label className="ux-field"><span>Address</span>
                        <select required value={form.monitor_target_id} onChange={(e) => setForm({ ...form, monitor_target_id: e.target.value })}>
                          {targets.map((x) => <option key={x.id} value={x.id}>{x.url}</option>)}
                        </select>
                      </label>
                      <label className="ux-field"><span>Work</span>
                        <select required value={form.action_code} onChange={(e) => setForm({ ...form, action_code: e.target.value })}>
                          {actions.map((a) => <option key={a.code} value={a.code}>{a.label}{a.price_paise === null ? '' : `: ${a.price_paise === 0 ? 'no charge' : rupees(a.price_paise)}`}</option>)}
                        </select>
                      </label>
                    </div>
                    {custom && (
                      <label className="ux-field"><span>Amount (₹)</span>
                        <input inputMode="decimal" required value={form.amount_rupees} onChange={(e) => setForm({ ...form, amount_rupees: e.target.value })} />
                      </label>
                    )}
                    <label className="ux-field"><span>Exact scope, as the customer will see it</span>
                      <textarea required maxLength={1000} rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    </label>
                    <div><button className="ux-btn ux-btn--primary">{q ? 'Issue new quote' : 'Issue quote'}</button></div>
                  </form>
                </Section>
              )}
            </div>

            <aside className="ux-tk__rail" aria-label="Request details">
              {staff && (
                <Section icon={UserRound} title="Assignment" id="tk-assign">
                  <Assignee ticket={t} role={role} meId={meId} onDone={(text) => { setMsg({ kind: 'ok', text }); refresh(); }} onError={(text) => setMsg({ kind: 'error', text })} />
                </Section>
              )}
              {staff && t.allowed_next.length > 0 && (
                <Section icon={ClipboardList} title="Stage" id="tk-stage">
                  <p className="hint">Moves from this stage. Starting work needs a current approval (and a confirmed payment when there is a charge); resolving needs a passed check.</p>
                  <div className="ux-tk__moves">
                    {t.allowed_next.map((to) => {
                      const blocked = to === 'ready_for_work' && !(q?.approved && (q.amount_paise === 0 || paid));
                      return (
                        <button key={to} type="button" className="ux-btn ux-btn--ghost ux-btn--sm" disabled={blocked}
                          title={blocked ? (q?.approved ? 'Waiting for the payment to be confirmed' : 'Needs the customer’s approval first') : undefined}
                          onClick={() => void run(() => api('POST', `tickets/${t.id}/transition`, { to, version: t.version }), `Moved to ${TICKET_LABELS[to]}.`)}>
                          Move to {TICKET_LABELS[to]}{blocked && <span className="ux-tk__why"> · {q?.approved ? 'awaiting payment' : 'needs approval'}</span>}
                        </button>
                      );
                    })}
                  </div>
                </Section>
              )}
              <Section icon={History} title="Timeline" id="tk-time">
                {events.length === 0 && !t.created_at ? <p className="hint">No changes recorded yet.</p> : (
                  <ol className="ux-tl">
                    {events.map((e, i) => (
                      <li key={i}>
                        <span className="ux-tl__dot" aria-hidden="true" />
                        <div>
                          <strong>{e.from_state ? `${TICKET_LABELS[e.from_state]} → ${TICKET_LABELS[e.to_state]}` : `Opened as ${TICKET_LABELS[e.to_state]}`}</strong>
                          {e.reason && <p>{e.reason}</p>}
                          <time dateTime={e.created_at}>{fmtWhen(e.created_at)}{e.actor_id && e.actor_id === meId ? ' · you' : ''}</time>
                        </div>
                      </li>
                    ))}
                    {t.created_at && !events.some((e) => e.from_state === null) && (
                      <li>
                        <span className="ux-tl__dot" aria-hidden="true" />
                        <div><strong>Request opened</strong><time dateTime={t.created_at}>{fmtWhen(t.created_at)}</time></div>
                      </li>
                    )}
                  </ol>
                )}
              </Section>
            </aside>
          </div>
        </>
      )}
    </dialog>
  );
}
