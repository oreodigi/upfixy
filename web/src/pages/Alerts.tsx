// ALR-001 in-app inbox and alert preferences.
import { useEffect, useState } from 'react';
import { api, type ApiNotification, type ApiPrefs } from '../api';
import { useWs } from '../app-context';
import { since } from '../components';
import { href, onLinkClick } from '../router';

export default function Alerts({ onRead }: { onRead: () => void }) {
  const { setError } = useWs();
  const [items, setItems] = useState<ApiNotification[] | null>(null);
  const load = () => api<{ data: ApiNotification[] }>('GET', 'notifications').then((r) => setItems(r.data)).catch((e) => setError(e.message));
  useEffect(() => { void load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  async function readAll() {
    await api('POST', 'notifications/read-all').catch((e) => setError(e.message));
    await load(); onRead();
  }
  async function open(n: ApiNotification) {
    if (!n.read_at) { await api('POST', `notifications/${n.id}/read`).catch(() => undefined); onRead(); }
  }

  const unread = items?.filter((n) => !n.read_at).length ?? 0;
  return (
    <div className="page">
      <div className="headline headline--row">
        <h1>Alerts</h1>
        {unread > 0 && <button type="button" className="btn btn--quiet" onClick={() => void readAll()}>Mark all as read</button>}
      </div>
      <p className="hint">Alerts are sent when a problem lasts three checks in a row, and again when two checks in a row pass. Change who gets email in Settings.</p>
      {items === null ? <p className="hint" role="status">Loading…</p> : items.length === 0 ? (
        <p className="empty">No alerts yet.</p>
      ) : (
        <ul className="rows panel-box">
          {items.map((n) => (
            <li key={n.id} className={`row row--${n.kind === 'incident_opened' ? 'critical' : 'ok'} ${n.read_at ? '' : 'row--unread'}`}>
              <div className="row__main">
                <a href={href({ name: 'website', id: n.website_id })} onClick={(e) => { void open(n); onLinkClick(e); }}>
                  {n.kind === 'incident_opened' ? 'Problem' : 'Resolved'}: {n.website}
                </a>
                <span>{n.title}</span>
              </div>
              <span className="hint">{n.read_at ? '' : 'New · '}{since(new Date(n.created_at))}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function AlertPrefs() {
  const { clients, isAgency, setError } = useWs();
  const [p, setP] = useState<ApiPrefs | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => { api<ApiPrefs>('GET', 'notification-preferences').then(setP).catch((e) => setError(e.message)); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  async function save(body: Record<string, unknown>, label: string) {
    try { setP(await api<ApiPrefs>('PUT', 'notification-preferences', body)); setMsg(`Saved: ${label}.`); }
    catch (e) { setError(e instanceof Error ? e.message : String(e)); }
  }
  if (!p) return null;
  const override = (id: string) => p.clients.find((c) => c.client_id === id);

  return (
    <section className="panel-box" aria-labelledby="alerts-h">
      <h2 id="alerts-h">Alerts</h2>
      <p className="hint">For websites you can see. You never receive alerts about other clients' websites.</p>
      <fieldset className="choice">
        <legend>Everywhere in this workspace</legend>
        <label><input type="checkbox" checked={p.workspace.email} onChange={(e) => void save({ email: e.target.checked, in_app: p.workspace.in_app }, 'email alerts')} /> Email</label>
        <label><input type="checkbox" checked={p.workspace.in_app} onChange={(e) => void save({ email: p.workspace.email, in_app: e.target.checked }, 'in-app alerts')} /> In-app inbox</label>
      </fieldset>
      {isAgency && clients.length > 0 && (
        <div className="table-wrap" tabIndex={0} role="region" aria-label="Alerts per client">
          <table className="list">
            <caption className="sr-only">Per-client alert settings</caption>
            <thead><tr><th scope="col">Client</th><th scope="col">Email</th><th scope="col">In-app</th><th scope="col"><span className="sr-only">Reset</span></th></tr></thead>
            <tbody>
              {clients.map((c) => {
                const o = override(c.id);
                const email = o?.email ?? p.workspace.email;
                const inApp = o?.in_app ?? p.workspace.in_app;
                return (
                  <tr key={c.id}>
                    <th scope="row">{c.name}</th>
                    <td><input type="checkbox" aria-label={`Email alerts for ${c.name}`} checked={email} onChange={(e) => void save({ client_id: c.id, email: e.target.checked, in_app: inApp }, c.name)} /></td>
                    <td><input type="checkbox" aria-label={`In-app alerts for ${c.name}`} checked={inApp} onChange={(e) => void save({ client_id: c.id, email, in_app: e.target.checked }, c.name)} /></td>
                    <td>{o && <button type="button" className="link" onClick={() => void save({ client_id: c.id, reset: true }, `${c.name} uses workspace settings`)}>Use workspace setting</button>}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      <p className="hint" role="status">{msg}</p>
    </section>
  );
}
