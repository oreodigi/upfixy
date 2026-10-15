<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** users table from db/migrations/0001_core_tenancy.sql (uuid key, password_hash). */
class User extends Authenticatable
{
    use HasApiTokens, HasUuids;

    public $timestamps = false;
    protected $fillable = ['email', 'password_hash'];
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'mfa_enabled_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
