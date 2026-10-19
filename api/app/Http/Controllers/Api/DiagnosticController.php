<?php

namespace App\Http\Controllers\Api;

use App\Diagnostics\DiagnosisService;
use App\Jobs\AnalyzeDiagnosis;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** DX-001/002/003/005: start a diagnosis and read the report (SCR-11). */
class DiagnosticController
{
    private const STAFF = ['owner', 'admin', 'technician'];

    public function start(string $website, DiagnosisService $dx): JsonResponse
    {
        if (!DB::selectOne('SELECT 1 AS x FROM websites WHERE id = ?', [$website])) abort(404);
        if (!DB::selectOne('SELECT app_can_diagnose(?) AS ok', [$website])->ok) {
            Audit::record('diagnosis.start', 'denied', 'website', $website);
            abort(403, 'Only owners, admins, the assigned technician and the client owner can run a diagnosis.');
        }
        $r = $dx->start($website);
        if ($r['error'] === 'not_found') abort(404);
        if ($r['error'] === 'no_fresh_evidence') {
            return response()->json(['message' => 'There are no fresh readings to analyse yet. Use "Check now" and try again in a minute.',
                'code' => 'not_eligible'], 422);
        }
        if (!$r['reused']) {
            Audit::record('diagnosis.started', 'allowed', 'diagnostic_run', $r['run_id']);
            AnalyzeDiagnosis::dispatchAfterResponse($r['run_id'], TenantContext::userId(), TenantContext::workspaceId());
        }
        return response()->json(['id' => $r['run_id'], 'reused' => $r['reused']], $r['reused'] ? 200 : 201);
    }

    public function index(string $website): JsonResponse
    {
        if (!DB::selectOne('SELECT 1 AS x FROM websites WHERE id = ?', [$website])) abort(404);
        return response()->json(['data' => DB::select(
            "SELECT r.id, r.status, r.created_at, r.completed_at,
                    (SELECT count(*) FROM findings f WHERE f.run_id = r.id AND f.hypothesis IS NULL)::int AS observations,
                    (SELECT status::text FROM ai_analyses a WHERE a.run_id = r.id) AS ai_status
               FROM diagnostic_runs r WHERE r.website_id = ? ORDER BY r.created_at DESC LIMIT 20", [$website])]);
    }

    public function show(string $run): JsonResponse
    {
        $r = DB::selectOne('SELECT r.*, w.display_name AS website FROM diagnostic_runs r JOIN websites w ON w.id = r.website_id WHERE r.id = ?', [$run]);
        if (!$r) abort(404);
        $staff = in_array(TenantContext::role(), self::STAFF, true);
        $snap = json_decode($r->snapshot, true);
        $findings = DB::select(
            "SELECT finding_key, observed_fact, evidence_ids, hypothesis, supporting_evidence_ids, contradicting_evidence_ids,
                    confidence::text AS confidence, responsibility::text AS responsibility, recommended_actions, human_review_required
               FROM findings WHERE run_id = ? AND source = 'rules' ORDER BY created_at, finding_key", [$run]);
        $pg = fn (string $a) => $a === '{}' ? [] : explode(',', trim($a, '{}'));
        $shape = fn ($f) => [
            'key' => $f->finding_key, 'observed_fact' => $f->observed_fact, 'evidence_ids' => $pg($f->evidence_ids),
            'hypothesis' => $f->hypothesis, 'supporting_evidence_ids' => $pg($f->supporting_evidence_ids),
            'contradicting_evidence_ids' => $pg($f->contradicting_evidence_ids), 'confidence' => $f->confidence,
            'responsibility' => $f->responsibility, 'recommended_actions' => json_decode($f->recommended_actions, true),
            'human_review_required' => $f->human_review_required,
        ];
        $ai = DB::selectOne('SELECT status::text AS status, model, prompt_version, schema_version, output, rejection_reasons, latency_ms, created_at FROM ai_analyses WHERE run_id = ?', [$run]);
        $aiOut = ['status' => $ai->status ?? 'pending'];
        if ($ai?->status === 'succeeded') $aiOut += json_decode($ai->output, true);
        else $aiOut['message'] = match ($ai->status ?? 'pending') {
            'pending' => 'AI-assisted analysis is being prepared.',
            'skipped' => 'AI-assisted analysis is not available here — measured findings only.',
            'budget_exceeded' => 'AI analysis limit reached for now — measured findings only.',
            default => 'AI unavailable — measured findings only.',
        };
        if ($staff && $ai) {
            // Audit view (SCR-11): versions and why an answer was rejected. Never the provider payload in full.
            $aiOut['audit'] = ['model' => $ai->model, 'prompt_version' => $ai->prompt_version, 'schema_version' => $ai->schema_version,
                'rejection_reasons' => json_decode($ai->rejection_reasons, true), 'latency_ms' => $ai->latency_ms];
        }
        $evidence = array_map(fn ($i) => array_intersect_key($i, array_flip(['evidence_id', 'host', 'kind', 'result_code', 'severity', 'observed_at', 'freshness', 'source']))
            + ($staff ? ['facts' => $i['facts']] : []), $snap['items']);

        $all = array_map($shape, $findings);
        return response()->json([
            'id' => $r->id, 'website_id' => $r->website_id, 'website' => $r->website, 'status' => $r->status,
            'created_at' => $r->created_at, 'completed_at' => $r->completed_at,
            'observations' => array_values(array_filter($all, fn ($f) => $f['hypothesis'] === null)),
            'hypotheses' => array_values(array_filter($all, fn ($f) => $f['hypothesis'] !== null)),
            'missing' => $snap['rules']['missing'] ?? [], 'skipped' => $snap['rules']['skipped'] ?? [],
            'human_review_required' => $snap['rules']['human_review_required'] ?? false,
            'evidence' => $evidence,
            'ai' => $aiOut,
            // Requests raised from this report (RLS: only those the caller may see).
            'tickets' => DB::select('SELECT id, title, state::text AS state FROM tickets WHERE diagnostic_run_id = ? ORDER BY created_at DESC', [$run]),
        ] + ($staff ? ['rule_version' => $r->rule_version, 'private_evidence' => $r->private_evidence] : []));
    }
}
