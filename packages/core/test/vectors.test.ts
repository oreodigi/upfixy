// Runs the shared vectors that the PHP API tests also run (api/tests/Unit/SharedVectorsTest.php).
import { describe, it, expect } from 'vitest';
import url from '../vectors/url-guard.json';
import health from '../vectors/health.json';
import incidents from '../vectors/incidents.json';
import { normalizeTarget, isBlockedAddress, siteHealth, rollUp, initialIncidentState, nextIncidentState, type CheckReading, type HealthLane, type CycleOutcome } from '../src';

describe('shared url-guard vectors', () => {
  it.each(url.normalize.map((v) => [v.input.slice(0, 60), v] as const))('%s', (_n, v) => {
    const r = normalizeTarget(v.input);
    if (v.code === 'ok') expect(r).toMatchObject({ ok: true, target: v.target });
    else expect(r.ok ? 'ok' : r.code).toBe(v.code);
  });
  it.each(url.blocked)('blocks %s', (a) => expect(isBlockedAddress(a)).toBe(true));
  it.each(url.allowed)('allows %s', (a) => expect(isBlockedAddress(a)).toBe(false));
});

describe('shared health vectors', () => {
  it.each(health.site.map((v) => [v.name, v] as const))('%s', (_n, v) => {
    const readings = v.readings.map((r) => ({ ...r, observedAt: null })) as CheckReading[];
    expect(siteHealth({ monitorActive: v.monitorActive, readings }).lane).toBe(v.lane);
  });
  it.each(health.rollUp.map((v) => [v.lanes.join('+') || '(none)', v] as const))('rollUp %s', (_n, v) => {
    expect(rollUp(v.lanes as HealthLane[])).toBe(v.lane);
  });
});

describe('shared incident vectors', () => {
  it.each(incidents.cases.map((v) => [v.name, v] as const))('%s', (_n, v) => {
    const at = (cycle: number) => new Date(Date.UTC(2026, 9, 18, 0, cycle));
    let s = initialIncidentState();
    v.outcomes.forEach((o, i) => { s = nextIncidentState(s, o as CycleOutcome, at(i + 1)); });
    expect(s.open).toBe(v.open);
    expect(s.openedAt?.getTime() ?? null).toBe(v.openedAtCycle === null ? null : at(v.openedAtCycle).getTime());
    expect(s.resolvedAt?.getTime() ?? null).toBe(v.resolvedAtCycle === null ? null : at(v.resolvedAtCycle).getTime());
  });
});
