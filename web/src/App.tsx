import { useState } from 'react';
import { canTransition, type TicketState } from '@upfixy/core';
import { demoClients, demoSites, demoTickets, type Issue, type Ticket } from './demo-data';
import { ClientCard, SiteCard, HealthBoard, SiteList, WorkBoard, HelpDialog } from './components';

type View = 'clients' | 'websites' | 'work';
type Mode = 'board' | 'list';

const VIEWS: Array<{ id: View; label: string }> = [
  { id: 'clients', label: 'Clients' },
  { id: 'websites', label: 'Websites' },
  { id: 'work', label: 'Work queue' },
];

export default function App() {
  const [view, setView] = useState<View>('clients');
  const [mode, setMode] = useState<Mode>('board');
  const [query, setQuery] = useState('');
  const [help, setHelp] = useState<{ issue: Issue; host: string } | null>(null);
  const [tickets, setTickets] = useState<Ticket[]>(demoTickets);

  const q = query.trim().toLowerCase();
  const clients = demoClients.filter((c) => !q || c.name.toLowerCase().includes(q) || c.sites.some((s) => s.host.includes(q)));
  const sites = demoSites.filter((s) => !q || s.host.includes(q));
  const attention = demoClients.filter((c) => c.lane === 'critical' || c.lane === 'warning').length;

  const onHelp = (issue: Issue, host: string) => setHelp({ issue, host });
  const onMove = (id: string, to: TicketState): string | null => {
    const t = tickets.find((x) => x.id === id)!;
    const check = canTransition(t.state, to, {
      approvals: t.hasApproval ? [{ amountPaise: 0, expiresAt: new Date(Date.now() + 86_400_000), voided: false, paymentVerified: false }] : [],
      verificationPassed: false,
    });
    if (!check.ok) return check.reason;
    setTickets((ts) => ts.map((x) => (x.id === id ? { ...x, state: to } : x)));
    return null;
  };

  return (
    <div className="app">
      <header className="top">
        <div className="top__brand">
          <span className="logo" aria-hidden="true">U</span>
          <div>
            <p className="top__name">Upfixy</p>
            <p className="top__ws">Demo agency workspace</p>
          </div>
        </div>
        <nav aria-label="Main" className="tabs">
          {VIEWS.map((v) => (
            <button key={v.id} type="button" className="tab" aria-current={view === v.id ? 'page' : undefined} onClick={() => setView(v.id)}>
              {v.label}
            </button>
          ))}
        </nav>
      </header>

      <p className="demo" role="note">Demo data. The API isn't connected yet, so nothing here is a real reading.</p>

      <main className="main">
        <div className="headline">
          {view === 'clients' && <h1>{attention ? `${attention} of ${demoClients.length} clients need attention` : 'All clients look fine'}</h1>}
          {view === 'websites' && <h1>{demoSites.length} websites</h1>}
          {view === 'work' && <h1>{tickets.filter((t) => t.state !== 'closed').length} open requests</h1>}
          {view !== 'work' && (
            <p className="headline__sub">Unknown means we don't have a fresh reading. It never counts as healthy.</p>
          )}
        </div>

        {view !== 'work' && (
          <div className="toolbar">
            <label className="search">
              <span className="sr-only">Search {view}</span>
              <input type="search" placeholder={view === 'clients' ? 'Search clients or domains' : 'Search domains'}
                value={query} onChange={(e) => setQuery(e.target.value)} />
            </label>
            <div className="seg" role="group" aria-label="Layout">
              <button type="button" aria-pressed={mode === 'board'} onClick={() => setMode('board')}>Board</button>
              <button type="button" aria-pressed={mode === 'list'} onClick={() => setMode('list')}>List</button>
            </div>
          </div>
        )}

        {view === 'clients' && (mode === 'board'
          ? <HealthBoard items={clients} noun="Clients" render={(c) => <ClientCard client={c} onHelp={onHelp} />} />
          : <SiteList sites={clients.flatMap((c) => c.sites)} clients={demoClients} />)}
        {view === 'websites' && (mode === 'board'
          ? <HealthBoard items={sites} noun="Websites" render={(s) => <SiteCard site={s} onHelp={onHelp} />} />
          : <SiteList sites={sites} clients={demoClients} />)}
        {view === 'work' && <WorkBoard tickets={tickets} onMove={onMove} />}
      </main>

      <HelpDialog issue={help?.issue ?? null} host={help?.host ?? ''} onClose={() => setHelp(null)} />
    </div>
  );
}
