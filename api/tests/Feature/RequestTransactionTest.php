<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** WithTenantContext: a request that writes and then fails must leave nothing behind. */
class RequestTransactionTest extends TestCase
{
    public function test_write_then_error_response_is_rolled_back(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'workspace'])->post('/api/v1/_test/partial', function () {
            DB::insert("INSERT INTO clients(workspace_id, name) VALUES (current_setting('app.workspace_id')::uuid, 'Half-made')");
            abort(422, 'Second step failed.');
        });
        $u = $this->makeUser('alice');
        $ws = $this->makeWorkspace('A');
        $this->addMember($ws, $u, 'owner');

        $this->api($u, $ws, 'POST', '_test/partial')->assertStatus(422);
        $this->assertSame(0, $this->owner()->table('clients')->count());
    }

    public function test_write_then_exception_is_rolled_back(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'workspace'])->post('/api/v1/_test/boom', function () {
            DB::insert("INSERT INTO clients(workspace_id, name) VALUES (current_setting('app.workspace_id')::uuid, 'Half-made')");
            throw new \RuntimeException('unexpected');
        });
        $u = $this->makeUser('alice');
        $ws = $this->makeWorkspace('A');
        $this->addMember($ws, $u, 'owner');

        $this->api($u, $ws, 'POST', '_test/boom')->assertStatus(500);
        $this->assertSame(0, $this->owner()->table('clients')->count());
    }
}
