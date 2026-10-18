// SCR-12 payment step and SCR-15 Billing (SVC-002…005). Razorpay TEST mode only.
import { useEffect, useState } from 'react';
import { api, rupees, type ApiCoverage, type ApiMerchant, type ApiPaymentOrder, type PaymentStatus } from '../api';
import { useWs } from '../app-context';

const errText = (e: unknown) => (e instanceof Error ? e.message : String(e));
const STATUS: Record<PaymentStatus, string> = {
  created: 'Starting payment…', pending: 'Waiting for payment', paid_verified: 'Paid — confirmed by Razorpay', failed: 'Payment failed',
  refunded: 'Refunded', partially_refunded: 'Partly refunded',
};
const day = (d: string) => new Date(d).toLocaleDateString('en-IN');

declare global { interface Window { Razorpay?: new (o: Record<string, unknown>) => { open: () => void; on: (e: string, f: (r: unknown) => void) => void } } }
function loadCheckout(): Promise<void> {
  if (window.Razorpay) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://checkout.razorpay.com/v1/checkout.js';
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Razorpay checkout could not be loaded. Check your connection and try again.'));
    document.head.appendChild(s);
  });
}

/** Payer's payment step inside a request (SCR-12). Work starts only after Razorpay confirms to Upfixy. */
export function PaymentBox({ ticket, approvalId, amount, payer, onChanged }: {
  ticket: string; approvalId: string | null; amount: number; payer: boolean; onChanged: () => void;
}) {
  const [orders, setOrders] = useState<ApiPaymentOrder[]>([]);
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const load = () => api<{ data: ApiPaymentOrder[] }>('GET', `tickets/${ticket}/payments`).then((r) => setOrders(r.data)).catch(() => undefined);
  useEffect(() => { void load(); }, [ticket]); // eslint-disable-line react-hooks/exhaustive-deps
  const latest = orders[0];
  const waiting = latest && ['created', 'pending'].includes(latest.status) && latest.client_reported_at;
  useEffect(() => { // poll for the webhook after the browser said "paid"
    if (!waiting) return;
    const t = window.setInterval(() => void load().then(onChanged), 4000);
    return () => window.clearInterval(t);
  }, [waiting]); // eslint-disable-line react-hooks/exhaustive-deps

  const pay = async () => {
    if (!approvalId) return;
    setBusy(true); setMsg(null);
    try {
      const r = await api<{ order_id: string; mode: string; checkout: Record<string, unknown> }>('POST', `approvals/${approvalId}/checkout`,
        { idempotency_key: `${approvalId}:${crypto.randomUUID()}` });
      await loadCheckout();
      const rzp = new window.Razorpay!({ ...r.checkout, handler: (res: Record<string, string>) => {
        void api('POST', `payments/${r.order_id}/client-confirm`, res).then(() => {
          setMsg({ kind: 'ok', text: 'Thanks. Waiting for Razorpay to confirm the payment to Upfixy; work starts after that.' });
          return load();
        }).catch((e) => setMsg({ kind: 'error', text: errText(e) }));
      }, modal: { ondismiss: () => setBusy(false) } });
      rzp.on('payment.failed', () => setMsg({ kind: 'error', text: 'The payment did not go through. You can try again.' }));
      rzp.open();
    } catch (e) { setMsg({ kind: 'error', text: errText(e) }); }
    setBusy(false);
  };

  return (
    <div className="pay" aria-live="polite">
      {orders.map((o) => (
        <div key={o.id} className={`status status--${o.status === 'paid_verified' ? 'ok' : o.status === 'failed' ? 'warning' : 'unknown'}`}>
          <strong>{STATUS[o.status]}</strong> · {rupees(o.amount_paise)}{o.mode === 'test' && ' · test mode, no real money'}
          {o.status_reason && <span className="hint"> — {o.status_reason}</span>}
          {o.receipt && <p className="hint">Receipt {o.receipt.number}. {o.receipt.tax_note}</p>}
          {o.refunds.map((r) => <p key={r.id} className="hint">Refund {rupees(r.amount_paise)}: {r.status}</p>)}
        </div>
      ))}
      {payer && approvalId && amount > 0 && (!latest || latest.status === 'failed') && (
        <button type="button" className="btn" disabled={busy} onClick={() => void pay()}>Pay {rupees(amount)}</button>
      )}
      {payer && latest && latest.status === 'pending' && !latest.client_reported_at && (
        <button type="button" className="btn btn--quiet" disabled={busy} onClick={() => void pay()}>Continue payment</button>
      )}
      {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
    </div>
  );
}

function MerchantForm({ m, onSaved }: { m: ApiMerchant; onSaved: (m: ApiMerchant) => void }) {
  const [f, setF] = useState({ legal_name: m.merchant?.legal_name ?? '', gstin: m.merchant?.gstin ?? '', razorpay_key_id: m.merchant?.razorpay_key_id ?? '',
    key_secret: '', webhook_secret: '' });
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  return (
    <form className="stack" onSubmit={(e) => {
      e.preventDefault();
      void api<ApiMerchant>('PUT', 'billing/merchant', { ...f, gstin: f.gstin || null, key_secret: f.key_secret || null, webhook_secret: f.webhook_secret || null })
        .then((r) => { onSaved(r); setF({ ...f, key_secret: '', webhook_secret: '' }); setMsg({ kind: 'ok', text: 'Saved. Secrets are stored encrypted and never shown again.' }); })
        .catch((err) => setMsg({ kind: 'error', text: errText(err) }));
    }}>
      <label className="field">Legal name on receipts<input required maxLength={160} value={f.legal_name} onChange={(e) => setF({ ...f, legal_name: e.target.value })} /></label>
      <label className="field">GSTIN (optional)<input maxLength={15} value={f.gstin} onChange={(e) => setF({ ...f, gstin: e.target.value.toUpperCase().trim() })} /></label>
      <label className="field">Razorpay key id (test)<input required placeholder="rzp_test_…" autoComplete="off" value={f.razorpay_key_id}
        onChange={(e) => setF({ ...f, razorpay_key_id: e.target.value.trim() })} /></label>
      <label className="field">Razorpay key secret{m.merchant && ' (leave blank to keep)'}<input type="password" autoComplete="off" required={!m.merchant}
        value={f.key_secret} onChange={(e) => setF({ ...f, key_secret: e.target.value })} /></label>
      <label className="field">Webhook secret{m.merchant && ' (leave blank to keep)'}<input type="password" autoComplete="off" required={!m.merchant}
        value={f.webhook_secret} onChange={(e) => setF({ ...f, webhook_secret: e.target.value })} /></label>
      <p className="hint">In the Razorpay dashboard (test mode), add a webhook to <code className="wrap">{m.webhook_url}</code> for payment.captured, payment.failed, order.paid, refund.processed and refund.failed.</p>
      <button className="btn">Save payment details</button>
      {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
    </form>
  );
}

function Coverage() {
  const { role, clients } = useWs();
  const admin = ['owner', 'admin'].includes(role);
  const [rows, setRows] = useState<ApiCoverage[]>([]);
  const today = new Date().toISOString().slice(0, 10);
  const [f, setF] = useState({ client_id: '', reference: '', scope: '', valid_from: today, valid_until: '' });
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const load = () => api<{ data: ApiCoverage[] }>('GET', 'billing/coverage').then((r) => setRows(r.data)).catch(() => undefined);
  useEffect(() => { void load(); }, []);
  const run = (fn: () => Promise<unknown>, ok: string) => fn().then(() => { setMsg({ kind: 'ok', text: ok }); return load(); })
    .catch((e) => setMsg({ kind: 'error', text: errText(e) }));
  return (
    <section className="panel-box" aria-labelledby="cov-h">
      <h2 id="cov-h">Coverage contracts (free fixes)</h2>
      <p className="hint">A ₹0 provider-covered fix can only be quoted when an active contract covers the website. The quote names the contract.</p>
      {rows.length === 0 ? <p className="empty-inline">No contracts yet.</p> : (
        <ul className="rows">
          {rows.map((c) => (
            <li key={c.id} className={`row ${c.active ? 'row--ok' : 'row--unknown'}`}>
              <div className="row__main">
                <strong>{c.reference}</strong> <span className="hint">{c.website ?? c.client ?? 'Agency\'s own websites'} · {day(c.valid_from)} – {day(c.valid_until)}{c.revoked_at ? ' · revoked' : c.active ? '' : ' · not active'}</span>
                <span className="hint">{c.scope}</span>
              </div>
              {admin && !c.revoked_at && <button type="button" className="link link--danger" onClick={() => {
                if (window.confirm(`Revoke ${c.reference}? Free fixes that rely on it can no longer start.`)) void run(() => api('POST', `billing/coverage/${c.id}/revoke`), 'Revoked.');
              }}>Revoke</button>}
            </li>
          ))}
        </ul>
      )}
      {admin && (
        <form className="stack" onSubmit={(e) => { e.preventDefault(); void run(() => api('POST', 'billing/coverage', { ...f, client_id: f.client_id || null }), 'Contract added.'); }}>
          <h3>Add a contract</h3>
          <label className="field">Client<select value={f.client_id} onChange={(e) => setF({ ...f, client_id: e.target.value })}>
            <option value="">Agency's own websites</option>{clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
          <label className="field">Contract or plan reference<input required maxLength={120} value={f.reference} onChange={(e) => setF({ ...f, reference: e.target.value })} /></label>
          <label className="field">What it covers<textarea required rows={2} maxLength={1000} value={f.scope} onChange={(e) => setF({ ...f, scope: e.target.value })} /></label>
          <div className="split2">
            <label className="field">From<input type="date" required value={f.valid_from} onChange={(e) => setF({ ...f, valid_from: e.target.value })} /></label>
            <label className="field">Until<input type="date" required value={f.valid_until} onChange={(e) => setF({ ...f, valid_until: e.target.value })} /></label>
          </div>
          <button className="btn btn--quiet">Add contract</button>
        </form>
      )}
      {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
    </section>
  );
}

/** SCR-15 Billing (owner, admin, billing). */
export default function Billing() {
  const { role } = useWs();
  const [m, setM] = useState<ApiMerchant | null>(null);
  const [p, setP] = useState<{ orders: Array<{ id: string; title: string; amount_paise: number; status: PaymentStatus; status_reason: string | null; mode: string;
    created_at: string; refunded_paise: number; receipt: string | null }>; alerts: Array<{ id: string; kind: string; created_at: string; detail: Record<string, unknown> }> } | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => {
    api<ApiMerchant>('GET', 'billing/merchant').then(setM).catch((e) => setMsg(errText(e)));
    api<NonNullable<typeof p>>('GET', 'billing/payments').then(setP).catch(() => undefined);
  }, []);
  const refund = (id: string, max: number) => {
    const amt = window.prompt(`Refund how much (₹, up to ${max / 100})?`);
    const reason = amt ? window.prompt('Reason for the refund?') : null;
    if (!amt || !reason) return;
    void api('POST', `payments/${id}/refunds`, { amount_paise: Math.round(Number(amt) * 100), reason })
      .then(() => setMsg('Refund requested. It shows as refunded once Razorpay confirms.')).catch((e) => setMsg(errText(e)));
  };
  return (
    <div className="page">
      <h1>Billing</h1>
      <p className="banner" role="note"><strong>Test mode only.</strong> {m?.live_note ?? 'Live charging is off.'} Receipts are not GST tax invoices until the invoicing entity is decided.</p>
      {msg && <p className="hint" role="status">{msg}</p>}
      <section className="panel-box" aria-labelledby="mer-h">
        <h2 id="mer-h">Payment account</h2>
        {!m ? <p className="hint" role="status">Loading…</p> : role === 'owner' ? <MerchantForm m={m} onSaved={setM} /> : m.merchant
          ? <dl className="kv"><dt>Merchant</dt><dd>{m.merchant.legal_name}</dd><dt>Mode</dt><dd>{m.merchant.mode}</dd><dt>Key</dt><dd>{m.merchant.razorpay_key_id}</dd></dl>
          : <p className="empty-inline">Not set up. The owner adds Razorpay test keys here.</p>}
      </section>
      <Coverage />
      <section className="panel-box" aria-labelledby="pay-h">
        <h2 id="pay-h">Payments</h2>
        {p?.alerts.length ? (
          <div className="notice" role="alert"><h3>Needs attention</h3><ul>{p.alerts.map((a) => <li key={a.id}>{a.kind === 'mismatch' ? 'A payment did not match its order and was not accepted' : 'A payment arrived for an unknown order'} · {new Date(a.created_at).toLocaleString('en-IN')}</li>)}</ul></div>
        ) : null}
        {!p ? <p className="hint" role="status">Loading…</p> : p.orders.length === 0 ? <p className="empty-inline">No payments yet.</p> : (
          <ul className="rows">
            {p.orders.map((o) => (
              <li key={o.id} className={`row ${o.status === 'paid_verified' ? 'row--ok' : o.status === 'failed' ? 'row--warning' : 'row--unknown'}`}>
                <div className="row__main"><strong>{o.title}</strong>
                  <span className="hint">{rupees(o.amount_paise)} · {STATUS[o.status]}{o.receipt ? ` · receipt ${o.receipt}` : ''}{o.mode === 'test' ? ' · test' : ''}</span></div>
                {['owner', 'billing'].includes(role) && ['paid_verified', 'partially_refunded'].includes(o.status) &&
                  <button type="button" className="btn btn--quiet btn--sm" onClick={() => refund(o.id, o.amount_paise - o.refunded_paise)}>Refund</button>}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
