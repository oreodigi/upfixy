import { describe, it, expect } from 'vitest';
import { siteHealth, rollUp, freshnessOf, backupBadge, securityBadge, type CheckReading } from '../src/health';

const now = new Date('2026-10-16T10:00:00Z');
const r = (kind: CheckReading['kind'], severity: CheckReading['severity'], freshness: CheckReading['freshness'] = 'fresh'): CheckReading =>
  ({ kind, severity, freshness, observedAt: freshness === 'never_checked' ? null : now });
const allOk = [r('http', 'ok'), r('tls', 'ok'), r('dns', 'ok')];

describe('freshnessOf', () => {
  it('AC-MON-007.1: stale after 3× the interval', () => {
    expect(freshnessOf(new Date(now.getTime() - 15 * 60_000), 300, now)).toBe('fresh');
    expect(freshnessOf(new Date(now.getTime() - 15 * 60_000 - 1), 300, now)).toBe('stale');
    expect(freshnessOf(null, 300, now)).toBe('never_checked');
  });
});

describe('siteHealth (§15.2)', () => {
  it('all required checks fresh and OK → healthy', () => {
    expect(siteHealth({ monitorActive: true, readings: allOk }).lane).toBe('healthy');
  });

  it('fresh critical wins', () => {
    const h = siteHealth({ monitorActive: true, readings: [r('http', 'critical'), r('tls', 'warning'), r('dns', 'ok')] });
    expect(h).toMatchObject({ lane: 'critical', reasons: ['http'] });
  });

  it('a stale critical is Unknown, not Critical', () => {
    expect(siteHealth({ monitorActive: true, readings: [r('http', 'critical', 'stale'), r('tls', 'ok'), r('dns', 'ok')] }).lane).toBe('unknown');
  });

  it('AC-MON-007.1: one stale required reading makes the site Unknown, never Healthy', () => {
    expect(siteHealth({ monitorActive: true, readings: [r('http', 'ok', 'stale'), r('tls', 'ok'), r('dns', 'ok')] }).lane).toBe('unknown');
  });

  it('a missing required check makes the site Unknown', () => {
    expect(siteHealth({ monitorActive: true, readings: [r('http', 'ok'), r('tls', 'ok')] })).toMatchObject({ lane: 'unknown', reasons: ['dns'] });
  });

  it('never-checked, inactive monitor → Needs setup', () => {
    expect(siteHealth({ monitorActive: false, readings: [] }).lane).toBe('needs_setup');
  });

  it('active but awaiting first checks → Unknown', () => {
    expect(siteHealth({ monitorActive: true, readings: [] }).lane).toBe('unknown');
  });
});

describe('rollUp (client card)', () => {
  it('AC-BRD-001.1: one critical among healthy sites → Critical', () => {
    expect(rollUp(['healthy', 'critical', 'healthy'])).toBe('critical');
  });
  it('D-17: healthy + unknown → Unknown, never Healthy', () => {
    expect(rollUp(['healthy', 'unknown'])).toBe('unknown');
  });
  it('healthy + needs setup → Unknown', () => {
    expect(rollUp(['healthy', 'needs_setup'])).toBe('unknown');
  });
  it('all needs setup or no sites → Needs setup', () => {
    expect(rollUp(['needs_setup'])).toBe('needs_setup');
    expect(rollUp([])).toBe('needs_setup');
  });
  it('warning beats unknown', () => {
    expect(rollUp(['unknown', 'warning'])).toBe('warning');
  });
});

describe('badges', () => {
  it('AC-BRD-005.1: no backup connector → Not connected, never ok', () => {
    expect(backupBadge({ connected: false })).toEqual({ state: 'not_connected', label: 'Backup: Not connected' });
  });
  it('job reported without restore proof is not shown as verified', () => {
    expect(backupBadge({ connected: true, jobReportedComplete: true }).state).toBe('warning');
  });
  it('public-only security checks never say "Secure"', () => {
    expect(securityBadge(true, true).label).toBe('Basic web checks passed');
  });
});
