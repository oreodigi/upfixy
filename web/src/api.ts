// Thin client for the Upfixy API (/api/v1, proxied by Vite in development).
import type { HealthLane, TicketState } from '@upfixy/core';

export interface ApiCheck {
  target_id: string;
  host: string;
  source: 'public' | 'connector' | 'user';
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
  hosts: string[];
  open_requests: number;
  verified_hosts: string[];
}
export interface ApiClient {
  id: string;
  name: string;
  lane: HealthLane;
  website_count: number;
  impacted_count: number;
  last_verified_at: string | null;
  websites: ApiSite[];
  open_requests: number;
  staff: Array<{ user_id: string; email: string | null }>;
}
export interface ApiTicket {
  id: string;
  title: string;
  state: TicketState;
  version: number;
  website: string;
  website_id: string;
  assignee_id: string | null;
  assignee_email: string | null;
  client: string | null;
  client_id: string | null;
  has_quote: boolean;
  approved: boolean;
}
export interface ApiWorkspace { id: string; name: string; kind: 'solo' | 'agency'; role: string; closed_at?: string | null; is_demo?: boolean }

export class ApiError extends Error {
  constructor(public status: number, message: string, public body: unknown, public code?: string) { super(message); }
}

/**
 * Step-up MFA: when the API answers 403 mfa_step_up_required, the app asks for
 * a code (StepUpDialog), then the original request is retried once.
 */
type StepUpHandler = () => Promise<boolean>;
let stepUp: StepUpHandler | null = null;
export const setStepUpHandler = (h: StepUpHandler | null) => { stepUp = h; };

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
  /** Last workspace list (names and roles only) so pages render before it reloads.
   *  A UI hint, never authority: every request is re-authorised by the API. */
  workspaces: (): ApiWorkspace[] => { try { return JSON.parse(store.get('upfixy.workspaces') ?? '[]'); } catch { return []; } },
  setWorkspaces: (w: ApiWorkspace[] | null) => store.set('upfixy.workspaces', w ? JSON.stringify(w) : null),
};

export async function api<T>(method: string, path: string, body?: unknown, opts: { workspace?: boolean; retried?: boolean } = {}): Promise<T> {
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
    if (res.status === 401) { session.setToken(null); session.setWorkspaces(null); }
    if (res.status === 403 && json?.code === 'mfa_step_up_required' && stepUp && !opts.retried) {
      if (await stepUp()) return api<T>(method, path, body, { ...opts, retried: true });
    }
    const firstError = json?.errors ? (Object.values(json.errors)[0] as string[])[0] : null;
    throw new ApiError(res.status, firstError ?? json?.message ?? `Request failed (${res.status})`, json, json?.code);
  }
  return json as T;
}

// ----------------------------------------------------------- M1 hardening types
export interface ApiMe { id: string; email: string; email_verified: boolean; mfa_enabled: boolean; mfa_fresh: boolean }
export interface ApiMember { user_id: string; email: string; role: string; suspended_at: string | null; email_verified: boolean; mfa_enabled: boolean }
export interface ApiInvitation { id: string; email: string; role: string; client_id: string | null; expires_at: string; accepted_at: string | null; revoked_at: string | null }
export interface ApiClaim {
  id: string; host: string; method: 'dns_txt' | 'html_file';
  status: 'challenge_issued' | 'verified' | 'expired' | 'revoked' | 'unverified';
  instructions: { type: 'TXT'; name: string; value: string } | { url: string; contents: string } | null;
  challenge_expires_at: string; verified_at: string | null; proof_expires_at: string | null;
  last_checked_at: string | null; last_check_result: string | null;
}
export interface ApiTarget { id: string; url: string; host: string; state: string }
export interface ApiQuote {
  id: string; ticket_id: string; action: { code: string; label: string }; target: { id: string; host: string; url: string };
  description: string; amount_paise: number; currency: string; expires_at: string; fingerprint: string;
  ownership_verified: boolean; approved: boolean; payment_required: boolean;
  params?: Record<string, unknown> & { operation?: string }; approval_id?: string | null;
}
export interface ApiTicketDetail {
  id: string; title: string; state: TicketState; version: number; website_id: string;
  assignee_id: string | null; assignee_email: string | null; allowed_next: TicketState[]; quote: ApiQuote | null;
}
export interface ApiAction { code: string; label: string; tier: string; price_paise: number | null }

export const rupees = (paise: number) => (paise / 100).toLocaleString('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 });

// ----------------------------------------------------------------- M2 types
export interface ApiOverview {
  websites: { total: number; by_lane: Record<HealthLane, number> };
  issues: Array<ApiCheck & { website_id: string; website: string; client_id: string | null }>;
  requests: {
    by_state: Partial<Record<TicketState, number>>;
    assigned_to_me: Array<{ id: string; title: string; state: TicketState; website: string }>;
    awaiting_quote_decision: Array<{ id: string; title: string; website: string }>;
  };
}
export interface ApiWebsiteDetail {
  id: string; display_name: string; client_id: string | null; client_name: string | null; created_at: string;
  targets: Array<ApiTarget & { interval_seconds: number }>;
  lane: HealthLane; last_verified_at: string | null; checks: ApiCheck[];
  coverage: Record<string, string> | null; verified_hosts: string[];
  requests: Array<{ id: string; title: string; state: TicketState; updated_at: string }>;
}
export interface ApiHistoryRow {
  id: string; observed_at: string; host: string; kind: ApiCheck['kind']; severity: ApiCheck['severity']; source: ApiCheck['source'];
  result_code: string; region: string | null; cycle_no: number; detail: Record<string, unknown> | null;
}
export interface ApiClientDetail extends ApiClient {
  agency_may_pay: boolean; client_self_service: boolean;
  client_users: Array<{ user_id: string; email: string | null; role: string }>;
}
export interface ApiSavedView { id: string; board: 'clients' | 'websites' | 'work'; name: string; filters: import('./filters').Filters }
export interface ApiStaff { user_id: string; email: string; role: string }

// ----------------------------------------------------------------- M3 types
export interface ApiIncident {
  id: string; website_id: string; website: string; host: string; status: 'open' | 'resolved'; root_kind: ApiCheck['kind'];
  result_code: string; title: string; opened_at: string; first_failure_at: string; last_failure_at: string;
  resolved_at: string | null; acknowledged_at: string | null; client_id: string | null;
  events?: Array<{ kind: string; result_code: string | null; at: string }>;
}
export interface ApiNotification {
  id: string; kind: 'incident_opened' | 'incident_resolved'; created_at: string; read_at: string | null;
  incident_id: string; result_code: string; status: 'open' | 'resolved'; website_id: string; website: string; title: string;
}
export interface ApiPrefs {
  workspace: { email: boolean; in_app: boolean };
  clients: Array<{ client_id: string; email: boolean; in_app: boolean }>;
}

// ----------------------------------------------------------------- M5 types
export type Confidence = 'confirmed' | 'likely' | 'possible' | 'unknown';
export interface ApiFinding {
  key: string; observed_fact: string; evidence_ids: string[]; hypothesis: string | null; supporting_evidence_ids: string[];
  contradicting_evidence_ids: string[]; confidence: Confidence; responsibility: string;
  recommended_actions: Array<{ kind: string; text: string }>; human_review_required: boolean;
}
export interface ApiAiFinding {
  finding_id: string; evidence_ids: string[]; observed_fact: string;
  hypotheses: Array<{ text: string; supporting_evidence_ids: string[]; contradicting_evidence_ids: string[]; confidence: Confidence }>;
  recommended_actions: Array<{ text: string; kind: string; operation_id: string | null }>; unknowns: string[]; human_review_required: boolean;
}
export interface ApiDiagnosis {
  id: string; website_id: string; website: string; status: string; created_at: string; completed_at: string | null;
  observations: ApiFinding[]; hypotheses: ApiFinding[];
  missing: Array<{ key: string; detail: string; next_step: string; host?: string }>;
  skipped: Array<{ check: string; label: string; reason: string }>;
  human_review_required: boolean;
  evidence: Array<{ evidence_id: string; host: string; kind: string; result_code: string; severity: string; observed_at: string; freshness: string; source: string; facts?: Record<string, unknown> }>;
  ai: { status: 'pending' | 'succeeded' | 'rejected' | 'unavailable' | 'skipped' | 'budget_exceeded'; message?: string; summary?: string;
        findings?: ApiAiFinding[]; missing_information?: string[];
        audit?: { model: string | null; prompt_version: string; schema_version: string; rejection_reasons: string[]; latency_ms: number | null } };
  rule_version?: string;
}

// ----------------------------------------------------------------- M7 types
export interface RepairPlan {
  version: string; operation: string; connection_id: string; zone_id: string; zone_name: string; record_id: string;
  record_name: string; record_type: 'A' | 'AAAA';
  before: { content: string; ttl: number; proxied: boolean }; after: { content: string; ttl: number; proxied: boolean };
  verification: { resolve: string; expect: string; http_target_id: string; http_was_ok: boolean }; rollback: string;
}
export interface ApiDnsConnection { id: string; provider: string; zone_id: string; zone_name: string; secret_version: number; created_at: string; verified_at: string | null; verify_result: string | null; revoked_at: string | null }
export interface ApiRepairDraft { id: string; state: 'reading' | 'ready' | 'refused'; refusal: string | null; plan: RepairPlan | null; created_at: string }
export type RepairState = 'queued' | 'preflight' | 'executing' | 'verifying' | 'resolved' | 'rolled_back' | 'needs_technician' | 'refused';
export interface ApiRepairAttempt { id: string; state: RepairState; customer_message: string | null; state_reason?: string; plan?: RepairPlan; created_at: string; updated_at: string;
  events?: Array<{ kind: string; at: string; detail?: Record<string, unknown> }> }
export interface ApiRepairControls { platform_enabled: boolean; global: { paused: boolean; reason: string | null }; workspace: { paused: boolean; reason: string | null; changed_at: string | null } }

// ----------------------------------------------------------------- M4 types
export type ConnectorKind = 'cloudflare' | 'whm_reseller' | 'cpanel';
export interface ApiConnector {
  id: string; kind: ConnectorKind; label: string; host: string | null; username: string | null;
  status: 'verifying' | 'active' | 'rejected' | 'error' | 'revoked'; status_reason: string | null;
  declared_capabilities: string[]; verified_capabilities: string[]; scope: Record<string, unknown> | null; warnings: string[];
  secret_version: number; last_success_at: string | null; last_attempt_at: string | null; next_sync_at: string; error_class: string | null;
  created_at: string; revoked_at: string | null; stale: boolean;
}
export interface ApiConnectorAsset {
  id: string; kind: 'zone' | 'account' | 'domain'; external_id: string; name: string; parent_external_id: string | null;
  facts: Record<string, unknown>; last_seen_at: string; gone_at: string | null;
  import_state: 'discovered' | 'imported' | 'ignored' | 'needs_review'; review_reason: string | null; website_id: string | null;
  match_website_id: string | null; match_website: string | null; match_client_id: string | null; match_client: string | null;
}
export interface ApiProviderLink {
  asset_id: string; provider: ConnectorKind; asset_kind: 'zone' | 'account' | 'domain'; name: string;
  facts: Record<string, any> | null; private_access: boolean; last_seen_at: string; stale: boolean; stale_reason: string | null;
}

// ----------------------------------------------------------------- M6 types
export type PaymentStatus = 'created' | 'pending' | 'paid_verified' | 'failed' | 'refunded' | 'partially_refunded';
export interface ApiPaymentOrder {
  id: string; quote_id: string; approval_id: string; amount_paise: number; currency: string; mode: 'test' | 'live'; merchant_name: string;
  status: PaymentStatus; status_reason: string | null; client_reported_at: string | null; refunded_paise: number; created_at: string; paid_at: string | null;
  receipt: { id: string; number: string; issued_at: string; is_tax_invoice: boolean; tax_note: string } | null;
  refunds: Array<{ id: string; amount_paise: number; reason: string; status: string; requested_at: string; processed_at: string | null }>;
}
export interface ApiMerchant {
  merchant: { legal_name: string; gstin: string | null; state_code: string | null; receipt_prefix: string; mode: 'test' | 'live'; razorpay_key_id: string; updated_at: string } | null;
  live_allowed: boolean; live_note: string; webhook_url: string;
}
export interface ApiCoverage {
  id: string; client_id: string | null; client: string | null; website_id: string | null; website: string | null; reference: string; scope: string;
  valid_from: string; valid_until: string; revoked_at: string | null; active: boolean;
}

// ----------------------------------------------------------------- UI v1: board metrics (read-only, RLS-scoped)
export interface ApiMetricPoint { hour: string; checks: number; availability_pct: number | null; avg_ms: number | null }
export interface ApiSiteMetrics {
  http_checks: number; availability_pct: number | null; avg_ms: number | null; last_ms: number | null;
  tls_days_left: number | null; tls_not_after: string | null; ms_series: Array<number | null>;
}
export interface ApiActivity {
  kind: string; at: string; website: string; website_id: string; detail: string | null; ticket_id: string | null;
}
export interface ApiMetrics {
  window_hours: number; generated_at: string; series: ApiMetricPoint[];
  websites: Record<string, ApiSiteMetrics>; activity: ApiActivity[];
}
