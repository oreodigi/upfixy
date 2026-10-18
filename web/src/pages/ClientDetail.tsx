// SCR-06 Client detail: the client's websites, who works on them, and the
// contract settings (payment authority, self-service).
import { useEffect, useState } from 'react';
import { api, ApiError, type ApiClientDetail, type ApiMember } from '../api';
import { useWs } from '../app-context';
import { HealthBoard, SiteCard, StatusPill } from '../components';
import { toSite } from '../live-mapping';
import { onLinkClick } from '../router';

export default function ClientDetail({ id }: { id: string }) {
  const { role, isStaff, openHelp, report, reload, clients } = useWs();
  const [c, setC] = useState<ApiClientDetail | null>(null);
  const [missing, setMissing] = useState(false);
  const [members, setMembers] = useState<ApiMember[]>([]);
  const [pick, setPick] = useState('');
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
  const admin = ['owner', 'admin'].includes(role);

  const load = () => api<ApiClientDetail>('GET', `clients/${id}`).then(setC)
    .catch((e) => { if (e instanceof ApiError && e.status === 404) setMissing(true); });
  useEffect(() => { setMissing(false); void load(); }, [id, clients]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (admin) api<{ data: ApiMember[] }>('GET', 'members').then((r) => setMembers(r.data)).catch(() => undefined); }, [admin]);

  const run = (fn: () => Promise<unknown>, ok: string) => fn()
    .then(async () => { setMsg({ kind: 'ok', text: ok }); await load(); await reload(); })
    .catch((e) => setMsg({ kind: 'error', text: e instanceof Error ? e.message : String(e) }));

  if (missing) return <div className="page"><h1>Client not found</h1><p>It may have been removed, or you no longer have access. <a href="/clients" onClick={onLinkClick}>Back to clients</a></p></div>;
  if (!c) return <p className="hint" role="status">Loading…</p>;

  const sites = c.websites.map(toSite);
  const assignedIds = new Set(c.staff.map((s) => s.user_id));
  const candidates = members.filter((m) => m.role === 'technician' && !m.suspended_at && !assignedIds.has(m.user_id));

  return (
    <div className="page">
      <nav className="crumbs" aria-label="Breadcrumb"><a href="/clients" onClick={onLinkClick}>Clients</a><span aria-hidden="true"> / </span></nav>
      <div className="headline">
        <h1>{c.name}</h1>
        <p className="headline__sub"><StatusPill lane={c.lane} /> {c.website_count} website{c.website_count === 1 ? '' : 's'}
          {c.impacted_count ? `, ${c.impacted_count} with problems` : ''}{c.open_requests ? `, ${c.open_requests} open request${c.open_requests === 1 ? '' : 's'}` : ''}</p>
      </div>

      {msg && <p className={msg.kind} role={msg.kind === 'error' ? 'alert' : 'status'}>{msg.text}</p>}

      <h2>Websites</h2>
      {sites.length === 0 ? <p className="empty-inline">No websites for this client yet. Add one from Websites and choose this client.</p>
        : <HealthBoard items={sites} noun="Websites" render={(s) => (
            <SiteCard site={s} onHelp={openHelp} actions={role !== 'billing' && <button type="button" className="link" onClick={() => report(s.id, s.host)}>Report a problem</button>} />
          )} />}

      {isStaff && (
        <div className="split">
          <section className="panel-box" aria-labelledby="staff-h">
            <h2 id="staff-h">Technicians</h2>
            <p className="hint">Only technicians assigned here see this client and can take its requests.</p>
            {c.staff.length === 0 ? <p className="empty-inline">No technician assigned.</p> : (
              <ul className="rows">
                {c.staff.map((s) => (
                  <li key={s.user_id} className="row">
                    <div className="row__main"><span>{s.email ?? 'Technician'}</span></div>
                    {admin && <button type="button" className="link" onClick={() => {
                      if (window.confirm(`Remove ${s.email ?? 'this technician'} from ${c.name}? Their open requests for this client become unassigned.`)) {
                        void run(() => api('DELETE', `clients/${id}/assignments/${s.user_id}`), 'Removed. Their open requests are unassigned.');
                      }
                    }}>Remove</button>}
                  </li>
                ))}
              </ul>
            )}
            {admin && candidates.length > 0 && (
              <form className="inline-form" onSubmit={(e) => { e.preventDefault(); if (pick) void run(() => api('POST', `clients/${id}/assignments`, { user_id: pick }), 'Technician assigned.').then(() => setPick('')); }}>
                <label className="field">Assign a technician
                  <select value={pick} onChange={(e) => setPick(e.target.value)} required>
                    <option value="">Choose…</option>
                    {candidates.map((m) => <option key={m.user_id} value={m.user_id}>{m.email}</option>)}
                  </select>
                </label>
                <button className="btn btn--sm">Assign</button>
              </form>
            )}
          </section>

          <section className="panel-box" aria-labelledby="cu-h">
            <h2 id="cu-h">Client users</h2>
            {c.client_users.length === 0 ? <p className="empty-inline">Nobody from {c.name} has access yet. Invite them from Team.</p> : (
              <ul className="rows">
                {c.client_users.map((u) => (
                  <li key={u.user_id} className="row">
                    <div className="row__main"><span>{u.email ?? 'Client user'}</span></div>
                    <span className="hint">{u.role === 'client_owner' ? 'Client owner' : 'Client staff'}</span>
                  </li>
                ))}
              </ul>
            )}
            <h2>Settings</h2>
            <dl className="settings">
              <div>
                <dt>Who pays for paid fixes</dt>
                <dd>{c.agency_may_pay ? 'Your agency may approve and pay' : 'The client approves and pays'}
                  {role === 'owner' && (
                    <button type="button" className="link" onClick={() => {
                      const next = !c.agency_may_pay;
                      if (window.confirm(next
                        ? `Let your agency approve and pay for fixes on ${c.name}'s websites? You'll confirm with your authenticator code.`
                        : `Require ${c.name} to approve and pay for fixes themselves?`)) {
                        void run(() => api('PATCH', `clients/${id}`, { agency_may_pay: next }), 'Payment authority updated.');
                      }
                    }}>Change</button>
                  )}
                </dd>
              </div>
              <div>
                <dt>Client adds websites</dt>
                <dd>{c.client_self_service ? 'The client owner can add their own websites' : 'Only your team adds websites'}
                  {admin && <button type="button" className="link" onClick={() => void run(() => api('PATCH', `clients/${id}`, { client_self_service: !c.client_self_service }), 'Setting updated.')}>Change</button>}
                </dd>
              </div>
            </dl>
            {role !== 'owner' && <p className="hint">Only the workspace owner can change who pays.</p>}
          </section>
        </div>
      )}
    </div>
  );
}
