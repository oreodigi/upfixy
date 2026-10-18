import { useEffect, useRef, useState } from 'react';
import {
  TRANSITIONS, TICKET_LABELS, TICKET_STATES, canTransition,
  type HealthLane, type ComponentState, type TicketState,
} from '@upfixy/core';
import type { Client, Site, Ticket, Issue, ComponentKey } from './demo-data';
import { HELP, supportMessage } from './help';
import { onLinkClick } from './router';

export const LANES: HealthLane[] = ['critical', 'warning', 'unknown', 'needs_setup', 'healthy'];
export const LANE_LABEL: Record<HealthLane, string> = {
  critical: 'Critical', warning: 'Warning', unknown: 'Unknown', needs_setup: 'Needs setup', healthy: 'Healthy',
};
const GLYPH: Record<HealthLane | ComponentState, string> = {
  critical: '!', warning: '▲', unknown: '?', needs_setup: '+', healthy: '✓', ok: '✓', not_connected: '–',
};
export const COMPONENT_LABEL: Record<ComponentKey, string> = {
  domain: 'Domain', ssl: 'SSL', errors: 'Errors', hosting: 'Hosting', security: 'Security', backup: 'Backup',
};

const since = (d: Date) => {
  const m = Math.round((Date.now() - d.getTime()) / 60_000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m} min ago`;
  if (m < 48 * 60) return `${Math.round(m / 60)} h ago`;
  return `${Math.round(m / 1440)} days ago`;
};

/** "Checked …" only for fresh readings; stale data says so instead of hiding its age. */
export function ago(verified: Date | null, observed: Date | null = null): string {
  if (verified) return `Checked ${since(verified)}`;
  if (observed) return `Last reading ${since(observed)} (stale)`;
  return 'Never checked';
}
export { since };

export function StatusPill({ lane }: { lane: HealthLane }) {
  return (
    <span className={`pill pill--${lane}`}>
      <span aria-hidden="true" className="pill__glyph">{GLYPH[lane]}</span>
      {LANE_LABEL[lane]}
    </span>
  );
}

/** Link that navigates in-app; plain <span> in the demo (no href). */
export function Title({ href, children, className }: { href?: string; children: React.ReactNode; className?: string }) {
  return href ? <a href={href} onClick={onLinkClick} className={`title-link ${className ?? ''}`}>{children}</a> : <>{children}</>;
}

/** One row per component: icon, name, honest value. Readable at card width. */
export function StatusList({ components, only }: { components: Site['components']; only?: ComponentKey[] }) {
  return (
    <ul className="status" aria-label="Status by area">
      {(only ?? (Object.keys(COMPONENT_LABEL) as ComponentKey[])).map((k) => (
        <li key={k} className={`st st--${components[k].state}`}>
          <span className="st__glyph" aria-hidden="true">{GLYPH[components[k].state]}</span>
          <span className="st__label">{COMPONENT_LABEL[k]}</span>
          <span className="st__value">{components[k].label}</span>
        </li>
      ))}
    </ul>
  );
}

/** Worst component across a client's sites, for the client card summary. */
export function worstComponents(sites: Site[]): Site['components'] {
  const rank: Record<ComponentState, number> = { critical: 4, warning: 3, unknown: 2, not_connected: 1, ok: 0 };
  const out = {} as Site['components'];
  for (const k of Object.keys(COMPONENT_LABEL) as ComponentKey[]) {
    out[k] = sites.map((s) => s.components[k]).reduce((a, b) => (rank[b.state] > rank[a.state] ? b : a));
  }
  return out;
}

function IssueList({ issues, onHelp, showHost }: { issues: Array<Issue & { host?: string }>; onHelp: (i: Issue, host: string) => void; showHost?: boolean }) {
  if (!issues.length) return null;
  return (
    <ul className="issues">
      {issues.map((i) => (
        <li key={(i.host ?? '') + i.code}>
          <span className="issues__text">{showHost && i.host && <strong>{i.host} </strong>}{i.title}</span>
          <button type="button" className="link" onClick={() => onHelp(i, i.host ?? '')}>Help me understand</button>
        </li>
      ))}
    </ul>
  );
}

const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

export function ClientCard({ client, onHelp }: { client: Client; onHelp: (i: Issue, host: string) => void }) {
  const issues = client.sites.flatMap((s) => s.issues.map((i) => ({ ...i, host: i.host ?? s.host }))).slice(0, 3);
  const impacted = client.sites.filter((s) => s.lane !== 'healthy').length;
  const times = (xs: Array<Date | null>) => xs.filter(Boolean).map((d) => d!.getTime());
  const verified = times(client.sites.map((s) => s.lastVerifiedAt));
  const observed = times(client.sites.map((s) => s.lastObservedAt));
  // Any site without a fresh reading means the client as a whole isn't freshly verified.
  const allFresh = verified.length === client.sites.length && verified.length > 0;
  const oldest = allFresh ? new Date(Math.min(...verified)) : null;
  const newestObserved = observed.length ? new Date(Math.max(...observed)) : null;
  const staff = client.staff ?? (client.technician ? [client.technician] : []);
  return (
    <article className={`card card--${client.lane}`} aria-label={`${client.name}, ${LANE_LABEL[client.lane]}`}>
      <header className="card__head">
        <h3><Title href={client.href}>{client.name}</Title></h3>
        <StatusPill lane={client.lane} />
      </header>
      <p className="card__meta">
        {plural(client.sites.length, 'website')}
        {impacted > 0 && <>, {impacted} need attention</>}
        {client.openTickets > 0 && <>, {plural(client.openTickets, 'open request')}</>}
      </p>
      {client.sites.length > 0 && <StatusList components={worstComponents(client.sites)} />}
      <IssueList issues={issues} onHelp={onHelp} showHost />
      <footer className="card__foot">
        <span>{staff.length ? `Staff: ${staff.join(', ')}` : 'No technician assigned'}</span>
        <span>{ago(oldest, newestObserved)}</span>
      </footer>
      {client.tags.length > 0 && <p className="tags">{client.tags.map((t) => <span key={t}>{t}</span>)}</p>}
    </article>
  );
}

export function SiteCard({ site, onHelp, actions }: { site: Site; onHelp: (i: Issue, host: string) => void; actions?: React.ReactNode }) {
  return (
    <article className={`card card--${site.lane}`} aria-label={`${site.host}, ${LANE_LABEL[site.lane]}`}>
      <header className="card__head">
        <h3 className="host"><Title href={site.href}>{site.host}</Title></h3>
        <StatusPill lane={site.lane} />
      </header>
      <StatusList components={site.components} />
      <IssueList issues={site.issues} onHelp={(i) => onHelp(i, i.host ?? site.host)} showHost={(site.hosts?.length ?? 1) > 1} />
      <footer className="card__foot">
        <span>{ago(site.lastVerifiedAt, site.lastObservedAt)}</span>
        {!!site.openRequests && <span>{plural(site.openRequests, 'open request')}</span>}
      </footer>
      {actions && <div className="card__actions">{actions}</div>}
    </article>
  );
}

/** Lane filter chips: toggle lanes on and off; counts are totals before lane filtering. */
export function LaneChips({ counts, selected, onChange }: {
  counts: Record<HealthLane, number>; selected: HealthLane[]; onChange: (lanes: HealthLane[]) => void;
}) {
  return (
    <div className="chips" role="group" aria-label="Show health">
      {LANES.map((l) => {
        const on = selected.includes(l);
        return (
          <button key={l} type="button" className={`chip chip--${l}`} aria-pressed={on}
            onClick={() => onChange(on ? selected.filter((x) => x !== l) : [...selected, l])}>
            <span aria-hidden="true" className="chip__glyph">{GLYPH[l]}</span>{LANE_LABEL[l]} <span className="chip__n">{counts[l]}</span>
          </button>
        );
      })}
    </div>
  );
}

/** Cards rendered per lane before "Show all": keeps a 200-card board fast on phones (NFR-02). */
export const LANE_PAGE = 8;

/** Health lanes: computed by monitoring, never draggable (AC-BRD-001.2). */
export function HealthBoard<T extends { lane: HealthLane; id: string }>({ items, render, noun, lanes }: {
  items: T[]; render: (t: T) => React.ReactNode; noun: string; lanes?: HealthLane[];
}) {
  const [open, setOpen] = useState<Partial<Record<HealthLane, boolean>>>({});
  const shown = lanes?.length ? LANES.filter((l) => lanes.includes(l)) : LANES;
  return (
    <div className="board" role="list" aria-label={`${noun} by health`}>
      {shown.map((lane) => {
        const inLane = items.filter((i) => i.lane === lane);
        const visible = open[lane] ? inLane : inLane.slice(0, LANE_PAGE);
        const hidden = inLane.length - visible.length;
        return (
          <section key={lane} className={`lane lane--${lane} ${inLane.length ? '' : 'lane--empty'}`} role="listitem" aria-label={`${LANE_LABEL[lane]}: ${inLane.length}`}>
            <h2 className="lane__title"><StatusPill lane={lane} /><span className="count">{inLane.length}</span></h2>
            <div className="lane__body">
              {inLane.length ? visible.map((i) => <div key={i.id}>{render(i)}</div>)
                : <p className="lane__none">No {noun.toLowerCase()} here.</p>}
              {hidden > 0 && (
                <button type="button" className="btn btn--quiet lane__more" onClick={() => setOpen({ ...open, [lane]: true })}>
                  Show all {inLane.length} {LANE_LABEL[lane].toLowerCase()}
                </button>
              )}
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
    <div className="table-wrap" tabIndex={0} role="region" aria-label="Scrollable table">
      <table className="list">
        <thead>
          <tr><th scope="col">Website</th>{clients && <th scope="col">Client</th>}<th scope="col">Health</th>
            <th scope="col">Domain</th><th scope="col">SSL</th><th scope="col">Errors</th><th scope="col">Last check</th></tr>
        </thead>
        <tbody>
          {sorted.map((s) => (
            <tr key={s.id}>
              <th scope="row" className="host"><Title href={s.href}>{s.host}</Title></th>
              {clients && <td>{name(s.clientId)}</td>}
              <td><StatusPill lane={s.lane} /></td>
              {(['domain', 'ssl', 'errors'] as const).map((k) => (
                <td key={k} className={`txt--${s.components[k].state}`}>{s.components[k].label}</td>
              ))}
              <td className="num">{ago(s.lastVerifiedAt, s.lastObservedAt)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Work queue (BRD-003). Workflow lanes are separate from health. A drag (mouse)
 * or the "Move to" control (keyboard, touch) both REQUEST a move; the server
 * validates it. While dragging, only lanes the workflow allows accept the card.
 */
export function WorkBoard({ tickets, onMove, onOpen, canMove = true, states = TICKET_STATES as readonly TicketState[] }: {
  tickets: Ticket[];
  onMove: (id: string, to: TicketState) => Promise<string | null> | string | null;
  onOpen?: (id: string) => void;
  canMove?: boolean;
  states?: readonly TicketState[];
}) {
  const [message, setMessage] = useState<string | null>(null);
  const [dragging, setDragging] = useState<Ticket | null>(null);
  const [over, setOver] = useState<TicketState | null>(null);

  async function move(t: Ticket, to: TicketState) {
    if (!TRANSITIONS[t.state].includes(to)) {
      setMessage(`Not moved: ${TICKET_LABELS[t.state]} can't move to ${TICKET_LABELS[to]}.`);
      return;
    }
    setMessage(`Moving "${t.title}" to ${TICKET_LABELS[to]}…`);
    const err = await onMove(t.id, to);
    setMessage(err ? `Not moved: ${err}` : `Moved "${t.title}" to ${TICKET_LABELS[to]}.`);
  }

  return (
    <>
      <p className="live" role="status" aria-live="polite">{message}</p>
      <div className="board board--work">
        {states.map((state) => {
          const inLane = tickets.filter((t) => t.state === state);
          const allowed = dragging ? TRANSITIONS[dragging.state].includes(state) : false;
          const dropClass = dragging && dragging.state !== state ? (allowed ? 'lane--drop-ok' : 'lane--drop-no') : '';
          return (
            <section key={state}
              className={`lane ${inLane.length || dragging ? '' : 'lane--empty'} ${dropClass} ${over === state && allowed ? 'lane--drop-over' : ''}`}
              aria-label={`${TICKET_LABELS[state]}: ${inLane.length}`}
              onDragOver={(e) => { if (allowed) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; setOver(state); } }}
              onDragLeave={() => setOver((o) => (o === state ? null : o))}
              onDrop={(e) => {
                e.preventDefault();
                const t = dragging; setDragging(null); setOver(null);
                if (t && t.state !== state) void move(t, state);
              }}>
              <h2 className="lane__title"><span>{TICKET_LABELS[state]}</span><span className="count">{inLane.length}</span></h2>
              <div className="lane__body">
                {inLane.map((t) => (
                  <article key={t.id} className={`card ticket ${dragging?.id === t.id ? 'ticket--dragging' : ''}`}
                    draggable={canMove}
                    onDragStart={(e) => { e.dataTransfer.setData('text/plain', t.id); e.dataTransfer.effectAllowed = 'move'; setDragging(t); }}
                    onDragEnd={() => { setDragging(null); setOver(null); }}>
                    <h3>{onOpen ? <button type="button" className="title-btn" onClick={() => onOpen(t.id)}>{t.title}</button> : t.title}</h3>
                    <p className="card__meta"><span className="host">{t.host}</span>{t.client ? `, ${t.client}` : ''}</p>
                    <p className="card__meta">
                      {t.assignee ? `Assigned to ${t.assignee}` : 'Unassigned'}
                      {t.hasApproval ? ', approved' : t.hasQuote ? ', quote sent' : ''}
                    </p>
                    {canMove && <label className="move">
                      <span>Move to</span>
                      <select value="" onChange={(e) => void move(t, e.target.value as TicketState)}>
                        <option value="" disabled>Choose a stage</option>
                        {TRANSITIONS[t.state].map((to) => {
                          const check = canTransition(t.state, to, {
                            approvals: t.hasApproval ? [{ amountPaise: 0, expiresAt: new Date(Date.now() + 86_400_000), voided: false, paymentVerified: false }] : [],
                            verificationPassed: false,
                          });
                          return <option key={to} value={to}>{TICKET_LABELS[to]}{check.ok ? '' : ' (needs approval or verification)'}</option>;
                        })}
                      </select>
                    </label>}
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
      ) : <p>We don't have a plain-language guide for this result yet. The message below gives support the details.</p>}
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
