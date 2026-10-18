<?php

namespace App\Http\Controllers\Api;

use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** BRD-004 saved views: personal filter presets per board (RLS: own rows only). */
class SavedViewController
{
    private const FILTER_RULES = [
        'filters' => ['present', 'array'],
        'filters.q' => ['nullable', 'string', 'max:100'],
        'filters.lanes' => ['nullable', 'array', 'max:5'],
        'filters.lanes.*' => ['in:critical,warning,unknown,needs_setup,healthy'],
        'filters.client_id' => ['nullable', 'uuid'],
        'filters.assignee' => ['nullable', 'in:me,unassigned,any'],
        'filters.states' => ['nullable', 'array', 'max:9'],
        'filters.states.*' => ['in:new,investigating,waiting_for_customer,quote_pending,ready_for_work,in_progress,verifying,resolved,closed'],
        'filters.layout' => ['nullable', 'in:board,list'],
    ];

    public function index(Request $request): JsonResponse
    {
        $board = $request->validate(['board' => ['nullable', 'in:clients,websites,work']])['board'] ?? null;
        $rows = DB::select('SELECT id, board, name, filters, created_at FROM saved_views'
            . ($board ? ' WHERE board = ?' : '') . ' ORDER BY board, name', $board ? [$board] : []);
        foreach ($rows as $r) $r->filters = json_decode($r->filters, true);
        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['board' => ['required', 'in:clients,websites,work'], 'name' => ['required', 'string', 'max:60']] + self::FILTER_RULES);
        try {
            $id = DB::selectOne('INSERT INTO saved_views(workspace_id, user_id, board, name, filters) VALUES (?, ?, ?, ?, ?::jsonb) RETURNING id',
                [TenantContext::workspaceId(), TenantContext::userId(), $data['board'], $data['name'], json_encode($this->clean($data['filters'] ?? []))])->id;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return response()->json(['message' => 'You already have a view with that name on this board.'], 409);
            }
            if (str_contains($e->errorInfo[2] ?? '', 'limit reached')) {
                return response()->json(['message' => 'You can keep up to 20 views per board. Delete one first.'], 422);
            }
            throw $e;
        }
        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, string $view): JsonResponse
    {
        $rules = ['name' => ['sometimes', 'string', 'max:60']] + array_map(fn ($r) => $r, self::FILTER_RULES);
        $rules['filters'] = ['sometimes', 'array'];
        $data = $request->validate($rules);
        $sets = [];
        $args = [];
        if (isset($data['name'])) { $sets[] = 'name = ?'; $args[] = $data['name']; }
        if (isset($data['filters'])) { $sets[] = 'filters = ?::jsonb'; $args[] = json_encode($this->clean($data['filters'])); }
        if (!$sets) abort(422, 'Nothing to change.');
        $args[] = $view;
        $n = DB::update('UPDATE saved_views SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
        if ($n === 0) abort(404);
        return response()->json(null, 204);
    }

    public function destroy(string $view): JsonResponse
    {
        if (DB::delete('DELETE FROM saved_views WHERE id = ?', [$view]) === 0) abort(404);
        return response()->json(null, 204);
    }

    /** Keep only known keys, so a view can't smuggle arbitrary data. */
    private function clean(array $f): object
    {
        return (object) array_intersect_key($f, array_flip(['q', 'lanes', 'client_id', 'assignee', 'states', 'layout']));
    }
}
