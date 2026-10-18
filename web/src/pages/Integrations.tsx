// SCR-09 Integrations list and SCR-10 connect wizard + import preview (INT-001…006).
import { useEffect, useMemo, useState } from 'react';
import { api, type ApiConnector, type ApiConnectorAsset, type ApiProviderLink, type ConnectorKind } from '../api';
import { useWs } from '../app-context';
import { href, onLinkClick } from '../router';

const KIND: Record<ConnectorKind, { name: string; help: string; token: string }> = {
  cloudflare: { name: 'Cloudflare DNS (read-only)', token: 'API token',
    help: 'Create a Cloudflare API token with Zone:Read and DNS:Read, limited to the zones you want Upfixy to watch. Upfixy only reads.' },
  whm_reseller: { name: 'WHM reseller (read-only)', token: 'WHM API token',
    help: 'Use a reseller API token. Root or root-equivalent tokens are refused. Upfixy checks that the token only sees your own accounts before showing anything.' },
  cpanel: { name: 'cPanel account (read-only)', token: 'cPanel API token',
    help: 'For one hosting account that is not on your WHM. cPanel tokens can usually make changes; Upfixy only calls a fixed list of read functions.' },
};
const STATUS: Record<ApiConnector['status'], string> = {
  verifying: 'Checking access…', active: 'Connected', rejected: 'Rejected', error: 'Provider not reachable', revoked: 'Revoked',
};
const errText = (e: unknown) => (e instanceof Error ? e.message : String(e));
const when = (t: string | null) => (t ? new Date(t).toLocaleString('en-IN') : 'never');

function StatusLine({ c }: { c: ApiConnector }) {
  const tone = c.status === 'active' && !c.stale ? 'ok' : c.status === 'verifying' ? 'unknown' : 'warning';
  return (
    <p className={`status status--${tone}`}>
      <strong>{STATUS[c.status]}</strong>
      {c.status === 'active' && c.stale && ' · not synced recently'}
      {c.status_reason && <span className="hint"> — {c.status_reason}</span>}
    </p>
  );
}

export function Integrations() {
  const [rows, setRows] = useState<ApiConnector[] | null>(null);
  const [kind, setKind] = useState<ConnectorKind>('cloudflare');
  const [form, setForm] = useState({ label: '', host: '', username: '', api_token: '' });
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const load = () => api<{ data: ApiConnector[] }>('GET', 'connectors').then((r) => setRows(r.data)).catch((e) => setMsg({ kind: 'error', text: errText(e) }));
  useEffect(() => { void load(); }, []);
  useEffect(() => { // poll while something is being verified
    if (!rows?.some((r) => r.status === 'verifying')) return;
    const t = window.setInterval(() => void load(), 4000);
    return () => window.clearInterval(t);
  }, [rows]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setMsg(null);
    try {
      const body = kind === 'cloudflare' ? { kind, label: form.label, api_token: form.api_token } : { kind, ...form };
      await api('POST', 'connectors', body);
      setForm({ label: '', host: '', username: '', api_token: '' });
      setMsg({ kind: 'ok', text: 'Saved. Upfixy is checking what this credential can see. Nothing is imported until you choose.' });
      await load();
    } catch (err) { setMsg({ kind: 'error', text: errText(err) }); }
  };

  return (
    <div className="page">
      <h1>Integrations</h1>
      <p className="lede">Read-only connections add private details (DNS records, hosting accounts, certificates) to websites you have verified. Public monitoring never depends on them.</p>
      {rows === null ? <p className="hint" role="status">Loading…</p> : rows.length === 0 ? <p className="empty-inline">No connections yet.</p> : (
        <ul className="rows">
          {rows.map((c) => (
            <li key={c.id} className={`row ${c.revoked_at || c.status === 'rejected' ? 'row--unknown' : c.stale ? 'row--warning' : 'row--ok'}`}>
              <div className="row__main">
                <a href={href({ name: 'integration', id: c.id })} onClick={onLinkClick}>{c.label}</a>
                <span className="hint">{KIND[c.kind].name}{c.host ? ` · ${c.username}@${c.host}` : ''}</span>
                <StatusLine c={c} />
              </div>
              <span className="hint">Last sync {when(c.last_success_at)}</span>
            </li>
          ))}
        </ul>
      )}

      <section className="panel-box" aria-labelledby="connect-h">
        <h2 id="connect-h">Connect a provider</h2>
        <form className="stack" onSubmit={(e) => void submit(e)}>
          <fieldset className="choice">
            <legend>Provider</legend>
            {(Object.keys(KIND) as ConnectorKind[]).map((k) => (
              <label key={k} className="radio"><input type="radio" name="kind" value={k} checked={kind === k} onChange={() => setKind(k)} /> {KIND[k].name}</label>
            ))}
          </fieldset>
          <p className="hint">{KIND[kind].help}</p>
          <label className="field">Name for this connection<input required maxLength={80} value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} /></label>
          {kind !== 'cloudflare' && (<>
            <label className="field">Panel hostname<input required placeholder="server1.example.com" autoComplete="off" value={form.host}
              onChange={(e) => setForm({ ...form, host: e.target.value.trim().toLowerCase() })} /></label>
            <label className="field">{kind === 'whm_reseller' ? 'Reseller username' : 'cPanel username'}<input required autoComplete="off" value={form.username}
              onChange={(e) => setForm({ ...form, username: e.target.value.trim() })} /></label>
          </>)}
          <label className="field">{KIND[kind].token}<input required type="password" autoComplete="off" value={form.api_token}
            onChange={(e) => setForm({ ...form, api_token: e.target.value })} /></label>
          <p className="hint">The token is sealed so only Upfixy's connector service can use it. It is never shown again. You'll be asked for your authentication code.</p>
          <button className="btn">Connect and check access</button>
        </form>
        {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
      </section>
    </div>
  );
}

function factsLine(a: ApiConnectorAsset): string {
  const f = a.facts as Record<string, any>;
  if (a.kind === 'zone') {
    const n = f.records?.count ? Object.values(f.records.count as Record<string, number>).reduce((x, y) => x + y, 0) : null;
    return [f.status && `Zone ${f.status}`, n !== null && `${n} records`, f.dnssec && `DNSSEC ${f.dnssec}`].filter(Boolean).join(' · ');
  }
  if (a.kind === 'account') return [f.suspended ? 'Suspended' : 'Active', f.plan && `plan ${f.plan}`, f.disk_used && `disk ${f.disk_used} of ${f.disk_limit ?? '?'}`].filter(Boolean).join(' · ');
  const ssl = f.ssl as { not_after?: string; self_signed?: boolean } | null;
  if (!f.ssl_known) return 'Certificate: not reported';
  if (!ssl) return 'No certificate reported';
  if (!ssl.not_after) return `Certificate${ssl.self_signed ? ' (self-signed)' : ''}, expiry not reported`;
  const exp = new Date(ssl.not_after);
  return `Certificate${ssl.self_signed ? ' (self-signed)' : ''} ${exp.getTime() < Date.now() ? 'EXPIRED on' : 'until'} ${exp.toLocaleDateString('en-IN')}`;
}

export function Integration({ id }: { id: string }) {
  const { clients } = useWs();
  const [c, setC] = useState<ApiConnector | null>(null);
  const [assets, setAssets] = useState<ApiConnectorAsset[]>([]);
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [client, setClient] = useState('');
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const [token, setToken] = useState('');
  const load = () => api<{ connector: ApiConnector; assets: ApiConnectorAsset[] }>('GET', `connectors/${id}`)
    .then((r) => { setC(r.connector); setAssets(r.assets); }).catch((e) => setMsg({ kind: 'error', text: errText(e) }));
  useEffect(() => { void load(); }, [id]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (c?.status !== 'verifying') return;
    const t = window.setInterval(() => void load(), 4000);
    return () => window.clearInterval(t);
  }, [c?.status]); // eslint-disable-line react-hooks/exhaustive-deps

  // Import candidates: zones (Cloudflare) or domains (WHM/cPanel); accounts are context only.
  const candidates = useMemo(() => assets.filter((a) => a.kind !== 'account' && !a.gone_at), [assets]);
  const accounts = useMemo(() => assets.filter((a) => a.kind === 'account'), [assets]);
  if (!c) return <div className="page">{msg ? <p className="error" role="alert">{msg.text}</p> : <p className="hint" role="status">Loading…</p>}</div>;

  const toggle = (aid: string) => setPicked((p) => { const n = new Set(p); if (n.has(aid)) n.delete(aid); else n.add(aid); return n; });
  const run = (fn: () => Promise<unknown>, ok: string | ((r: any) => string)) => fn()
    .then(async (r) => { setMsg({ kind: 'ok', text: typeof ok === 'string' ? ok : ok(r) }); setPicked(new Set()); await load(); })
    .catch((e) => setMsg({ kind: 'error', text: errText(e) }));
  const live = !c.revoked_at && c.status !== 'rejected';

  return (
    <div className="page">
      <p><a href="/integrations" onClick={onLinkClick}>← Integrations</a></p>
      <h1>{c.label}</h1>
      <p className="hint">{KIND[c.kind].name}{c.host ? ` · ${c.username}@${c.host}` : ''}</p>
      <StatusLine c={c} />
      <dl className="kv">
        <dt>Can read</dt><dd>{c.verified_capabilities.length ? c.verified_capabilities.join(', ') : 'Nothing verified yet'}</dd>
        <dt>Asked for</dt><dd>{c.declared_capabilities.join(', ')}</dd>
        <dt>Last sync</dt><dd>{when(c.last_success_at)}{c.status === 'error' && c.next_sync_at ? ` · next try ${when(c.next_sync_at)}` : ''}</dd>
      </dl>
      {c.warnings.length > 0 && <ul className="notice">{c.warnings.map((w) => <li key={w}>{w}</li>)}</ul>}

      {c.status === 'active' && (
        <section className="panel-box" aria-labelledby="imp-h">
          <h2 id="imp-h">Choose what to add</h2>
          <p className="hint">Nothing is added until you choose. Adding a website starts public monitoring only; private details appear after you verify ownership.</p>
          {accounts.length > 0 && <p className="hint">{accounts.length} hosting account{accounts.length === 1 ? '' : 's'} found.</p>}
          {candidates.length === 0 ? <p className="empty-inline">Nothing found yet.</p> : (
            <form onSubmit={(e) => { e.preventDefault(); void run(() => api('POST', `connectors/${id}/import`, { asset_ids: [...picked], client_id: client || null }),
              (r: { imported: unknown[]; linked: unknown[]; needs_review: Array<{ name: string; reason: string }> }) =>
                `${r.imported.length} added, ${r.linked.length} linked to existing websites${r.needs_review.length ? `, ${r.needs_review.length} need review` : ''}.`); }}>
              <fieldset>
                <legend className="sr-only">Items found by this connection</legend>
                <ul className="rows">
                  {candidates.map((a) => {
                    const done = a.import_state === 'imported';
                    return (
                      <li key={a.id} className={`row ${a.import_state === 'needs_review' ? 'row--warning' : ''}`}>
                        <label className="row__main check">
                          <input type="checkbox" disabled={done} checked={picked.has(a.id)} onChange={() => toggle(a.id)} />
                          <span><span className="host">{a.name}</span>
                            <span className="hint"> {factsLine(a)}</span>
                            {done && <span className="hint"> · added</span>}
                            {a.import_state === 'ignored' && <span className="hint"> · ignored</span>}
                            {a.import_state === 'needs_review' && <span className="hint"> · needs review: {a.review_reason}</span>}
                            {!done && a.match_website && <span className="hint"> · already monitored{a.match_client ? ` for ${a.match_client}` : ''}</span>}
                          </span>
                        </label>
                      </li>
                    );
                  })}
                </ul>
              </fieldset>
              {clients.length > 0 && (
                <label className="field">Add to client
                  <select value={client} onChange={(e) => setClient(e.target.value)}>
                    <option value="">No client (agency's own)</option>
                    {clients.map((cl) => <option key={cl.id} value={cl.id}>{cl.name}</option>)}
                  </select>
                </label>
              )}
              <div className="actions">
                <button className="btn" disabled={picked.size === 0}>Add {picked.size || ''} selected</button>
                <button type="button" className="btn btn--quiet" disabled={picked.size === 0}
                  onClick={() => void run(() => api('POST', `connectors/${id}/ignore`, { asset_ids: [...picked] }), 'Ignored.')}>Ignore selected</button>
              </div>
            </form>
          )}
        </section>
      )}

      {live && (
        <section className="panel-box" aria-labelledby="sec-h">
          <h2 id="sec-h">Credential</h2>
          <form className="stack" onSubmit={(e) => { e.preventDefault(); void run(() => api('POST', `connectors/${id}/rotate`, { api_token: token }), 'New credential saved; checking access again.').then(() => setToken('')); }}>
            <label className="field">Replace token<input type="password" required autoComplete="off" value={token} onChange={(e) => setToken(e.target.value)} /></label>
            <button className="btn btn--quiet">Replace and re-check</button>
          </form>
          <button type="button" className="btn btn--danger" onClick={() => {
            if (window.confirm(`Revoke "${c.label}"? Upfixy destroys the token at once. Details already shown become "stale"; public monitoring continues.`)) {
              void run(() => api('POST', `connectors/${id}/revoke`), 'Revoked. The token has been destroyed.');
            }
          }}>Revoke connection</button>
        </section>
      )}
      {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}
    </div>
  );
}

const PROVIDER: Record<ConnectorKind, string> = { cloudflare: 'Cloudflare DNS', whm_reseller: 'WHM hosting', cpanel: 'cPanel hosting' };

function linkFacts(l: ApiProviderLink): string[] {
  const f = l.facts ?? {};
  if (l.asset_kind === 'zone') {
    const out = [`Zone ${f.status ?? 'unknown'}`];
    if (f.name_servers?.length) out.push(`Name servers: ${f.name_servers.join(', ')}`);
    if (f.records?.apex?.length) out.push(`Main address: ${f.records.apex.map((r: { type: string; content: string; proxied: boolean }) => `${r.type} ${r.content}${r.proxied ? ' (proxied)' : ''}`).join(', ')}`);
    out.push(`DNSSEC: ${f.dnssec ?? 'not reported'}`);
    return out;
  }
  if (l.asset_kind === 'account') {
    return [f.suspended ? `Account suspended${f.suspend_reason ? `: ${f.suspend_reason}` : ''}` : 'Account active',
      ...(f.plan ? [`Plan ${f.plan}`] : []), ...(f.disk_used ? [`Disk ${f.disk_used} of ${f.disk_limit ?? 'unlimited'}`] : [])];
  }
  if (!f.ssl_known) return ['Certificate: not reported by the provider'];
  if (!f.ssl) return ['No certificate installed on the hosting account'];
  if (!f.ssl.not_after) return [`Certificate on the server${f.ssl.self_signed ? ' (self-signed)' : ''}; expiry not reported`];
  const exp = new Date(f.ssl.not_after);
  return [`Certificate on the server${f.ssl.self_signed ? ' (self-signed)' : ''} ${exp.getTime() < Date.now() ? 'expired on' : 'valid until'} ${exp.toLocaleDateString('en-IN')}`];
}

/** AST-004 on SCR-07: which providers this website is linked to, and what they report (with proof). */
export function ProviderPanel({ websiteId, kinds, fallback = null }: { websiteId: string; kinds: ConnectorKind[]; fallback?: React.ReactNode }) {
  const [links, setLinks] = useState<ApiProviderLink[] | null>(null);
  useEffect(() => { api<{ data: ApiProviderLink[] }>('GET', `websites/${websiteId}/providers`).then((r) => setLinks(r.data)).catch(() => setLinks([])); }, [websiteId]);
  const mine = (links ?? []).filter((l) => kinds.includes(l.provider));
  if (links === null) return null;
  if (mine.length === 0) return <>{fallback}</>;
  return (
    <section className="providers" aria-label="Provider details">
      <h2>From your provider connections</h2>
      <ul className="rows">
        {mine.map((l) => (
          <li key={l.asset_id} className={`row ${l.stale ? 'row--unknown' : 'row--ok'}`}>
            <div className="row__main">
              <strong>{PROVIDER[l.provider]}</strong> <span className="host">{l.name}</span>
              {l.stale && <p className="status status--unknown"><strong>Stale</strong> — {l.stale_reason}. Shown as last reported {new Date(l.last_seen_at).toLocaleString('en-IN')}; not current.</p>}
              {l.private_access
                ? <ul className="plain">{linkFacts(l).map((t) => <li key={t}>{t}</li>)}</ul>
                : <p className="hint">Linked. Verify ownership of this website to see what the provider reports.</p>}
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
