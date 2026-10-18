<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * users table (db/migrations/0001, hardened in 0006).
 * The app role can only SELECT the columns below; password hashes and MFA
 * material are read through narrow database functions, never through this model.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasUuids;

    public const SAFE_COLUMNS = ['id', 'email', 'email_verified_at', 'mfa_enabled_at', 'created_at'];

    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'mfa_enabled_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('safe_columns', fn (Builder $q) => $q->select(self::SAFE_COLUMNS));
    }

    public function getAuthPassword(): string
    {
        return ''; // never loaded; login verifies through auth_lookup()
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function hasMfa(): bool
    {
        return $this->mfa_enabled_at !== null;
    }
}
