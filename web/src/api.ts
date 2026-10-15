// Thin client for the Upfixy API (/api/v1, proxied by Vite in development).
import type { HealthLane, TicketState } from '@upfixy/core';

export interface ApiCheck {
  target_id: string;
  kind: 'http' | 'tls' | 'dns';
  severity: 'ok' | 'warning' | 'critical' | 'unknown' | 'not_applicable';
  freshness: 'fresh' | 'stale' | 'never_checked';
  result_code: string;
  observed_at: string;
}
export interface ApiSite {
  id: string;
  client_id: string | null;
  display_name: string;
  lane: HealthLane;
  last_verified_at: string | null;
  checks: ApiCheck[];
  coverage: Record<string, string>;
}
export interface ApiClient {
  id: string;
  name: string;
  lane: HealthLane;
  website_count: number;
  impacted_count: number;
  last_verified_at: string | null;
  websites: ApiSite[];
}
export interface ApiTicket {
  id: string;
  title: string;
  state: TicketState;
  version: number;
  website: string;
  website_id: string;
  assignee_id: string | null;
}
export interface ApiWorkspace { id: string; name: string; kind: 'solo' | 'agency'; role: string }

export class ApiError extends Error {
  constructor(public status: number, message: string, public body: unknown) { super(message); }
}

const store = {
  get: (k: string) => { try { return localStorage.getItem(k); } catch { return null; } },
  set: (k: string, v: string | null) => {
    try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch { /* storage unavailable */ }
  },
};

export const session = {
  token: () => store.get('upfixy.token'),
  workspace: () => store.get('upfixy.workspace'),
  setToken: (t: string | null) => store.set('upfixy.token', t),
  setWorkspace: (w: string | null) => store.set('upfixy.workspace', w),
};

export async function api<T>(method: string, path: string, body?: unknown, opts: { workspace?: boolean } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const token = session.token();
  if (token) headers.Authorization = `Bearer ${token}`;
  const ws = session.workspace();
  if (opts.workspace !== false && ws) headers['X-Workspace-Id'] = ws;

  const res = await fetch(`/api/v1/${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  const text = await res.text();
  const json = text ? JSON.parse(text) : null;
  if (!res.ok) {
    if (res.status === 401) session.setToken(null);
    const firstError = json?.errors ? (Object.values(json.errors)[0] as string[])[0] : null;
    throw new ApiError(res.status, firstError ?? json?.message ?? `Request failed (${res.status})`, json);
  }
  return json as T;
}
