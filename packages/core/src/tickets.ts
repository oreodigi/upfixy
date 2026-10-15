// Ticket workflow, mirroring the database guard in db/migrations/0004_rls_policies.sql.
// FRD refs: BRD-003, §15.1 canonical lanes, §18.2 (FIX-A), AC-BRD-003.1/.2, AC-SVC-003.2/.3.
// The UI uses this to offer only legal moves; the database is the authority.

export const TICKET_STATES = [
  'new', 'investigating', 'waiting_for_customer', 'quote_pending',
  'ready_for_work', 'in_progress', 'verifying', 'resolved', 'closed',
] as const;
export type TicketState = (typeof TICKET_STATES)[number];

export const TICKET_LABELS: Record<TicketState, string> = {
  new: 'New',
  investigating: 'Investigating',
  waiting_for_customer: 'Waiting for customer',
  quote_pending: 'Quote pending',
  ready_for_work: 'Ready for work',
  in_progress: 'In progress',
  verifying: 'Verifying',
  resolved: 'Resolved',
  closed: 'Closed',
};

export const TRANSITIONS: Readonly<Record<TicketState, readonly TicketState[]>> = {
  new: ['investigating', 'waiting_for_customer'],
  investigating: ['waiting_for_customer', 'quote_pending', 'closed'],
  waiting_for_customer: ['investigating', 'quote_pending'],
  quote_pending: ['ready_for_work', 'waiting_for_customer', 'closed'],
  ready_for_work: ['in_progress'],
  in_progress: ['verifying', 'investigating'],
  verifying: ['resolved', 'investigating'],
  resolved: ['closed', 'investigating'],
  closed: ['investigating'],
};

export interface ApprovalSnapshot {
  amountPaise: number;
  expiresAt: Date;
  voided: boolean;
  paymentVerified: boolean;
}

export interface TransitionContext {
  approvals?: ApprovalSnapshot[];
  verificationPassed?: boolean;
  now?: Date;
}

export type TransitionCheck = { ok: true } | { ok: false; reason: string };

export function canTransition(from: TicketState, to: TicketState, ctx: TransitionContext = {}): TransitionCheck {
  if (!TRANSITIONS[from].includes(to)) {
    return { ok: false, reason: `${TICKET_LABELS[from]} can't move to ${TICKET_LABELS[to]}.` };
  }
  const now = ctx.now ?? new Date();
  if (from === 'quote_pending' && to === 'ready_for_work') {
    const valid = (ctx.approvals ?? []).some(
      (a) => !a.voided && a.expiresAt > now && (a.amountPaise === 0 || a.paymentVerified),
    );
    if (!valid) return { ok: false, reason: 'Needs a current customer approval (and verified payment if chargeable).' };
  }
  if (from === 'verifying' && to === 'resolved' && ctx.verificationPassed !== true) {
    return { ok: false, reason: 'Post-fix verification has not passed.' };
  }
  return { ok: true };
}
