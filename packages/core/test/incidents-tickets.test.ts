import { describe, it, expect } from 'vitest';
import { initialIncidentState, nextIncidentState, type CycleOutcome } from '../src/incidents';
import { canTransition, TRANSITIONS, TICKET_STATES } from '../src/tickets';

const run = (outcomes: CycleOutcome[]) => {
  let s = initialIncidentState();
  outcomes.forEach((o, i) => { s = nextIncidentState(s, o, new Date(Date.UTC(2026, 9, 16, 10, i * 5))); });
  return s;
};

describe('incident debounce', () => {
  it('AC-MON-005.1: one failed cycle then success opens nothing', () => {
    expect(run(['fail', 'ok']).open).toBe(false);
  });
  it('AC-MON-005.1: three consecutive failed cycles open an incident', () => {
    expect(run(['fail', 'fail', 'fail']).open).toBe(true);
  });
  it('non-consecutive failures do not open', () => {
    expect(run(['fail', 'fail', 'ok', 'fail', 'fail']).open).toBe(false);
  });
  it('probe infrastructure errors neither open nor break a failure streak', () => {
    expect(run(['probe_error', 'probe_error', 'probe_error']).open).toBe(false);
    expect(run(['fail', 'probe_error', 'fail', 'fail']).open).toBe(true);
  });
  it('AC-MON-008.1: resolves after 2 OK cycles with exact timestamps', () => {
    const s = run(['fail', 'fail', 'fail', 'ok', 'ok']);
    expect(s.open).toBe(false);
    expect(s.lastFailureAt?.toISOString()).toBe('2026-10-16T10:10:00.000Z');
    expect(s.resolvedAt?.toISOString()).toBe('2026-10-16T10:20:00.000Z');
  });
  it('one OK cycle does not resolve', () => {
    expect(run(['fail', 'fail', 'fail', 'ok']).open).toBe(true);
  });
});

describe('ticket transitions (§18.2)', () => {
  const future = new Date(Date.now() + 86_400_000);
  const past = new Date(Date.now() - 1000);

  it('AC-BRD-003.2: Investigating → Ready for work is not allowed', () => {
    expect(canTransition('investigating', 'ready_for_work').ok).toBe(false);
  });
  it('AC-SVC-003.3: Quote pending → Ready for work needs approval, even at ₹0', () => {
    expect(canTransition('quote_pending', 'ready_for_work').ok).toBe(false);
    expect(canTransition('quote_pending', 'ready_for_work', {
      approvals: [{ amountPaise: 0, expiresAt: future, voided: false, paymentVerified: false }],
    }).ok).toBe(true);
  });
  it('AC-SVC-003.2: expired or voided approval does not count', () => {
    for (const a of [{ expiresAt: past, voided: false }, { expiresAt: future, voided: true }]) {
      expect(canTransition('quote_pending', 'ready_for_work', {
        approvals: [{ amountPaise: 0, paymentVerified: false, ...a }],
      }).ok).toBe(false);
    }
  });
  it('paid approval needs verified payment', () => {
    const a = { amountPaise: 10000, expiresAt: future, voided: false };
    expect(canTransition('quote_pending', 'ready_for_work', { approvals: [{ ...a, paymentVerified: false }] }).ok).toBe(false);
    expect(canTransition('quote_pending', 'ready_for_work', { approvals: [{ ...a, paymentVerified: true }] }).ok).toBe(true);
  });
  it('AC-BRD-003.1: Resolved requires passed verification', () => {
    expect(canTransition('verifying', 'resolved').ok).toBe(false);
    expect(canTransition('verifying', 'resolved', { verificationPassed: true }).ok).toBe(true);
  });
  it('every state has at least one exit and only canonical targets', () => {
    for (const s of TICKET_STATES) {
      expect(TRANSITIONS[s].length).toBeGreaterThan(0);
      for (const t of TRANSITIONS[s]) expect(TICKET_STATES).toContain(t);
    }
  });
});
