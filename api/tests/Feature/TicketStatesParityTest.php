<?php

namespace Tests\Feature;

use App\Domain\TicketStates;
use Tests\TestCase;

/** PHP table == database ticket_transition_allowed() for all 81 pairs (TS parity is in db/test). */
class TicketStatesParityTest extends TestCase
{
    public function test_php_and_database_agree(): void
    {
        $rows = $this->owner()->select('SELECT f::text AS f, t::text AS t, ticket_transition_allowed(f, t) AS ok
            FROM unnest(enum_range(NULL::ticket_state)) f, unnest(enum_range(NULL::ticket_state)) t');
        $this->assertCount(81, $rows);
        foreach ($rows as $r) {
            $this->assertSame((bool) $r->ok, in_array($r->t, TicketStates::TRANSITIONS[$r->f], true), "{$r->f} -> {$r->t}");
        }
    }
}
