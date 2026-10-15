import { it, expect } from 'vitest';
import { toSite } from './live-mapping';
import type { ApiSite } from './api';

const base: ApiSite = { id: 's', client_id: null, display_name: 'shop.example', lane: 'unknown', last_verified_at: null, checks: [], coverage: {} };
const check = (kind: 'http' | 'tls' | 'dns', severity: 'ok' | 'warning' | 'critical', freshness: 'fresh' | 'stale', target = 't1') =>
  ({ target_id: target, kind, severity, freshness, result_code: `${kind}_${severity}`, observed_at: '2026-10-16T04:00:00Z' });

it('never shows a stale OK reading as OK', () => {
  const s = toSite({ ...base, checks: [check('tls', 'ok', 'stale')] });
  expect(s.components.ssl).toEqual({ state: 'unknown', label: 'Stale reading' });
  expect(s.components.domain.state).toBe('unknown'); // no DNS reading at all
});

it('shows the worst fresh reading when apex and www both report', () => {
  const s = toSite({ ...base, checks: [check('http', 'ok', 'fresh', 'apex'), check('http', 'critical', 'fresh', 'www')] });
  expect(s.components.errors.state).toBe('critical');
  expect(s.issues).toHaveLength(1);
});

it('keeps hosting and backup as not connected, never ok', () => {
  const s = toSite({ ...base, lane: 'healthy', checks: [check('http', 'ok', 'fresh'), check('tls', 'ok', 'fresh'), check('dns', 'ok', 'fresh')] });
  expect(s.components.hosting.state).toBe('not_connected');
  expect(s.components.backup.state).toBe('not_connected');
});
