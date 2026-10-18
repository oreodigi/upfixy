// Work queue (BRD-003): workflow lanes only, separate from computed health.
import { useMemo, useState } from 'react';
import { TICKET_LABELS, TICKET_STATES, type TicketState } from '@upfixy/core';
import { api, ApiError } from '../api';
import { useWs } from '../app-context';
import { WorkBoard } from '../components';
import type { Ticket } from '../demo-data';
import { filterTickets, type Filters } from '../filters';
import { Toolbar } from './Boards';

export default function Work() {
  const { tickets, me, isStaff, isAgency, reload, openTicket, loaded } = useWs();
  const [filters, setFilters] = useState<Filters>({});

  const models: Ticket[] = useMemo(() => tickets.map((t) => ({
    id: t.id, title: t.title, host: t.website, client: t.client ?? '', state: t.state,
    assignee: t.assignee_id ? (t.assignee_id === me?.id ? 'you' : t.assignee_email ?? 'a technician') : null,
    assigneeId: t.assignee_id, version: t.version, hasApproval: t.approved, hasQuote: t.has_quote,
  })), [tickets, me?.id]);
  const shown = filterTickets(models, filters, me?.id ?? null);
  // Closed requests stay out of the board unless asked for; they are history.
  const states = (filters.states?.length ? filters.states : TICKET_STATES.filter((s) => s !== 'closed')) as TicketState[];

  const onMove = async (id: string, to: TicketState) => {
    const t = tickets.find((x) => x.id === id)!;
    try {
      await api('POST', `tickets/${id}/transition`, { to, version: t.version });
      await reload();
      return null;
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) await reload();
      return e instanceof Error ? e.message : String(e);
    }
  };

  return (
    <div className="page">
      <div className="headline">
        <h1>{isAgency ? 'Work queue' : 'Requests'}</h1>
        <p className="headline__sub">Stages show where the work is, not how healthy a website is. Drag a card, or use “Move to”.</p>
      </div>
      {tickets.length === 0 && loaded ? (
        <p className="empty">No requests yet. Use “Report a problem” on a website to start one.</p>
      ) : (
        <>
          <Toolbar board="work" filters={filters} setFilters={setFilters} shown={shown.length} total={models.length} extra={
            <>
              {isStaff && (
                <label className="views"><span className="sr-only">Assigned to</span>
                  <select value={filters.assignee ?? 'any'} onChange={(e) => setFilters({ ...filters, assignee: e.target.value as Filters['assignee'] })}>
                    <option value="any">Anyone</option>
                    <option value="me">Assigned to me</option>
                    <option value="unassigned">Unassigned</option>
                  </select>
                </label>
              )}
              <label className="views"><span className="sr-only">Stage</span>
                <select value={filters.states?.[0] ?? ''} onChange={(e) => setFilters({ ...filters, states: e.target.value ? [e.target.value as TicketState] : [] })}>
                  <option value="">Open stages</option>
                  {TICKET_STATES.map((s) => <option key={s} value={s}>{TICKET_LABELS[s]}</option>)}
                </select>
              </label>
            </>
          } />
          <WorkBoard tickets={shown} states={states} onMove={onMove} onOpen={openTicket} canMove={isStaff} />
        </>
      )}
    </div>
  );
}
