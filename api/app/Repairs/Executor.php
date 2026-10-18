<?php

namespace App\Repairs;

use App\Monitoring\DnsProbe;
use App\Monitoring\HttpProbe;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FIX-003/004/005 repair executor. Deterministic: it runs exactly one
 * allowlisted operation (overwrite one A/AAAA record) and nothing an AI or a
 * request body says can change what it calls. Every phase re-checks every
 * gate; provider calls happen OUTSIDE database transactions; every step is
 * recorded in repair_events.
 *
 *   queued → preflight → executing → verifying → resolved
 *                 ↘ refused   ↘ rolled_back / needs_technician (escalation)
 */
final class Executor
{
    public const OPERATION = 'cloudflare.dns.update_address_record';
    private const SYSTEM_ACTOR_REASON = 'Upfixy repair executor';

    public function __construct(
        private DnsProvider $provider,
        private DnsProbe $dns,
        private HttpProbe $http,
        private array $cfg,
    ) {}

    private function db(): Connection
    {
        return DB::connection('pgsql_executor');
    }

    /** Process one unit of work. @return bool whether anything was claimed */
    public function runOnce(): bool
    {
        $w = $this->db()->selectOne('SELECT * FROM executor_claim(?)', [$this->cfg['lease_seconds']]);
        if (!$w) return false;
        try {
            $w->kind === 'draft' ? $this->draft($w->workspace_id, $w->id) : $this->attempt($w->workspace_id, $w->id);
        } catch (Throwable $e) {
            // Never leave a half-known state silently: record and escalate.
            if ($w->kind === 'attempt') {
                $this->finish($w->workspace_id, $w->id, 'needs_technician', 'internal error: ' . substr($e->getMessage(), 0, 120),
                    'Something unexpected stopped the automated fix. A technician will take over.', ['investigating', 'internal error']);
            }
            throw $e;
        }
        return true;
    }

    // --------------------------------------------------------------- drafts
    private function draft(string $ws, string $id): void
    {
        [$d, $c, $hosts, $httpOk] = $this->tx($ws, function ($db) use ($id) {
            $d = $db->selectOne('SELECT * FROM repair_drafts WHERE id = ?', [$id]);
            $c = $db->selectOne('SELECT id, workspace_id, website_id, zone_id, zone_name, secret_version, revoked_at FROM dns_connections WHERE id = ?', [$d->connection_id]);
            $hosts = array_column($db->select('SELECT DISTINCT host FROM monitor_targets WHERE website_id = ?', [$c->website_id]), 'host');
            $last = $db->selectOne("SELECT severity::text AS s FROM observations WHERE monitor_target_id = ? AND check_kind = 'http' ORDER BY observed_at DESC LIMIT 1", [$d->monitor_target_id]);
            return [$d, $c, $hosts, in_array($last->s ?? null, ['ok', 'warning'], true)];
        });
        $refuse = fn (string $why) => $this->tx($ws, fn ($db) => $db->update(
            "UPDATE repair_drafts SET state = 'refused', refusal = ?, read_at = now() WHERE id = ?", [$why, $id]));

        if ($c->revoked_at) { $refuse('The DNS connection was revoked.'); return; }
        try {
            $token = $this->token($ws, $c->id, $c->secret_version);
            // The token must see exactly the zone the customer connected, by ID and name (AC-INT-004.1 spirit).
            $zone = $this->provider->zone($token, $c->zone_id);
            $this->tx($ws, fn ($db) => $db->update('UPDATE dns_connections SET verified_at = now(), verify_result = ? WHERE id = ?',
                [$zone['zone_name'] === $c->zone_name ? 'ok' : 'zone name mismatch', $c->id]));
            if ($zone['zone_name'] !== $c->zone_name) { $refuse('The DNS token does not match the connected zone.'); return; }
            $rec = $this->provider->getRecord($token, $c->zone_id, $d->record_id);
        } catch (ProviderError $e) {
            $refuse("The DNS provider would not show this record ({$e->kind})."); return;
        } catch (Throwable) {
            $refuse('The DNS connection could not be used.'); return;
        }
        if ($why = Policy::refuseRecord($rec, $c->zone_name, $hosts)) { $refuse($why); return; }
        if ($why = Policy::refuseContent($rec['type'], $d->proposed_content, $rec['content'])) { $refuse($why); return; }

        $plan = [
            'version' => 'repair-plan-v1',
            'operation' => self::OPERATION,
            'connection_id' => $c->id, 'zone_id' => $c->zone_id, 'zone_name' => $c->zone_name,
            'record_id' => $d->record_id, 'record_name' => $rec['name'], 'record_type' => $rec['type'],
            'before' => ['content' => $rec['content'], 'ttl' => $rec['ttl'], 'proxied' => $rec['proxied']],
            'after' => ['content' => strtolower($d->proposed_content), 'ttl' => $rec['ttl'], 'proxied' => $rec['proxied']],
            'verification' => ['resolve' => $rec['name'], 'expect' => strtolower($d->proposed_content),
                'http_target_id' => $d->monitor_target_id, 'http_was_ok' => $httpOk],
            'rollback' => 'restore the "before" value if the change makes the website fail and the record still holds our value',
            'diagnostic_run_id' => $d->diagnostic_run_id,
        ];
        $this->tx($ws, fn ($db) => $db->update("UPDATE repair_drafts SET state = 'ready', plan = ?::jsonb, read_at = now() WHERE id = ?",
            [json_encode($plan), $id]));
    }

    // ------------------------------------------------------------- attempts
    private function attempt(string $ws, string $id): void
    {
        // Each loop step re-reads the attempt; a step either finishes, waits, or moves to the next state.
        // $prepared is true only when THIS run just completed preflight: an 'executing' attempt found any
        // other way was interrupted mid-write and must be reconciled, never written blindly again.
        $prepared = false;
        for ($guard = 0; $guard < 6; $guard++) {
            $a = $this->tx($ws, fn ($db) => $db->selectOne('SELECT * FROM repair_attempts WHERE id = ?', [$id]));
            $plan = json_decode($a->plan, true);
            switch ($a->state) {
                case 'queued':
                case 'preflight':
                    [$verdict, $why] = $this->gates($ws, $a, $plan, true);
                    if ($verdict === 'hold') { $this->hold($ws, $a, $why); return; }
                    if ($verdict === 'refuse') {
                        $this->finish($ws, $id, 'refused', $why, "The automated fix did not run: {$why}", $a->state === 'preflight' ? ['investigating', $why] : null);
                        return;
                    }
                    if ($a->state === 'queued') {
                        $this->tx($ws, function ($db) use ($a) {
                            $db->update("UPDATE repair_attempts SET state = 'preflight', updated_at = now() WHERE id = ?", [$a->id]);
                            $db->select('SELECT executor_move_ticket(?, ?::ticket_state, ?, ?)', [$a->ticket_id, 'in_progress', 'Automated DNS fix started', $a->requested_by]);
                            $this->event($db, $a, 'preflight_started');
                        });
                        continue 2;
                    }
                    $prepared = $this->preflight($ws, $a, $plan);
                    continue 2;
                case 'executing':
                    if ($prepared) {
                        [$verdict, $why] = $this->gates($ws, $a, $plan, true);
                        if ($verdict !== 'ok') {
                            // Nothing written yet: stop cleanly. (Kill switch engaged between preflight and write.)
                            $this->finish($ws, $id, $verdict === 'hold' ? 'needs_technician' : 'refused', $why,
                                "The automated fix was stopped before any change: {$why}", ['investigating', $why]);
                            return;
                        }
                        $this->execute($ws, $a, $plan);
                    } else {
                        $this->reconcileAfterUncertainWrite($ws, $a, $plan, $this->token($ws, $a->connection_id, (int) $a->connection_secret_version));
                    }
                    $prepared = false;
                    continue 2;
                case 'verifying':
                    $this->verify($ws, $a, $plan);
                    return;
                default:
                    return; // finished
            }
        }
    }

    /**
     * Every gate, every phase (§25.4 steps 1–4). Returns ['ok'|'hold'|'refuse', reason].
     * hold = temporarily stopped (kill switch): the attempt waits, nothing is consumed.
     */
    private function gates(string $ws, object $a, array $plan, bool $forWrite): array
    {
        return $this->tx($ws, function ($db) use ($ws, $a, $plan, $forWrite) {
            if ($forWrite && !$this->cfg['enabled']) return ['hold', 'automated repairs are switched off on this platform'];
            if ($forWrite && $this->paused($db, $ws)) return ['hold', 'automated repairs are paused (kill switch)'];

            $c = $db->selectOne('SELECT id, workspace_id, website_id, zone_id, zone_name, secret_version, revoked_at FROM dns_connections WHERE id = ?', [$a->connection_id]);
            if (!$c || $c->revoked_at) return ['refuse', 'the DNS connection was revoked'];
            if ((int) $c->secret_version !== (int) $a->connection_secret_version) return ['refuse', 'the DNS credential changed after this fix was requested'];

            $ap = $db->selectOne('SELECT * FROM approvals WHERE id = ?', [$a->approval_id]);
            $q = $db->selectOne('SELECT * FROM quotes WHERE id = ?', [$a->quote_id]);
            $t = $db->selectOne('SELECT * FROM monitor_targets WHERE id = ?', [$q->monitor_target_id]);
            if (!$ap || $ap->voided_at) return ['refuse', 'the approval was withdrawn'];
            if (strtotime($ap->expires_at) <= time()) return ['refuse', 'the approval expired'];
            if ($q->voided_at) return ['refuse', 'the quote was replaced or cancelled'];
            $fp = $db->selectOne('SELECT quote_fingerprint(q, t) AS f FROM quotes q, monitor_targets t WHERE q.id = ? AND t.id = ?', [$q->id, $t->id])->f;
            if (!hash_equals($fp, $q->fingerprint) || !hash_equals($q->fingerprint, (string) $ap->fingerprint) || !hash_equals($q->fingerprint, $a->plan_fingerprint)) {
                return ['refuse', 'the approved change does not match the requested change'];
            }
            if (json_decode($q->params, true) != $plan) return ['refuse', 'the approved change does not match the requested change'];
            // Verified payment, or proven provider coverage. No coverage records exist before M6, so ₹0 cannot pass.
            if (!$ap->payment_verified) return ['refuse', (int) $q->amount_paise === 0 ? 'provider coverage is not proven' : 'payment is not verified'];
            if (!$db->selectOne("SELECT site_private_access(?, ?, 'repair') AS ok", [$ws, $t->id])->ok) {
                return ['refuse', 'ownership or delegation for this address is no longer valid'];
            }
            $tk = $db->selectOne('SELECT state::text AS state, website_id FROM tickets WHERE id = ?', [$a->ticket_id]);
            if (!in_array($tk->state, ['ready_for_work', 'in_progress', 'verifying'], true)) return ['refuse', 'the request is not ready for work'];
            $hosts = array_column($db->select('SELECT DISTINCT host FROM monitor_targets WHERE website_id = ?', [$tk->website_id]), 'host');
            if ($why = Policy::refusePlan($plan, $c, $hosts)) return ['refuse', $why];
            return ['ok', null];
        });
    }

    /** @return bool true when the attempt is now ready to write */
    private function preflight(string $ws, object $a, array $plan): bool
    {
        try {
            $token = $this->token($ws, $a->connection_id, (int) $a->connection_secret_version);
            $rec = $this->provider->getRecord($token, $plan['zone_id'], $plan['record_id']);
        } catch (ProviderError $e) {
            $this->finish($ws, $a->id, 'needs_technician', "could not read the record before changing it ({$e->kind})",
                'The automated fix could not safely read your DNS record, so nothing was changed. A technician will follow up.', ['investigating', 'preflight read failed']);
            return false;
        }
        // §25.4 step 5: the live record must be exactly what the customer approved.
        if (!Policy::same($rec, $plan['record_type'], $plan['record_name'], $plan['before'])) {
            $this->tx($ws, fn ($db) => $this->event($db, $a, 'preflight_mismatch', ['found' => Policy::view($rec), 'approved' => $plan['before'], 'ref' => $rec['ref']]));
            $this->finish($ws, $a->id, 'refused', 'the DNS record changed since it was approved',
                'Your DNS record changed after you approved this fix, so nothing was changed. A new quote is needed.', ['investigating', 'record changed since approval']);
            return false;
        }
        $this->tx($ws, function ($db) use ($a, $rec) {
            // Before-image and write counter are committed BEFORE the provider call (no blind duplicate writes).
            $this->event($db, $a, 'before_image', ['record' => Policy::view($rec), 'ref' => $rec['ref']]);
            $db->update("UPDATE repair_attempts SET state = 'executing', write_count = write_count + 1, updated_at = now() WHERE id = ?", [$a->id]);
            $this->event($db, $a, 'write_intent');
        });
        return true;
    }

    private function execute(string $ws, object $a, array $plan): void
    {
        $token = $this->token($ws, $a->connection_id, (int) $a->connection_secret_version);
        $desired = ['type' => $plan['record_type'], 'name' => $plan['record_name']] + $plan['after'];
        try {
            $res = $this->provider->updateAddressRecord($token, $plan['zone_id'], $plan['record_id'], $desired);
            $this->tx($ws, fn ($db) => $this->event($db, $a, 'write', ['request' => Policy::view($desired), 'result' => Policy::view($res), 'ref' => $res['ref']]));
        } catch (ProviderError $e) {
            $this->tx($ws, fn ($db) => $this->event($db, $a, $e->kind === 'unknown_outcome' ? 'write_unknown_outcome' : 'write_failed',
                ['request' => Policy::view($desired), 'error' => $e->kind, 'ref' => $e->ref]));
            if ($e->kind === 'unknown_outcome') { $this->reconcileAfterUncertainWrite($ws, $a, $plan, $token); return; }
            $this->finish($ws, $a->id, 'needs_technician', "the provider rejected the change ({$e->kind})",
                'Your DNS provider did not accept the automated change, so nothing was changed. A technician will follow up.', ['investigating', 'provider rejected change']);
            return;
        }
        // §25.4 step 7: re-read; the provider's word alone is not proof.
        $now = $this->read($token, $plan);
        if ($now !== null && Policy::same($now, $plan['record_type'], $plan['record_name'], $plan['after'])) {
            $this->toVerifying($ws, $a);
        } else {
            $this->rollback($ws, $a, $plan, $token, 'the record did not read back as written');
        }
    }

    /** AC-FIX-004.3 / 004.1: after an uncertain write, look before acting. */
    private function reconcileAfterUncertainWrite(string $ws, object $a, array $plan, string $token): void
    {
        $now = $this->read($token, $plan);
        $this->tx($ws, fn ($db) => $this->event($db, $a, 'reconcile', ['found' => $now ? Policy::view($now) : null, 'ref' => $now['ref'] ?? null]));
        if ($now === null) {
            $this->finish($ws, $a->id, 'needs_technician', 'record state unknown after a provider error',
                'We could not confirm what happened at your DNS provider. A technician will check it.', ['investigating', 'state unknown']);
            return;
        }
        if (Policy::same($now, $plan['record_type'], $plan['record_name'], $plan['after'])) {
            // Applied, but the provider reported a failure: indeterminate. Return to the known-good state.
            $this->rollback($ws, $a, $plan, $token, 'the provider reported an error after applying the change');
            return;
        }
        if (Policy::same($now, $plan['record_type'], $plan['record_name'], $plan['before']) && (int) $a->write_count < 2) {
            // Not applied: one safe retry, counted before it is sent.
            $this->tx($ws, function ($db) use ($a) {
                $db->update('UPDATE repair_attempts SET write_count = write_count + 1, updated_at = now() WHERE id = ?', [$a->id]);
                $this->event($db, $a, 'retry_after_reconcile');
            });
            try {
                $res = $this->provider->updateAddressRecord($token, $plan['zone_id'], $plan['record_id'], ['type' => $plan['record_type'], 'name' => $plan['record_name']] + $plan['after']);
                $this->tx($ws, fn ($db) => $this->event($db, $a, 'write', ['result' => Policy::view($res), 'ref' => $res['ref']]));
            } catch (ProviderError $e) {
                $this->tx($ws, fn ($db) => $this->event($db, $a, 'write_failed', ['error' => $e->kind, 'ref' => $e->ref]));
                $this->finish($ws, $a->id, 'needs_technician', 'the retry failed too', 'The automated fix could not be applied. A technician will follow up.', ['investigating', 'retry failed']);
                return;
            }
            $after = $this->read($token, $plan);
            $after && Policy::same($after, $plan['record_type'], $plan['record_name'], $plan['after'])
                ? $this->toVerifying($ws, $a)
                : $this->rollback($ws, $a, $plan, $token, 'the record did not read back as written');
            return;
        }
        // Neither our value nor the approved starting value: someone else changed it. Never overwrite.
        $this->finish($ws, $a->id, 'needs_technician', 'the record was changed by someone else during the fix',
            'Your DNS record was changed by someone else while the fix ran, so Upfixy stopped. A technician will review it.', ['investigating', 'concurrent change']);
    }

    private function toVerifying(string $ws, object $a): void
    {
        $this->tx($ws, function ($db) use ($a) {
            $db->update("UPDATE repair_attempts SET state = 'verifying', verify_deadline = now() + make_interval(secs => ?),
                         next_verify_at = now() + make_interval(secs => ?), lease_until = NULL, updated_at = now() WHERE id = ?",
                [$this->cfg['verify_window_seconds'], $this->cfg['verify_interval_seconds'], $a->id]);
            $db->select('SELECT executor_move_ticket(?, ?::ticket_state, ?, ?)', [$a->ticket_id, 'verifying', 'Change applied; verifying', $a->requested_by]);
            $this->event($db, $a, 'verifying_started');
        });
    }

    /** §25.4 step 7 and FIX-002: public DNS must show the change, and the website check must pass. */
    private function verify(string $ws, object $a, array $plan): void
    {
        $token = $this->token($ws, $a->connection_id, (int) $a->connection_secret_version, true);
        $now = $token ? $this->read($token, $plan) : null;
        if ($now !== null && !Policy::same($now, $plan['record_type'], $plan['record_name'], $plan['after'])) {
            $this->tx($ws, fn ($db) => $this->event($db, $a, 'concurrent_change', ['found' => Policy::view($now), 'ref' => $now['ref']]));
            $this->finish($ws, $a->id, 'needs_technician', 'the record was changed by someone else after the fix',
                'Your DNS record was changed again after the fix, so Upfixy stopped checking. A technician will review it.', ['investigating', 'concurrent change']);
            return;
        }
        $deadlinePassed = strtotime((string) $a->verify_deadline) <= time();
        [$code, $ips] = $this->dns->resolve($plan['verification']['resolve']);
        $want = $plan['verification']['expect'];
        $visible = $code === 'DNS_OK' && in_array($want, array_map('strtolower', $ips), true) && !in_array($plan['before']['content'], $ips, true);
        $this->tx($ws, fn ($db) => $this->event($db, $a, 'verify_dns', ['result' => $code, 'visible' => $visible]));
        if (!$visible) {
            if ($deadlinePassed) {
                $this->finish($ws, $a->id, 'needs_technician', 'the change was not visible in public DNS within the verification window',
                    'The change was made at your DNS provider but is not yet visible everywhere. We are treating this as unresolved and a technician will check it.', ['investigating', 'propagation not confirmed']);
            } else {
                $this->waitVerify($ws, $a);
            }
            return;
        }
        $t = $this->tx($ws, fn ($db) => $db->selectOne('SELECT scheme, host, port, path FROM monitor_targets WHERE id = ?', [$plan['verification']['http_target_id']]));
        $r = $this->http->probe($t->scheme, $t->host, $t->port, $t->path, [$want]);
        $this->tx($ws, fn ($db) => $this->event($db, $a, 'verify_http', ['code' => $r->code, 'severity' => $r->severity]));
        if (in_array($r->severity, ['ok', 'warning'], true)) {
            $this->finish($ws, $a->id, 'resolved', null, 'The DNS record was updated and the website check now passes.', ['resolved', 'verified by post-fix checks']);
            return;
        }
        if ($r->severity === 'critical') {
            if ($plan['verification']['http_was_ok']) {
                $this->rollback($ws, $a, $plan, $token ?? $this->token($ws, $a->connection_id, (int) $a->connection_secret_version), 'the website started failing after the change');
            } else {
                $this->finish($ws, $a->id, 'needs_technician', 'the change was applied but the website check still fails',
                    'The DNS record was updated, but your website still has a problem, so this is not resolved yet. A technician will continue.', ['investigating', 'post-fix check failed']);
            }
            return;
        }
        $deadlinePassed
            ? $this->finish($ws, $a->id, 'needs_technician', 'the website could not be checked within the verification window',
                'We could not confirm the fix worked. A technician will check it.', ['investigating', 'verification inconclusive'])
            : $this->waitVerify($ws, $a);
    }

    /** AC-FIX-004.1/.2: restore the before-image only if the record still holds exactly what we wrote. */
    private function rollback(string $ws, object $a, array $plan, ?string $token, string $why): void
    {
        $paused = !$this->cfg['enabled'] || $this->tx($ws, fn ($db) => $this->paused($db, $ws));
        if ($paused || !$token) {
            $this->finish($ws, $a->id, 'needs_technician', "rollback needed ({$why}) but repairs are paused",
                'The fix did not work as expected and an undo is needed, but automated changes are paused. A technician will handle it.', ['investigating', 'rollback held']);
            return;
        }
        $now = $this->read($token, $plan);
        if ($now === null || !Policy::same($now, $plan['record_type'], $plan['record_name'], $plan['after'])) {
            $this->tx($ws, fn ($db) => $this->event($db, $a, 'rollback_skipped', ['reason' => 'record no longer holds our value', 'found' => $now ? Policy::view($now) : null]));
            $this->finish($ws, $a->id, 'needs_technician', "{$why}; rollback skipped because the record was changed by someone else",
                'The fix did not work as expected, and the record has since been changed by someone else, so Upfixy did not undo it. A technician will review it.', ['investigating', 'rollback unsafe']);
            return;
        }
        $this->tx($ws, function ($db) use ($a, $why) {
            $db->update('UPDATE repair_attempts SET write_count = write_count + 1, updated_at = now() WHERE id = ?', [$a->id]);
            $this->event($db, $a, 'rollback_started', ['reason' => $why]);
        });
        $restore = ['type' => $plan['record_type'], 'name' => $plan['record_name']] + $plan['before'];
        try {
            $res = $this->provider->updateAddressRecord($token, $plan['zone_id'], $plan['record_id'], $restore);
            $this->tx($ws, fn ($db) => $this->event($db, $a, 'rollback_write', ['request' => Policy::view($restore), 'result' => Policy::view($res), 'ref' => $res['ref']]));
        } catch (ProviderError $e) {
            $this->tx($ws, fn ($db) => $this->event($db, $a, 'rollback_failed', ['error' => $e->kind, 'ref' => $e->ref]));
        }
        $back = $this->read($token, $plan);
        if ($back && Policy::same($back, $plan['record_type'], $plan['record_name'], $plan['before'])) {
            $this->finish($ws, $a->id, 'rolled_back', $why, 'The fix did not work as expected, so your DNS record was put back exactly as it was. A technician will follow up.', ['investigating', 'rolled back']);
        } else {
            $this->finish($ws, $a->id, 'needs_technician', "{$why}; rollback could not be confirmed",
                'The fix did not work and the undo could not be confirmed. A technician has been alerted.', ['investigating', 'rollback failed']);
        }
    }

    // --------------------------------------------------------------- helpers
    private function read(string $token, array $plan): ?array
    {
        try {
            return $this->provider->getRecord($token, $plan['zone_id'], $plan['record_id']);
        } catch (ProviderError) {
            return null;
        }
    }

    private function token(string $ws, string $connection, int $version, bool $soft = false): ?string
    {
        $sealed = $this->tx($ws, fn ($db) => $db->selectOne('SELECT executor_sealed_secret(?, ?) AS s', [$connection, $version])->s);
        if (!$sealed) {
            if ($soft) return null;
            throw new \RuntimeException('credential unavailable (revoked or rotated)');
        }
        return SecretBox::open($sealed);
    }

    private function hold(string $ws, object $a, string $why): void
    {
        $this->tx($ws, function ($db) use ($a, $why) {
            $last = $db->selectOne('SELECT kind FROM repair_events WHERE attempt_id = ? ORDER BY id DESC LIMIT 1', [$a->id]);
            if (($last->kind ?? null) !== 'held') $this->event($db, $a, 'held', ['reason' => $why]);
            $db->update("UPDATE repair_attempts SET state_reason = ?, lease_until = now() + make_interval(secs => ?), updated_at = now() WHERE id = ?",
                [$why, $this->cfg['hold_seconds'], $a->id]);
        });
    }

    /** Fail closed: a missing global control row counts as paused. */
    private function paused($db, string $ws): bool
    {
        $g = $db->selectOne('SELECT paused FROM repair_controls WHERE workspace_id IS NULL');
        if (!$g || $g->paused) return true;
        return (bool) $db->selectOne('SELECT 1 AS x FROM repair_controls WHERE workspace_id = ? AND paused', [$ws]);
    }

    private function waitVerify(string $ws, object $a): void
    {
        $this->tx($ws, fn ($db) => $db->update('UPDATE repair_attempts SET next_verify_at = now() + make_interval(secs => ?), lease_until = NULL, updated_at = now() WHERE id = ?',
            [$this->cfg['verify_interval_seconds'], $a->id]));
    }

    /** @param ?array{0:string,1:string} $ticket [to-state, reason] */
    private function finish(string $ws, string $id, string $state, ?string $why, string $customer, ?array $ticket): void
    {
        $this->tx($ws, function ($db) use ($id, $state, $why, $customer, $ticket) {
            $a = $db->selectOne('SELECT * FROM repair_attempts WHERE id = ?', [$id]);
            $db->update('UPDATE repair_attempts SET state = ?::repair_state, state_reason = ?, customer_message = ?, lease_until = NULL, updated_at = now() WHERE id = ?',
                [$state, $why, $customer, $id]);
            $this->event($db, $a, "finished_{$state}", ['reason' => $why]);
            if ($ticket) {
                $from = $db->selectOne('SELECT state::text AS s FROM tickets WHERE id = ?', [$a->ticket_id])->s;
                // Resolved only from Verifying (FIX-002); every other ending returns the request to Investigating.
                $to = $ticket[0] === 'resolved' && $from !== 'verifying' ? 'investigating' : $ticket[0];
                if (!($to === 'investigating' && $from === 'ready_for_work')) {
                    $db->select('SELECT executor_move_ticket(?, ?::ticket_state, ?, ?)', [$a->ticket_id, $to, 'Automated DNS fix: ' . $ticket[1], $a->requested_by]);
                }
            }
        });
    }

    private function event($db, object $a, string $kind, array $detail = []): void
    {
        $db->insert('INSERT INTO repair_events(workspace_id, attempt_id, kind, detail) VALUES (?, ?, ?, ?::jsonb)',
            [$a->workspace_id, $a->id, $kind, json_encode($detail + ['by' => self::SYSTEM_ACTOR_REASON], JSON_UNESCAPED_SLASHES)]);
    }

    private function tx(string $ws, \Closure $fn): mixed
    {
        $db = $this->db();
        return $db->transaction(function () use ($db, $ws, $fn) {
            $db->select("SELECT set_config('app.workspace_id', ?, true), set_config('app.user_id', '', true)", [$ws]);
            return $fn($db);
        });
    }
}
