// Health computation. FRD refs: MON-007, BRD-001/002/005, §15.2, D-17, AC-MON-007.1,
// AC-BRD-001.1, AC-BRD-005.1. Missing or stale data is Unknown, never Healthy.

export type Severity = 'ok' | 'warning' | 'critical' | 'unknown' | 'not_applicable';
export type Freshness = 'fresh' | 'stale' | 'never_checked';
export type HealthLane = 'critical' | 'warning' | 'healthy' | 'unknown' | 'needs_setup';
export type CheckKind = 'http' | 'tls' | 'dns';

export const REQUIRED_PUBLIC_CHECKS: readonly CheckKind[] = ['http', 'tls', 'dns'];

/** A reading is stale once it is older than 3× the check interval (AC-MON-007.1). */
export const STALE_MULTIPLIER = 3;

export function freshnessOf(observedAt: Date | null, intervalSeconds: number, now: Date): Freshness {
  if (!observedAt) return 'never_checked';
  const ageMs = now.getTime() - observedAt.getTime();
  return ageMs <= STALE_MULTIPLIER * intervalSeconds * 1000 ? 'fresh' : 'stale';
}

export interface CheckReading {
  kind: CheckKind;
  severity: Severity;
  freshness: Freshness;
  observedAt: Date | null;
}

export interface SiteHealthInput {
  monitorActive: boolean;          // false while pending_validation / draft
  readings: CheckReading[];        // latest reading per check kind
  required?: readonly CheckKind[];
}

export interface SiteHealth {
  lane: HealthLane;
  /** Checks that drove the lane, for the card's "why" line. */
  reasons: CheckKind[];
  /** Oldest fresh observation among required checks; shown as "last verified". */
  lastVerifiedAt: Date | null;
}

/** §15.2 table, in precedence order. */
export function siteHealth({ monitorActive, readings, required = REQUIRED_PUBLIC_CHECKS }: SiteHealthInput): SiteHealth {
  const byKind = new Map(readings.map((r) => [r.kind, r]));
  const fresh = readings.filter((r) => r.freshness === 'fresh');

  const critical = fresh.filter((r) => r.severity === 'critical');
  if (critical.length) return { lane: 'critical', reasons: critical.map((r) => r.kind), lastVerifiedAt: oldest(fresh) };

  const warning = fresh.filter((r) => r.severity === 'warning');
  if (warning.length) return { lane: 'warning', reasons: warning.map((r) => r.kind), lastVerifiedAt: oldest(fresh) };

  if (!monitorActive && readings.every((r) => r.freshness === 'never_checked')) {
    return { lane: 'needs_setup', reasons: [], lastVerifiedAt: null };
  }

  const notOk = required.filter((k) => {
    const r = byKind.get(k);
    return !r || r.freshness !== 'fresh' || (r.severity !== 'ok' && r.severity !== 'not_applicable');
  });
  if (notOk.length) return { lane: 'unknown', reasons: notOk, lastVerifiedAt: oldest(fresh) };

  return { lane: 'healthy', reasons: [], lastVerifiedAt: oldest(fresh) };
}

function oldest(rs: CheckReading[]): Date | null {
  const ts = rs.map((r) => r.observedAt).filter((d): d is Date => !!d);
  return ts.length ? new Date(Math.min(...ts.map((d) => d.getTime()))) : null;
}

/**
 * Client roll-up (§15.2, D-17): any critical → Critical; else any warning →
 * Warning; else any unknown/stale (or a mix including needs-setup) → Unknown;
 * all needs-setup → Needs setup; only all healthy → Healthy.
 */
export function rollUp(lanes: HealthLane[]): HealthLane {
  if (lanes.length === 0) return 'needs_setup';
  if (lanes.includes('critical')) return 'critical';
  if (lanes.includes('warning')) return 'warning';
  if (lanes.every((l) => l === 'needs_setup')) return 'needs_setup';
  if (lanes.every((l) => l === 'healthy')) return 'healthy';
  return 'unknown';
}

// --------------------------------------------- component badges (BRD-005)
export type ComponentState = 'ok' | 'warning' | 'critical' | 'unknown' | 'not_connected';

export interface BackupSignal {
  connected: boolean;
  jobReportedComplete?: boolean;
  restoreVerified?: boolean;
  failed?: boolean;
}

/** Backup is never "healthy" without a connector, and never "verified" without restore proof. */
export function backupBadge(b: BackupSignal): { state: ComponentState; label: string } {
  if (!b.connected) return { state: 'not_connected', label: 'Backup: Not connected' };
  if (b.failed) return { state: 'critical', label: 'Backup: Last job failed' };
  if (b.restoreVerified) return { state: 'ok', label: 'Backup: Restore verified' };
  if (b.jobReportedComplete) return { state: 'warning', label: 'Backup job reported complete; restore not verified' };
  return { state: 'unknown', label: 'Backup: Unknown' };
}

/** Public header/TLS checks alone never justify "Secure" (§15.2). */
export function securityBadge(onlyPublicChecks: boolean, allPassed: boolean): { state: ComponentState; label: string } {
  if (!allPassed) return { state: 'warning', label: 'Security: Issues found' };
  return onlyPublicChecks
    ? { state: 'ok', label: 'Basic web checks passed' }
    : { state: 'ok', label: 'Security checks passed (see coverage)' };
}
