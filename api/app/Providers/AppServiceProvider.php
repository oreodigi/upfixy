<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\Net\CurlTransport;
use App\Support\Net\HttpTransport;
use App\Support\Net\Resolver;
use App\Support\Net\SystemResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Resolver::class, SystemResolver::class);
        $this->app->bind(HttpTransport::class, fn () => new CurlTransport(config('upfixy.egress_proxy')));
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Code guessing: 6 attempts per minute per user per MFA endpoint. A challenge
        // token also expires after 5 minutes, which caps guesses per sign-in.
        RateLimiter::for('mfa', fn (Request $r) => Limit::perMinute(6)->by(($r->user()?->id ?? $r->ip()) . '|' . $r->path()));
    }
}
