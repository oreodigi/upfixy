// SCR-02 Overview: what needs attention now. One headline, then the work.
import { useEffect, useState } from 'react';
import { TICKET_LABELS, type HealthLane, type TicketState } from '@upfixy/core';
import { api, type ApiIncident, type ApiOverview } from '../api';
import { IncidentRows } from './Incidents';
import { useWs } from '../app-context';
import { LANES, LANE_LABEL, StatusPill, since } from '../components';
import { HELP } from '../help';
import { href, navigate, onLinkClick } from '../router';

const KIND: Record<string, string> = { http: 'Website', tls: 'SSL', dns: 'Domain' };

export default function Overview() {
  const { isStaff, role, workspace, openHelp, setError } = useWs();
  const [o, setO] = useState<ApiOverview | null>(null);
  const [incidents, setIncidents] = useState<ApiIncident[]>([]);
  const loadIncidents = () => api<{ data: ApiIncident[] }>('GET', 'incidents?status=open').then((r) => setIncidents(r.data)).catch(() => undefined);

  useEffect(() => {
    api<ApiOverview>('GET', 'overview').then(setO).catch((e) => setError(e.message));
    void loadIncidents();
  }, [workspace?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!o) return <p className="hint" role="status">Loading…</p>;
  const lanes = o.websites.by_lane;
  const attention = lanes.critical + lanes.warning;
  const headline = o.websites.total === 0
    ? 'Add your first website to start monitoring'
    : attention === 0
      ? (lanes.unknown || lanes.needs_setup
        ? `Nothing failing, but ${lanes.unknown + lanes.needs_setup} of ${o.websites.total} websites have no fresh readings`
        : `All ${o.websites.total} websites look healthy`)
      : `${attention} of ${o.websites.total} websites need attention`;
  const openStates = Object.entries(o.requests.by_state).filter(([s]) => s !== 'resolved') as Array<[TicketState, number]>;

  return (
    <div className="page">
      <div className="headline">
        <h1>{headline}</h1>
        <p className="headline__sub">Health comes from fresh readings only. Missing or old readings show as Unknown, never Healthy.</p>
      </div>

      {o.websites.total > 0 && (
        <nav className="lanebar" aria-label="Websites by health">
          {LANES.map((l: HealthLane) => (
            <a key={l} href={`/websites?lane=${l}`} className={`lanebar__item lanebar__item--${l}`}
              onClick={(e) => { e.preventDefault(); navigate(`/websites?lane=${l}`); }}
              style={{ flexGrow: Math.max(lanes[l], 0.0001) }} aria-label={`${LANE_LABEL[l]}: ${lanes[l]}`}>
              {lanes[l] > 0 && <><StatusPill lane={l} /><span className="lanebar__n">{lanes[l]}</span></>}
            </a>
          ))}
        </nav>
      )}

      {incidents.length > 0 && (
        <section className="panel-box panel-box--alert" aria-labelledby="incidents-h">
          <h2 id="incidents-h">Open incidents ({incidents.length})</h2>
          <p className="hint">A problem becomes an incident after three failed checks in a row, and resolves after two good ones.</p>
          <IncidentRows incidents={incidents} onChanged={() => void loadIncidents()} />
        </section>
      )}

      <div className="split">
        <section className="panel-box" aria-labelledby="attention-h">
          <h2 id="attention-h">Needs attention</h2>
          {o.issues.length === 0 ? <p className="empty-inline">No failing or warning checks right now.</p> : (
            <ul className="rows">
              {o.issues.map((i) => {
                const code = i.result_code.toUpperCase();
                return (
                  <li key={`${i.target_id}-${i.kind}`} className={`row row--${i.severity}`}>
                    <div className="row__main">
                      <a href={href({ name: 'website', id: i.website_id })} onClick={onLinkClick} className="host">{i.host}</a>
                      <span>{HELP[code]?.title ?? `${KIND[i.kind]} check ${i.severity === 'critical' ? 'failing' : 'needs attention'}`}</span>
                    </div>
                    <div className="row__side">
                      <span className="hint">{since(new Date(i.observed_at))}</span>
                      <button type="button" className="link" onClick={() => openHelp({ code, title: HELP[code]?.title ?? code, observedAt: new Date(i.observed_at), host: i.host }, i.host)}>
                        Help me understand
                      </button>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </section>

        <section className="panel-box" aria-labelledby="requests-h">
          <h2 id="requests-h">Requests</h2>
          {o.requests.awaiting_quote_decision.length > 0 && (
            <>
              <h3>{isStaff ? 'Waiting for the customer to decide' : 'Waiting for your decision'}</h3>
              <ul className="rows">
                {o.requests.awaiting_quote_decision.map((t) => (
                  <li key={t.id} className="row">
                    <div className="row__main">
                      <a href={href({ name: 'work', ticket: t.id })} onClick={onLinkClick}>{t.title}</a>
                      <span className="host">{t.website}</span>
                    </div>
                    {!isStaff && <span className="tag tag--action">Review quote</span>}
                  </li>
                ))}
              </ul>
            </>
          )}
          {isStaff && (
            <>
              <h3>Assigned to you</h3>
              {o.requests.assigned_to_me.length === 0 ? <p className="empty-inline">Nothing assigned to you.</p> : (
                <ul className="rows">
                  {o.requests.assigned_to_me.map((t) => (
                    <li key={t.id} className="row">
                      <div className="row__main">
                        <a href={href({ name: 'work', ticket: t.id })} onClick={onLinkClick}>{t.title}</a>
                        <span className="host">{t.website}</span>
                      </div>
                      <span className="hint">{TICKET_LABELS[t.state]}</span>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
          {openStates.length > 0 ? (
            <dl className="counts">
              {openStates.map(([s, n]) => <div key={s}><dt>{TICKET_LABELS[s]}</dt><dd>{n}</dd></div>)}
            </dl>
          ) : <p className="empty-inline">No open requests.</p>}
          {role !== 'billing' && <a className="link" href="/work" onClick={onLinkClick}>{isStaff ? 'Open the work queue' : 'Open issues'}</a>}
        </section>
      </div>
    </div>
  );
}
