// UX-06 Work queue (BRD-003): support workflow lanes only, separate from computed health.
// Cards can be dragged, or moved with the "Move to" control (keyboard and phones). The
// server decides whether a move is allowed (approval, payment, verification); a refused move
// is reported and the board reloads.
import { useMemo, useState } from 'react';
import { TICKET_LABELS, TICKET_STATES, TRANSITIONS, type TicketState } from '@upfixy/core';
import {
  BadgeCheck, CircleDashed, ClipboardList, Hammer, IndianRupee, LayoutGrid, LifeBuoy, Search, ShieldCheck, Timer, UserRound, UserRoundX,
} from 'lucide-react';
import { api, ApiError } from '../api';
import { useWs } from '../app-context';
import { since } from '../components';
import { filterTickets, isFiltered, type Filters } from '../filters';
import { EmptyState, MetricCard, StatusBadge } from '../ui/kit';
import { BoardBar } from './Boards';
import { STAGE_STATUS } from './Ticket';

interface Card {
  id: string; title: string; host: string; client: string; state: TicketState; version: number;
  assignee: string | null; assigneeId: string | null; hasQuote: boolean; hasApproval: boolean; updated: string | null;
}

const STAGE_ICON: Record<TicketState, typeof Search> = {
  new: ClipboardList, investigating: Search, waiting_for_customer: Timer, quote_pending: IndianRupee,
  ready_for_work: BadgeCheck, in_progress: Hammer, verifying: ShieldCheck, resolved: BadgeCheck, closed: CircleDashed,
};
const STAGE_HINT: Record<TicketState, string> = {
  new: 'Just reported', investigating: 'Finding the cause', waiting_for_customer: 'Needs a reply', quote_pending: 'Waiting for approval',
  ready_for_work: 'Approved, not started', in_progress: 'Being fixed', verifying: 'Checking the fix', resolved: 'Fixed and verified', closed: 'History',
};
const OPEN: TicketState[] = TICKET_STATES.filter((s) => s !== 'resolved' && s !== 'closed');

const initials = (s: string) => s.replace(/@.*/, '').split(/[._\s-]+/).filter(Boolean).slice(0, 2).map((x) => x[0]!.toUpperCase()).join('') || '?';

export default function Work() {
  const { tickets, me, isStaff, isAgency, reload, openTicket, loaded } = useWs();
  const [filters, setFilters] = useState<Filters>({});
  const [message, setMessage] = useState<string | null>(null);
  const [dragging, setDragging] = useState<Card | null>(null);
  const [over, setOver] = useState<TicketState | null>(null);
  const [mobileStage, setMobileStage] = useState<TicketState | null>(null);

  const cards: Card[] = useMemo(() => tickets.map((t) => ({
    id: t.id, title: t.title, host: t.website, client: t.client ?? '', state: t.state, version: t.version,
    assignee: t.assignee_id ? (t.assignee_id === me?.id ? 'You' : t.assignee_email ?? 'A technician') : null,
    assigneeId: t.assignee_id, hasApproval: t.approved, hasQuote: t.has_quote, updated: t.updated_at ?? null,
  })), [tickets, me?.id]);
  const shown = filterTickets(cards, filters, me?.id ?? null);
  // Closed requests stay off the board unless asked for; they are history.
  const states = (filters.states?.length ? filters.states : TICKET_STATES.filter((s) => s !== 'closed')) as TicketState[];
  const count = (s: TicketState) => shown.filter((c) => c.state === s).length;
  const stage: TicketState = mobileStage && states.includes(mobileStage) ? mobileStage : states.find((s) => count(s) > 0) ?? states[0]!;

  const open = cards.filter((c) => OPEN.includes(c.state));
  const kpi = {
    open: open.length,
    unassigned: open.filter((c) => !c.assigneeId).length,
    mine: open.filter((c) => c.assigneeId && c.assigneeId === me?.id).length,
    approval: cards.filter((c) => c.state === 'quote_pending' && c.hasQuote && !c.hasApproval).length,
    active: cards.filter((c) => ['ready_for_work', 'in_progress', 'verifying'].includes(c.state)).length,
    resolved: cards.filter((c) => c.state === 'resolved').length,
  };

  async function move(c: Card, to: TicketState) {
    if (!TRANSITIONS[c.state].includes(to)) {
      setMessage(`Not moved: ${TICKET_LABELS[c.state]} can't move to ${TICKET_LABELS[to]}.`);
      return;
    }
    setMessage(`Moving “${c.title}” to ${TICKET_LABELS[to]}…`);
    try {
      await api('POST', `tickets/${c.id}/transition`, { to, version: c.version });
      await reload();
      setMessage(`Moved “${c.title}” to ${TICKET_LABELS[to]}.`);
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) await reload();
      setMessage(`Not moved: ${e instanceof Error ? e.message : String(e)}`);
    }
  }

  const layout = filters.layout ?? 'board';
  const renderCard = (c: Card) => (
    <article key={c.id} className={`ux-tcard ${dragging?.id === c.id ? 'ux-tcard--dragging' : ''}`}
      draggable={isStaff}
      onDragStart={(e) => { e.dataTransfer.setData('text/plain', c.id); e.dataTransfer.effectAllowed = 'move'; setDragging(c); }}
      onDragEnd={() => { setDragging(null); setOver(null); }}>
      <p className="ux-tcard__site"><span className="host">{c.host}</span>{c.client && <span> · {c.client}</span>}</p>
      <h3><button type="button" className="title-btn" onClick={() => openTicket(c.id)}>{c.title}</button></h3>
      <div className="ux-tcard__chips">
        {c.hasApproval ? <StatusBadge status="healthy" label="Approved" /> : c.hasQuote ? <StatusBadge status="warning" label="Quote sent" /> : null}
      </div>
      <footer className="ux-tcard__foot">
        <span className={`ux-avatar ${c.assigneeId ? '' : 'ux-avatar--none'}`} aria-hidden="true">{c.assignee ? initials(c.assignee) : <UserRoundX />}</span>
        <span className="ux-tcard__who">{c.assignee ? (c.assignee === 'You' ? 'Assigned to you' : c.assignee) : 'Unassigned'}</span>
        {c.updated && <span className="ux-tcard__when">{since(new Date(c.updated))}</span>}
      </footer>
      {isStaff && TRANSITIONS[c.state].length > 0 && (
        <label className="move ux-move">
          <span>Move to</span>
          <select value="" onChange={(e) => void move(c, e.target.value as TicketState)}>
            <option value="" disabled>Choose a stage</option>
            {TRANSITIONS[c.state].map((to) => <option key={to} value={to}>{TICKET_LABELS[to]}</option>)}
          </select>
        </label>
      )}
    </article>
  );

  return (
    <div className="ux-page">
      <header className="ux-head">
        <div>
          <h1>{isAgency ? 'Work queue' : 'Issues'}</h1>
          <p>Stages show where the work is, not how healthy a website is.{isStaff ? ' Drag a card, or use “Move to”.' : ''}</p>
        </div>
      </header>

      {tickets.length === 0 && loaded ? (
        <EmptyState icon={LifeBuoy} title="No requests yet">Use “Report a problem” or “Request a fix” on a website or diagnosis to start one.</EmptyState>
      ) : (
        <>
          <div className="ux-kpis ux-kpis--strip" style={{ ['--kpi-n' as string]: isStaff ? 5 : 4 }} tabIndex={0} role="region" aria-label="Request figures">
            <MetricCard tone="blue" icon={LifeBuoy} label="Open requests" value={kpi.open} sub={`${kpi.resolved} resolved, not yet closed`} />
            {isStaff && <MetricCard tone="violet" icon={UserRound} label="Assigned to you" value={kpi.mine} sub={`${kpi.unassigned} unassigned`} />}
            <MetricCard tone="orange" icon={IndianRupee} label="Awaiting approval" value={kpi.approval} sub="Quotes sent, not yet approved" />
            <MetricCard tone="aqua" icon={Hammer} label="Being worked on" value={kpi.active} sub="Ready, in progress or verifying" />
            <MetricCard tone="mint" icon={BadgeCheck} label="Resolved" value={kpi.resolved} sub="Fix verified by checks" />
          </div>

          <BoardBar board="work" filters={filters} setFilters={setFilters} shown={shown.length} total={cards.length} placeholder="Search requests, websites or clients" extra={
            <>
              {isStaff && (
                <label><span className="sr-only">Assigned to</span>
                  <select className="ux-select" value={filters.assignee ?? 'any'} onChange={(e) => setFilters({ ...filters, assignee: e.target.value as Filters['assignee'] })}>
                    <option value="any">Anyone</option>
                    <option value="me">Assigned to me</option>
                    <option value="unassigned">Unassigned</option>
                  </select>
                </label>
              )}
              <label><span className="sr-only">Stage</span>
                <select className="ux-select" value={filters.states?.[0] ?? ''} onChange={(e) => setFilters({ ...filters, states: e.target.value ? [e.target.value as TicketState] : [] })}>
                  <option value="">Open stages</option>
                  {TICKET_STATES.map((s) => <option key={s} value={s}>{TICKET_LABELS[s]}</option>)}
                </select>
              </label>
            </>
          } />
          <p className="sr-only" role="status" aria-live="polite">{message}</p>
          {message && <p className="ux-tk__msg ux-tk__msg--info" aria-hidden="true">{message}</p>}

          {shown.length === 0 ? (
            <EmptyState icon={Search} title="No requests match">{isFiltered(filters) ? 'Try clearing the filters.' : 'Nothing here yet.'}</EmptyState>
          ) : layout === 'list' ? (
            <div className="ux-panel" style={{ padding: '10px 14px' }}>
              <div className="ux-table-wrap" tabIndex={0} role="region" aria-label="Requests table">
                <table className="ux-table ux-table--cards">
                  <thead><tr><th scope="col">Request</th><th scope="col">Website</th>{isAgency && <th scope="col">Client</th>}<th scope="col">Stage</th><th scope="col">Assigned</th><th scope="col">Updated</th></tr></thead>
                  <tbody>
                    {shown.map((c) => (
                      <tr key={c.id}>
                        <td style={{ gridColumn: '1 / -1' }}><button type="button" className="title-btn" onClick={() => openTicket(c.id)}>{c.title}</button></td>
                        <td data-label="Website"><span className="host">{c.host}</span></td>
                        {isAgency && <td data-label="Client">{c.client || '—'}</td>}
                        <td><StatusBadge status={STAGE_STATUS[c.state]} label={TICKET_LABELS[c.state]} /></td>
                        <td data-label="Assigned">{c.assignee ?? 'Unassigned'}</td>
                        <td data-label="Updated">{c.updated ? since(new Date(c.updated)) : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : (
            <>
              <div className="ux-mobile-only ux-lanepick" role="group" aria-label="Show stage">
                <div className="ux-tabs ux-tabs--scroll">
                  {states.map((s) => {
                    const Icon = STAGE_ICON[s];
                    return (
                      <button key={s} type="button" className={`ux-tab ux-stage-tab--${s}`} aria-pressed={stage === s} onClick={() => setMobileStage(s)}>
                        <Icon aria-hidden="true" />{TICKET_LABELS[s]}<span className="ux-count">{count(s)}</span>
                      </button>
                    );
                  })}
                </div>
              </div>
              <div className="ux-mobile-only ux-mlane" aria-label={`Requests: ${TICKET_LABELS[stage]}`} role="region">
                {count(stage) ? shown.filter((c) => c.state === stage).map(renderCard)
                  : <div className="ux-lane__empty"><LayoutGrid aria-hidden="true" /><strong>Nothing here</strong>No requests are {TICKET_LABELS[stage].toLowerCase()}.</div>}
              </div>
              <div className="ux-wboard ux-desktop-only" tabIndex={0} role="region" aria-label="Requests by stage">
                {states.map((s) => {
                  const inLane = shown.filter((c) => c.state === s);
                  const allowed = dragging ? TRANSITIONS[dragging.state].includes(s) : false;
                  const drop = dragging && dragging.state !== s ? (allowed ? 'ux-wlane--ok' : 'ux-wlane--no') : '';
                  const Icon = STAGE_ICON[s];
                  return (
                    <section key={s} className={`ux-wlane ux-wlane--${s} ${drop} ${over === s && allowed ? 'ux-wlane--over' : ''}`}
                      aria-label={`${TICKET_LABELS[s]}: ${inLane.length}`}
                      onDragOver={(e) => { if (allowed) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; setOver(s); } }}
                      onDragLeave={() => setOver((o) => (o === s ? null : o))}
                      onDrop={(e) => { e.preventDefault(); const c = dragging; setDragging(null); setOver(null); if (c && c.state !== s) void move(c, s); }}>
                      <header className="ux-wlane__head">
                        <span className="ux-wlane__icon" aria-hidden="true"><Icon /></span>
                        <div><h2>{TICKET_LABELS[s]}</h2><p>{STAGE_HINT[s]}</p></div>
                        <span className="ux-count">{inLane.length}</span>
                      </header>
                      {inLane.length ? inLane.map(renderCard) : <p className="ux-wlane__empty">No requests</p>}
                    </section>
                  );
                })}
              </div>
            </>
          )}
        </>
      )}
    </div>
  );
}
