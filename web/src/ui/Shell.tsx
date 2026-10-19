// AppShell pieces: desktop navy Sidebar + Topbar, mobile Topbar + bottom nav + More sheet.
// Navigation items and role gating are decided by LiveApp; this only renders them.
import { useEffect, useRef, useState } from 'react';
import { Bell, CalendarClock, ChevronDown, Ellipsis, FlaskConical, LogOut, Search, Settings, ShieldCheck, type LucideIcon } from 'lucide-react';
import { navigate, onLinkClick } from '../router';

export interface ShellNavItem { to: string; label: string; icon: LucideIcon; current: boolean; badge?: number }

export const ROLE_LABEL: Record<string, string> = {
  owner: 'Owner', admin: 'Admin', technician: 'Technician', billing: 'Billing', client_owner: 'Client owner', client_staff: 'Client staff',
};

/** Original Upfixy mark: a rounded tile with a "U" that closes into a check. */
export function BrandMark({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 40 40" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id="ux-mark" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#38bdf8" /><stop offset=".55" stopColor="#2563eb" /><stop offset="1" stopColor="#7c3aed" />
        </linearGradient>
      </defs>
      <rect x="1" y="1" width="38" height="38" rx="11" fill="url(#ux-mark)" />
      <path d="M12 11v9.5a8 8 0 0 0 16 0V11" fill="none" stroke="#fff" strokeWidth="3.6" strokeLinecap="round" />
      <path d="M16.5 20.5l3 3 5.5-6" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" opacity=".9" />
    </svg>
  );
}

function NavLink({ n, onNav }: { n: ShellNavItem; onNav?: () => void }) {
  const Icon = n.icon;
  return (
    <a href={n.to} aria-current={n.current ? 'page' : undefined} onClick={(e) => { onLinkClick(e); onNav?.(); }}>
      <Icon aria-hidden="true" /><span>{n.label}</span>
      {!!n.badge && <span className="ux-nav__badge" aria-label={`${n.badge} unread`}>{n.badge}</span>}
    </a>
  );
}

export function Sidebar({ primary, secondary, switcher, workspaceKind, siteCount }: {
  primary: ShellNavItem[]; secondary: ShellNavItem[]; switcher: React.ReactNode; workspaceKind: 'solo' | 'agency' | undefined; siteCount?: number;
}) {
  return (
    <aside className="ux-sidebar">
      <a className="ux-brand" href="/" onClick={onLinkClick} aria-label="Upfixy home">
        <BrandMark className="ux-brand__mark" />
        <span><span className="ux-brand__name">Upfixy</span><span className="ux-brand__tag">Monitor · Diagnose · Resolve</span></span>
      </a>
      <div className="ux-ws">{switcher}</div>
      <nav aria-label="Main" className="ux-nav">
        {primary.map((n) => <NavLink key={n.to} n={n} />)}
        <p className="ux-nav__label" aria-hidden="true">Workspace</p>
        {secondary.map((n) => <NavLink key={n.to} n={n} />)}
      </nav>
      <div className="ux-sidebar__foot">
        <span className="ux-sidebar__shield" aria-hidden="true"><ShieldCheck /></span>
        <div>
          <strong>Read-only by default</strong>
          <p>{workspaceKind === 'agency'
            ? 'Client sites change only after an approved quote.'
            : 'Nothing changes without your approval.'}</p>
          {siteCount !== undefined && <p className="ux-sidebar__count"><b>{siteCount}</b> {siteCount === 1 ? 'website' : 'websites'} monitored</p>}
        </div>
      </div>
    </aside>
  );
}

export function Topbar({ email, role, unread, onSignOut, searchPlaceholder, period }: {
  email: string; role: string; unread: number; onSignOut: () => void; searchPlaceholder: string; period?: string;
}) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const ref = useRef<HTMLDivElement>(null);
  const input = useRef<HTMLInputElement>(null);
  // "/" or Ctrl/⌘+K focuses search (not while typing elsewhere).
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement;
      const typing = t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName);
      if ((e.key === 'k' && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !typing)) { e.preventDefault(); input.current?.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);
  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);
  const name = email.split('@')[0] ?? '';
  return (
    <div className="ux-topbar">
      <form className="ux-search" role="search" onSubmit={(e) => { e.preventDefault(); navigate(`/websites${q.trim() ? `?q=${encodeURIComponent(q.trim())}` : ''}`); }}>
        <Search aria-hidden="true" />
        <label className="sr-only" htmlFor="ux-global-search">Search websites</label>
        <input ref={input} id="ux-global-search" type="search" placeholder={searchPlaceholder} value={q} onChange={(e) => setQ(e.target.value)} aria-keyshortcuts="Control+K /" />
        <kbd className="ux-kbd" aria-hidden="true">Ctrl K</kbd>
      </form>
      {period && <span className="ux-period"><CalendarClock aria-hidden="true" />{period}</span>}
      <a className="ux-iconbtn" href="/alerts" onClick={onLinkClick} aria-label={unread ? `Alerts, ${unread} unread` : 'Alerts'}>
        <Bell aria-hidden="true" />{!!unread && <span className="ux-dot" aria-hidden="true">{unread > 99 ? '99+' : unread}</span>}
      </a>
      <div ref={ref} style={{ position: 'relative' }}>
        <button type="button" className="ux-user" aria-expanded={open} aria-haspopup="menu" onClick={() => setOpen(!open)}
          onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
          <span className="ux-avatar" aria-hidden="true">{name.charAt(0).toUpperCase() || '?'}</span>
          <span><span className="ux-user__name">{cap(name) || 'Account'}</span><span className="ux-user__role">{ROLE_LABEL[role] ?? role}</span></span>
          <ChevronDown aria-hidden="true" style={{ width: 16, height: 16, color: 'var(--ink-3)' }} />
        </button>
        {open && (
          <div className="ux-menu" role="menu" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
            <p style={{ margin: '4px 10px 8px', fontSize: 13, color: 'var(--ink-3)', overflowWrap: 'anywhere' }}>{email}</p>
            <a role="menuitem" href="/settings" onClick={(e) => { onLinkClick(e); setOpen(false); }}><Settings aria-hidden="true" width={18} />Settings &amp; security</a>
            <button role="menuitem" type="button" onClick={onSignOut}><LogOut aria-hidden="true" width={18} />Sign out</button>
          </div>
        )}
      </div>
    </div>
  );
}

export function MobileTopbar({ title, subtitle, unread }: { title: string; subtitle: string; unread: number }) {
  return (
    <header className="ux-mtop">
      <BrandMark className="ux-mtop__mark" />
      <div className="ux-mtop__title"><strong>{title}</strong><span>{subtitle}</span></div>
      <a className="ux-iconbtn" href="/alerts" onClick={onLinkClick} aria-label={unread ? `Alerts, ${unread} unread` : 'Alerts'}>
        <Bell aria-hidden="true" />{!!unread && <span className="ux-dot" aria-hidden="true">{unread > 99 ? '99+' : unread}</span>}
      </a>
    </header>
  );
}

export function MobileBottomNav({ primary, secondary, switcher, onSignOut, unread }: {
  primary: ShellNavItem[]; secondary: ShellNavItem[]; switcher: React.ReactNode | null; onSignOut: () => void; unread: number;
}) {
  const [more, setMore] = useState(false);
  const closeRef = useRef<HTMLButtonElement>(null);
  useEffect(() => { if (more) closeRef.current?.focus(); }, [more]);
  return (
    <>
      <nav aria-label="Main" className="ux-bnav">
        {primary.map((n) => {
          const Icon = n.icon;
          return (
            <a key={n.to} href={n.to} aria-current={n.current ? 'page' : undefined} onClick={(e) => { onLinkClick(e); setMore(false); }}>
              <Icon aria-hidden="true" /><span>{n.label}</span>
            </a>
          );
        })}
        <button type="button" aria-expanded={more} aria-controls="more-sheet" onClick={() => setMore(!more)}
          aria-current={secondary.some((s) => s.current) ? 'page' : undefined}>
          <Ellipsis aria-hidden="true" /><span>More</span>
          {!!unread && <span className="ux-dot" aria-label={`${unread} unread alerts`}>{unread > 99 ? '99+' : unread}</span>}
        </button>
      </nav>
      {more && (
        <div className="ux-sheet" onClick={(e) => e.target === e.currentTarget && setMore(false)} onKeyDown={(e) => e.key === 'Escape' && setMore(false)}>
          <div className="ux-sheet__panel" id="more-sheet" role="dialog" aria-modal="true" aria-label="More">
            <div className="ux-sheet__grip" aria-hidden="true" />
            {switcher && <div className="ux-ws ux-ws--sheet" style={{ margin: '0 0 8px' }}>{switcher}</div>}
            <div className="ux-sheet__list">
              {secondary.map((n) => {
                const Icon = n.icon;
                return (
                  <a key={n.to} href={n.to} aria-current={n.current ? 'page' : undefined} onClick={(e) => { onLinkClick(e); setMore(false); }}>
                    <Icon aria-hidden="true" /><span style={{ flex: 1 }}>{n.label}</span>
                    {!!n.badge && <span className="ux-nav__badge">{n.badge}</span>}
                  </a>
                );
              })}
              <button type="button" onClick={onSignOut}><LogOut aria-hidden="true" />Sign out</button>
            </div>
            <button ref={closeRef} type="button" className="ux-btn ux-btn--ghost" style={{ width: '100%', marginTop: 12 }} onClick={() => setMore(false)}>Close</button>
          </div>
        </div>
      )}
    </>
  );
}

export const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

/** AC-UI-12: always visible on a workspace marked as demo by the seed (the API can't set the flag). */
export function DemoBanner() {
  return (
    <div className="ux-demo" role="note">
      <FlaskConical aria-hidden="true" />
      <span><strong>Demonstration data.</strong><span className="ux-desktop-only"> This workspace was filled by the development seed: readings, history and requests are synthetic, not from real websites.</span><span className="ux-mobile-only"> Synthetic readings from the development seed.</span></span>
    </div>
  );
}
