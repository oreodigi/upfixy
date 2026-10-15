import { useEffect, useRef, useState } from 'react';
import {
  TRANSITIONS, TICKET_LABELS, TICKET_STATES, canTransition,
  type HealthLane, type ComponentState, type TicketState,
} from '@upfixy/core';
import type { Client, Site, Ticket, Issue, ComponentKey } from './demo-data';
import { HELP, supportMessage } from './help';

export const LANES: HealthLane[] = ['critical', 'warning', 'unknown', 'needs_setup', 'healthy'];
export const LANE_LABEL: Record<HealthLane, string> = {
  critical: 'Critical', warning: 'Warning', unknown: 'Unknown', needs_setup: 'Needs setup', healthy: 'Healthy',
};
const GLYPH: Record<HealthLane | ComponentState, string> = {
  critical: '!', warning: '▲', unknown: '?', needs_setup: '+', healthy: '✓', ok: '✓', not_connected: '–',
};
const COMPONENT_LABEL: Record<ComponentKey, string> = {
  domain: 'Domain', hosting: 'Hosting', ssl: 'SSL', security: 'Security', backup: 'Backup', errors: 'Errors',
};

const since = (d: Date) => {
  const m = Math.round((Date.now() - d.getTime()) / 60_000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m} min ago`;
  return `${Math.round(m / 60)} h ago`;
};

/** "Checked …" only for fresh readings; stale data says so instead of hiding its age. */
export function ago(verified: Date | null, observed: Date | null = null): string {
  if (verified) return `Checked ${since(verified)}`;
  if (observed) return `Last reading ${since(observed)} (stale)`;
  return 'Never checked';
}

export function StatusPill({ lane }: { lane: HealthLane }) {
  return (
    <span className={`pill pill--${lane}`}>
      <span aria-hidden="true" className="pill__glyph">{GLYPH[lane]}</span>
      {LANE_LABEL[lane]}
    </span>
  );
}

function ComponentRow({ components }: { components: Site['components'] }) {
  return (
    <dl className="components">
      {(Object.keys(COMPONENT_LABEL) as ComponentKey[]).map((k) => (
        <div key={k} className={`comp comp--${components[k].state}`}>
          <dt>{COMPONENT_LABEL[k]}</dt>
          <dd><span aria-hidden="true">{GLYPH[components[k].state]} </span>{components[k].label}</dd>
        </div>
      ))}
    </dl>
  );
}

/** Worst component across a client's sites, for the client card summary row. */
function worstComponents(sites: Site[]): Site['components'] {
  const rank: Record<ComponentState, number> = { critical: 4, warning: 3, unknown: 2, not_connected: 1, ok: 0 };
  const out = {} as Site['components'];
  for (const k of Object.keys(COMPONENT_LABEL) as ComponentKey[]) {
    out[k] = sites.map((s) => s.components[k]).reduce((a, b) => (rank[b.state] > rank[a.state] ? b : a));
  }
  return out;
}

export function ClientCard({ client, onHelp }: { client: Client; onHelp: (i: Issue, host: string) => void }) {
  const issues = client.sites.flatMap((s) => s.issues.map((i) => ({ ...i, host: s.host }))).slice(0, 3);
  const impacted = client.sites.filter((s) => s.lane !== 'healthy').length;
  const times = (xs: Array<Date | null>) => xs.filter(Boolean).map((d) => d!.getTime());
  const verified = times(client.sites.map((s) => s.lastVerifiedAt));
  const observed = times(client.sites.map((s) => s.lastObservedAt));
  // Any site without a fresh reading means the client as a whole isn't freshly verified.
  const allFresh = verified.length === client.sites.length && verified.length > 0;
  const oldest = allFresh ? new Date(Math.min(...verified)) : null;
  const newestObserved = observed.length ? new Date(Math.max(...observed)) : null;
  return (
    <article className={`card card--${client.lane}`} aria-label={`${client.name}, ${LANE_LABEL[client.lane]}`}>
      <header className="card__head">
        <h3>{client.name}</h3>
        <StatusPill lane={client.lane} />
      </header>
      <p className="card__meta">
        {client.sites.length} {client.sites.length === 1 ? 'website' : 'websites'}
        {impacted > 0 && <>, {impacted} need attention</>}
        {client.openTickets > 0 && <>, {client.openTickets} open {client.openTickets === 1 ? 'request' : 'requests'}</>}
      </p>
      {client.sites.length > 0 && <ComponentRow components={worstComponents(client.sites)} />}
      {issues.length > 0 && (
        <ul className="issues">
          {issues.map((i) => (
            <li key={i.host + i.code}>
              <span className="issues__text"><strong>{i.host}</strong> {i.title}</span>
              <button type="button" className="link" onClick={() => onHelp(i, i.host)}>Help me understand</button>
            </li>
          ))}
        </ul>
      )}
      <footer className="card__foot">
        <span>{client.technician ? `Assigned to ${client.technician}` : 'Unassigned'}</span>
        <span>{ago(oldest, newestObserved)}</span>
      </footer>
      {client.tags.length > 0 && <p className="tags">{client.tags.map((t) => <span key={t}>{t}</span>)}</p>}
    </article>
  );
}

export function SiteCard({ site, onHelp }: { site: Site; onHelp: (i: Issue, host: string) => void }) {
  return (
    <article className={`card card--${site.lane}`} aria-label={`${site.host}, ${LANE_LABEL[site.lane]}`}>
      <header className="card__head">
        <h3 className="host">{site.host}</h3>
        <StatusPill lane={site.lane} />
      </header>
      <ComponentRow components={site.components} />
      {site.issues.length > 0 && (
        <ul className="issues">
          {site.issues.map((i) => (
            <li key={i.code}>
              <span className="issues__text">{i.title}</span>
              <button type="button" className="link" onClick={() => onHelp(i, site.host)}>Help me understand</button>
            </li>
          ))}
        </ul>
      )}
      <footer className="card__foot"><span>{ago(site.lastVerifiedAt, site.lastObservedAt)}</span></footer>
    </article>
  );
}

export function HealthBoard<T extends { lane: HealthLane; id: string }>({ items, render, noun }: {
  items: T[]; render: (t: T) => React.ReactNode; noun: string;
}) {
  return (
    <div className="board" role="list" aria-label={`${noun} by health`}>
      {LANES.map((lane) => {
        const inLane = items.filter((i) => i.lane === lane);
        return (
          <section key={lane} className={`lane lane--${lane} ${inLane.length ? '' : 'lane--empty'}`} role="listitem" aria-label={`${LANE_LABEL[lane]}: ${inLane.length}`}>
            <h2 className="lane__title"><StatusPill lane={lane} /><span className="count">{inLane.length}</span></h2>
            <div className="lane__body">
              {inLane.length ? inLane.map((i) => <div key={i.id}>{render(i)}</div>)
                : <p className="lane__none">No {noun.toLowerCase()} here.</p>}
            </div>
          </section>
        );
      })}
    </div>
  );
}

export function SiteList({ sites, clients }: { sites: Site[]; clients?: Client[] }) {
  const name = (id: string | null) => clients?.find((c) => c.id === id)?.name ?? '—';
  const order = (l: HealthLane) => LANES.indexOf(l);
  const sorted = [...sites].sort((a, b) => order(a.lane) - order(b.lane) || a.host.localeCompare(b.host));
  return (
    <div className="table-wrap">
      <table className="list">
        <thead>
          <tr><th scope="col">Website</th>{clients && <th scope="col">Client</th>}<th scope="col">Health</th>
            <th scope="col">SSL</th><th scope="col">Backup</th><th scope="col">Last check</th></tr>
        </thead>
        <tbody>
          {sorted.map((s) => (
            <tr key={s.id}>
              <th scope="row" className="host">{s.host}</th>
              {clients && <td>{name(s.clientId)}</td>}
              <td><StatusPill lane={s.lane} /></td>
              <td className={`txt--${s.components.ssl.state}`}>{s.components.ssl.label}</td>
              <td className={`txt--${s.components.backup.state}`}>{s.components.backup.label}</td>
              <td className="num">{ago(s.lastVerifiedAt, s.lastObservedAt)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Work queue: workflow lanes are separate from health and moved by a validated action, never by drag alone. */
export function WorkBoard({ tickets, onMove }: { tickets: Ticket[]; onMove: (id: string, to: TicketState) => string | null }) {
  const [message, setMessage] = useState<string | null>(null);
  return (
    <>
      <p className="live" role="status" aria-live="polite">{message}</p>
      <div className="board board--work">
        {TICKET_STATES.map((state) => {
          const inLane = tickets.filter((t) => t.state === state);
          return (
            <section key={state} className={`lane ${inLane.length ? '' : 'lane--empty'}`} aria-label={`${TICKET_LABELS[state]}: ${inLane.length}`}>
              <h2 className="lane__title"><span>{TICKET_LABELS[state]}</span><span className="count">{inLane.length}</span></h2>
              <div className="lane__body">
                {inLane.map((t) => (
                  <article key={t.id} className="card ticket">
                    <h3>{t.title}</h3>
                    <p className="card__meta"><span className="host">{t.host}</span>, {t.client}</p>
                    <p className="card__meta">{t.assignee ? `Assigned to ${t.assignee}` : 'Unassigned'}{t.hasApproval ? ', approval recorded' : ''}</p>
                    <label className="move">
                      <span>Move to</span>
                      <select value="" onChange={(e) => {
                        const to = e.target.value as TicketState;
                        const err = onMove(t.id, to);
                        setMessage(err ? `Not moved: ${err}` : `Moved "${t.title}" to ${TICKET_LABELS[to]}.`);
                      }}>
                        <option value="" disabled>Choose a stage</option>
                        {TRANSITIONS[t.state].map((to) => {
                          const check = canTransition(t.state, to, {
                            approvals: t.hasApproval ? [{ amountPaise: 0, expiresAt: new Date(Date.now() + 86_400_000), voided: false, paymentVerified: false }] : [],
                            verificationPassed: false,
                          });
                          return <option key={to} value={to}>{TICKET_LABELS[to]}{check.ok ? '' : ' (blocked)'}</option>;
                        })}
                      </select>
                    </label>
                  </article>
                ))}
              </div>
            </section>
          );
        })}
      </div>
    </>
  );
}

export function HelpDialog({ issue, host, onClose }: { issue: Issue | null; host: string; onClose: () => void }) {
  const ref = useRef<HTMLDialogElement>(null);
  const [copied, setCopied] = useState(false);
  useEffect(() => {
    if (issue && !ref.current?.open) { setCopied(false); ref.current?.showModal(); }
  }, [issue]);
  if (!issue) return null;
  const h = HELP[issue.code];
  const msg = supportMessage(issue.code, host, issue.observedAt);
  return (
    <dialog ref={ref} className="help" onClose={onClose} aria-labelledby="help-title">
      <h2 id="help-title">{h?.title ?? issue.title}</h2>
      <p className="help__host host">{host}, observed {issue.observedAt.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}</p>
      {h ? (
        <>
          <h3>What does this mean?</h3><p>{h.meaning}</p>
          <h3>What could be causing it?</h3><ul>{h.causes.map((c) => <li key={c}>{c}</li>)}</ul>
          <h3>What can I check?</h3><ul>{h.checks.map((c) => <li key={c}>{c}</li>)}</ul>
          <h3>What should I do next?</h3><p>{h.next}</p>
        </>
      ) : <p>No guide for this issue yet.</p>}
      <h3>Message for support</h3>
      <p className="help__msg">{msg}</p>
      <div className="help__actions">
        <button type="button" className="btn" onClick={() => navigator.clipboard?.writeText(msg).then(() => setCopied(true))}>
          {copied ? 'Copied' : 'Copy message'}
        </button>
        <button type="button" className="btn btn--quiet" onClick={() => ref.current?.close()}>Close</button>
      </div>
    </dialog>
  );
}
