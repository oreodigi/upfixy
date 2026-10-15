// Incident debounce. FRD refs: MON-005, MON-008, §17.1, AC-MON-005.1, AC-MON-005.3, AC-MON-008.1.
// Input is one result per scheduled CYCLE (retries inside a cycle are already
// collapsed upstream), so retries can never count as independent failures.

export const OPEN_AFTER_FAILED_CYCLES = 3;
export const RESOLVE_AFTER_OK_CYCLES = 2;

export type CycleOutcome = 'ok' | 'fail' | 'probe_error';

export interface IncidentState {
  open: boolean;
  consecutiveFails: number;
  consecutiveOks: number;
  openedAt: Date | null;
  lastFailureAt: Date | null;
  resolvedAt: Date | null;
}

export const initialIncidentState = (): IncidentState => ({
  open: false, consecutiveFails: 0, consecutiveOks: 0, openedAt: null, lastFailureAt: null, resolvedAt: null,
});

/**
 * probe_error (our own infrastructure failed) neither counts toward opening
 * nor toward recovery: a broken probe must not mark a customer site down (§17.1).
 */
export function nextIncidentState(s: IncidentState, outcome: CycleOutcome, at: Date): IncidentState {
  if (outcome === 'probe_error') return s;
  if (outcome === 'fail') {
    const fails = s.consecutiveFails + 1;
    const opening = !s.open && fails >= OPEN_AFTER_FAILED_CYCLES;
    return {
      ...s,
      consecutiveFails: fails,
      consecutiveOks: 0,
      lastFailureAt: at,
      open: s.open || opening,
      openedAt: opening ? at : s.openedAt,
      resolvedAt: opening ? null : s.resolvedAt,
    };
  }
  const oks = s.consecutiveOks + 1;
  const resolving = s.open && oks >= RESOLVE_AFTER_OK_CYCLES;
  return {
    ...s,
    consecutiveFails: 0,
    consecutiveOks: oks,
    open: s.open && !resolving,
    resolvedAt: resolving ? at : s.resolvedAt,
  };
}
