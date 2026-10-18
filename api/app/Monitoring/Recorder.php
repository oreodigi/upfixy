<?php

namespace App\Monitoring;

use App\Mail\IncidentMail;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Persists one finished cycle as the worker role, inside the target's
 * workspace: check run, readings (evidence), cycle completion, incident
 * decision and in-app notifications in ONE transaction; emails after commit.
 * Re-recording the same cycle is a no-op (unique keys, ON CONFLICT).
 */
final class Recorder
{
    public function __construct(private string $worker, private string $version, private string $region) {}

    private function db(): Connection
    {
        return DB::connection('pgsql_worker');
    }

    /**
     * @param Reading[] $readings
     * @return array<int, array{incident_id:string, event:string}> incident events produced by this cycle
     */
    public function record(object $t, array $readings, string $state, ?string $reason, \DateTimeInterface $started, \DateTimeInterface $finished): array
    {
        $db = $this->db();
        $events = [];
        $mail = [];
        $db->transaction(function () use ($db, $t, $readings, $state, $reason, $started, $finished, &$events, &$mail) {
            $db->select("SELECT set_config('app.workspace_id', ?, true), set_config('app.user_id', '', true)", [$t->workspace_id]);
            $outcome = IncidentDebounce::runOutcome($readings);
            $attempts = max(array_map(fn ($r) => $r->attempts, $readings));
            $run = $db->selectOne(
                'INSERT INTO check_runs(workspace_id, monitor_target_id, cycle_no, scheduled_at, started_at, finished_at, worker, worker_version, region, outcome, attempts)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::run_outcome, ?)
                 ON CONFLICT (monitor_target_id, cycle_no) DO NOTHING RETURNING id',
                [$t->workspace_id, $t->target_id, $t->cycle_no, $t->scheduled_at, $started, $finished, $this->worker, $this->version, $this->region, $outcome, min($attempts, 10)]);
            if (!$run) {
                // Another process already recorded this cycle: just release our claim.
                $db->select('SELECT worker_finish_cycle(?, ?, ?::monitor_state, ?)', [$t->target_id, $t->cycle_no, $state, $reason]);
                return;
            }
            foreach ($readings as $r) {
                $db->insert(
                    "INSERT INTO observations(workspace_id, monitor_target_id, check_kind, cycle_no, severity, source, result_code, observed_at, detail, check_run_id, region)
                     VALUES (?, ?, ?::check_type, ?, ?::signal_severity, 'public', ?, ?, ?::jsonb, ?, ?)
                     ON CONFLICT (monitor_target_id, check_kind, cycle_no) DO NOTHING",
                    [$t->workspace_id, $t->target_id, $r->kind === 'probe' ? 'http' : $r->kind, $t->cycle_no, $r->severity, $r->code, $finished,
                        json_encode($r->detail + ['attempts' => $r->attempts, 'outcome' => $r->outcome], JSON_UNESCAPED_SLASHES), $run->id, $this->region]);
            }
            $db->select('SELECT worker_finish_cycle(?, ?, ?::monitor_state, ?)', [$t->target_id, $t->cycle_no, $state, $reason]);

            // Incident decision from stored history (idempotent).
            $history = array_reverse(array_map(fn ($x) => IncidentDebounce::cycleOutcome($x->outcome), $db->select(
                'SELECT outcome::text AS outcome FROM check_runs WHERE monitor_target_id = ? ORDER BY cycle_no DESC LIMIT 20', [$t->target_id])));
            $open = (bool) $db->selectOne("SELECT 1 AS x FROM incidents WHERE monitor_target_id = ? AND status = 'open'", [$t->target_id]);
            $action = IncidentDebounce::decide($open, $history);
            if ($action === 'none') return;
            $root = IncidentDebounce::root($readings);
            $first = $action === 'open'
                ? $db->selectOne("SELECT min(finished_at) AS at FROM (SELECT finished_at FROM check_runs WHERE monitor_target_id = ? AND outcome = 'target_failure' ORDER BY cycle_no DESC LIMIT 3) x", [$t->target_id])->at
                : $finished;
            $res = $db->selectOne('SELECT * FROM worker_apply_incident(?, ?, ?::check_type, ?, ?, ?, ?)', [
                $t->target_id, $action, $root?->kind ?? 'http', $root?->code ?? 'RECOVERED', $finished, $first, $t->cycle_no]);
            if (!$res) return;
            $events[] = ['incident_id' => $res->incident_id, 'event' => $res->event];
            if (in_array($res->event, ['opened', 'resolved'], true)) {
                $kind = $res->event === 'opened' ? 'incident_opened' : 'incident_resolved';
                $code = $db->selectOne('SELECT result_code FROM incidents WHERE id = ?', [$res->incident_id])->result_code;
                foreach ($db->select('SELECT * FROM worker_notify_incident(?, ?)', [$res->incident_id, $kind]) as $to) {
                    $mail[] = ['to' => $to, 'kind' => $kind, 'code' => $code, 'at' => $res->event === 'opened' ? $first : $finished];
                }
            }
        });

        foreach ($mail as $m) $this->email($t, $m);
        return $events;
    }

    private function email(object $t, array $m): void
    {
        try {
            $issue = IssueLibrary::get($m['code']);
            $when = (new \DateTimeImmutable((string) $m['at']))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('j M Y, H:i');
            Mail::to((string) $m['to']->email)->send(new IncidentMail(
                $m['kind'], $t->host, $issue['title'] ?? $m['code'], $issue['meaning'] ?? '', $when,
                config('upfixy.frontend_url') . "/websites/{$t->website_id}"));
            $db = $this->db();
            $db->transaction(function () use ($db, $t, $m) {
                $db->select("SELECT set_config('app.workspace_id', ?, true)", [$t->workspace_id]);
                $db->select('SELECT worker_mark_emailed(?)', [$m['to']->notification_id]);
            });
        } catch (Throwable $e) {
            // The in-app alert is already stored; a failed email is logged without the address (NFR-21).
            Log::warning('incident email failed', ['notification' => $m['to']->notification_id, 'error' => $e->getMessage()]);
        }
    }
}
