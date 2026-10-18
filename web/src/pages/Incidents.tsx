// MON-008 incidents (SCR-02 / SCR-07): open problems with acknowledge and timeline.
import { useState } from 'react';
import { api, type ApiIncident } from '../api';
import { useWs } from '../app-context';
import { since } from '../components';
import { HELP } from '../help';
import { href, onLinkClick } from '../router';

const fmt = (iso: string) => new Date(iso).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata' });

export function IncidentRows({ incidents, onChanged, showSite = true }: {
  incidents: ApiIncident[]; onChanged: () => void; showSite?: boolean;
}) {
  const { role, isStaff, openHelp, setError } = useWs();
  const [busy, setBusy] = useState<string | null>(null);
  const canAck = isStaff || role === 'client_owner';

  async function ack(id: string) {
    setBusy(id);
    try { await api('POST', `incidents/${id}/acknowledge`); onChanged(); }
    catch (e) { setError(e instanceof Error ? e.message : String(e)); }
    finally { setBusy(null); }
  }

  return (
    <ul className="rows">
      {incidents.map((i) => (
        <li key={i.id} className={`row row--${i.status === 'open' ? 'critical' : 'ok'}`}>
          <div className="row__main">
            {showSite && <a className="host" href={href({ name: 'website', id: i.website_id })} onClick={onLinkClick}>{i.host}</a>}
            <span><strong>{i.title}</strong></span>
            <span className="hint">
              {i.status === 'open'
                ? <>Failing since {fmt(i.first_failure_at)} · last failed {since(new Date(i.last_failure_at))}</>
                : <>Resolved {fmt(i.resolved_at!)} · last failure {fmt(i.last_failure_at)}</>}
              {i.acknowledged_at && <> · acknowledged</>}
            </span>
          </div>
          <div className="row__side">
            <button type="button" className="link" onClick={() => openHelp({ code: i.result_code, title: i.title, observedAt: new Date(i.last_failure_at), host: i.host }, i.host)}>
              Help me understand
            </button>
            {i.status === 'open' && !i.acknowledged_at && canAck && (
              <button type="button" className="btn btn--quiet btn--sm" disabled={busy === i.id} onClick={() => void ack(i.id)}>Acknowledge</button>
            )}
          </div>
        </li>
      ))}
    </ul>
  );
}
