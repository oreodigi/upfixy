<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Token lookup through auth_token_lookup() (db/migrations/0006): the app role
 * cannot SELECT token hashes or other users' rows directly. The function also
 * never returns expired tokens.
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'mfa_verified_at' => 'datetime',
    ];

    public static function findToken($token)
    {
        if (!str_contains($token, '|')) {
            return null; // only "id|secret" tokens; no full-table hash scan
        }
        [$id, $secret] = explode('|', $token, 2);
        if (!ctype_digit($id)) {
            return null;
        }
        $row = DB::selectOne('SELECT * FROM auth_token_lookup(?)', [(int) $id]);
        if (!$row || !hash_equals((string) $row->token, hash('sha256', $secret))) {
            return null;
        }

        $model = (new static)->newFromBuilder([
            'id' => $row->id, 'tokenable_type' => $row->tokenable_type, 'tokenable_id' => $row->tokenable_id,
            'name' => $row->name, 'abilities' => $row->abilities, 'last_used_at' => $row->last_used_at,
            'expires_at' => $row->expires_at, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            'mfa_verified_at' => $row->mfa_verified_at,
        ]);
        $model->setRelation('tokenable', (new User)->newFromBuilder([
            'id' => $row->tokenable_id, 'email' => $row->user_email, 'email_verified_at' => $row->user_email_verified_at,
            'mfa_enabled_at' => $row->user_mfa_enabled_at, 'created_at' => $row->user_created_at,
        ]));
        return $model;
    }

    /** Sanctum's guard calls this before tenant context exists; RLS would make it a no-op anyway. */
    public function save(array $options = []): bool
    {
        if ($this->exists && array_keys($this->getDirty()) === ['last_used_at']) {
            return true; // recorded by WithTenantContext once the user context is set
        }
        return parent::save($options);
    }

    public function mfaFresh(int $minutes = 15): bool
    {
        return $this->mfa_verified_at !== null && $this->mfa_verified_at->gt(now()->subMinutes($minutes));
    }
}
