// Runs the shared vectors that the PHP API tests also run (api/tests/Unit/SharedVectorsTest.php).
import { describe, it, expect } from 'vitest';
import url from '../vectors/url-guard.json';
import health from '../vectors/health.json';
import { normalizeTarget, isBlockedAddress, siteHealth, rollUp, type CheckReading, type HealthLane } from '../src';

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
