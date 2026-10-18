// Minimal path router: real URLs (shareable, back button works), no dependency.
import { useEffect, useState } from 'react';

export type Route =
  | { name: 'overview' }
  | { name: 'clients' }
  | { name: 'client'; id: string }
  | { name: 'websites' }
  | { name: 'website'; id: string; tab?: string }
  | { name: 'work'; ticket?: string }
  | { name: 'team' }
  | { name: 'settings' }
  | { name: 'alerts' }
  | { name: 'diagnostic'; id: string }
  | { name: 'not_found' };

const UUID = '[0-9a-f-]{36}';

export function parse(path: string, search = ''): Route {
  const p = path.replace(/\/+$/, '') || '/';
  const q = new URLSearchParams(search);
  let m: RegExpMatchArray | null;
  if (p === '/' || p === '/overview') return { name: 'overview' };
  if (p === '/clients') return { name: 'clients' };
  if ((m = p.match(new RegExp(`^/clients/(${UUID})$`)))) return { name: 'client', id: m[1] };
  if (p === '/websites') return { name: 'websites' };
  if ((m = p.match(new RegExp(`^/websites/(${UUID})(?:/([a-z]+))?$`)))) return { name: 'website', id: m[1], tab: m[2] };
  if (p === '/work') return { name: 'work', ticket: q.get('ticket') ?? undefined };
  if (p === '/team') return { name: 'team' };
  if (p === '/alerts') return { name: 'alerts' };
  if ((m = p.match(new RegExp(`^/diagnostics/(${UUID})$`)))) return { name: 'diagnostic', id: m[1] };
  if (p === '/settings' || p === '/security') return { name: 'settings' };
  return { name: 'not_found' };
}

export function href(r: Route): string {
  switch (r.name) {
    case 'overview': return '/';
    case 'client': return `/clients/${r.id}`;
    case 'diagnostic': return `/diagnostics/${r.id}`;
    case 'website': return `/websites/${r.id}${r.tab && r.tab !== 'overview' ? `/${r.tab}` : ''}`;
    case 'work': return r.ticket ? `/work?ticket=${r.ticket}` : '/work';
    case 'not_found': return '/';
    default: return `/${r.name}`;
  }
}

const listeners = new Set<() => void>();
export function navigate(to: string | Route, opts: { replace?: boolean } = {}) {
  const url = typeof to === 'string' ? to : href(to);
  if (url === window.location.pathname + window.location.search) return;
  window.history[opts.replace ? 'replaceState' : 'pushState'](null, '', url);
  listeners.forEach((l) => l());
  if (!opts.replace) window.scrollTo(0, 0);
}

export function useRoute(): Route {
  const read = () => parse(window.location.pathname, window.location.search);
  const [route, setRoute] = useState<Route>(read);
  useEffect(() => {
    const update = () => setRoute(read());
    listeners.add(update);
    window.addEventListener('popstate', update);
    return () => { listeners.delete(update); window.removeEventListener('popstate', update); };
  }, []);
  return route;
}

/** <a> that navigates in-app but still works with middle-click / open in new tab. */
export function onLinkClick(e: React.MouseEvent<HTMLAnchorElement>) {
  if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  e.preventDefault();
  navigate(e.currentTarget.getAttribute('href') ?? '/');
}
