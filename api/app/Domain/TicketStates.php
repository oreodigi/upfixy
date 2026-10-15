<?php

namespace App\Domain;

/**
 * Mirrors packages/core/src/tickets.ts and the DB function ticket_transition_allowed().
 * tests/Unit/TicketStatesParityTest.php checks all three agree.
 */
final class TicketStates
{
    public const ALL = ['new', 'investigating', 'waiting_for_customer', 'quote_pending',
        'ready_for_work', 'in_progress', 'verifying', 'resolved', 'closed'];

    public const TRANSITIONS = [
        'new' => ['investigating', 'waiting_for_customer'],
        'investigating' => ['waiting_for_customer', 'quote_pending', 'closed'],
        'waiting_for_customer' => ['investigating', 'quote_pending'],
        'quote_pending' => ['ready_for_work', 'waiting_for_customer', 'closed'],
        'ready_for_work' => ['in_progress'],
        'in_progress' => ['verifying', 'investigating'],
        'verifying' => ['resolved', 'investigating'],
        'resolved' => ['closed', 'investigating'],
        'closed' => ['investigating'],
    ];

    private const LABELS = [
        'new' => 'New', 'investigating' => 'Investigating', 'waiting_for_customer' => 'Waiting for customer',
        'quote_pending' => 'Quote pending', 'ready_for_work' => 'Ready for work', 'in_progress' => 'In progress',
        'verifying' => 'Verifying', 'resolved' => 'Resolved', 'closed' => 'Closed',
    ];

    /** Null when allowed by the table; otherwise a plain-language reason. Gates needing data are DB-enforced. */
    public static function check(string $from, string $to): ?string
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return self::LABELS[$from] . " can't move to " . self::LABELS[$to] . '.';
        }
        if ($from === 'verifying' && $to === 'resolved') {
            // AC-BRD-003.1: verification runs arrive with M7; until then nothing can be marked resolved.
            return 'Post-fix verification has not passed.';
        }
        return null;
    }
}
