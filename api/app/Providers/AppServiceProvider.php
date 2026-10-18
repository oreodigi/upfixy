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

        // M3 monitoring. Tests replace DnsClient, HttpFetcher and TlsInspector with fakes.
        $m = fn (string $k) => config("upfixy.monitor.{$k}");
        $this->app->bind(\App\Monitoring\Dns\DnsClient::class, fn () => new \App\Monitoring\Dns\UdpDnsClient($m('dns_timeout')));
        $this->app->bind(\App\Monitoring\Http\HttpFetcher::class, fn () => new \App\Monitoring\Http\CurlFetcher(
            $m('http_connect_timeout'), $m('http_total_timeout'), $m('http_max_bytes'), $m('user_agent'), config('upfixy.egress_proxy')));
        $this->app->bind(\App\Monitoring\Tls\TlsInspector::class, fn () => new \App\Monitoring\Tls\StreamTlsInspector($m('tls_timeout')));
        $this->app->bind(\App\Monitoring\DnsProbe::class, fn ($app) => new \App\Monitoring\DnsProbe($app->make(\App\Monitoring\Dns\DnsClient::class), $m('resolvers')));
        $this->app->singleton(\App\Monitoring\ProbeHealth::class, fn ($app) => new \App\Monitoring\ProbeHealth(
            $app->make(\App\Monitoring\Dns\DnsClient::class), $m('resolvers'), $m('canaries')));
        $this->app->bind(\App\Monitoring\CycleRunner::class, fn ($app) => new \App\Monitoring\CycleRunner(
            $app->make(\App\Monitoring\DnsProbe::class), $app->make(\App\Monitoring\TlsProbe::class),
            $app->make(\App\Monitoring\HttpProbe::class), $app->make(\App\Monitoring\ProbeHealth::class), $m('retry_delays_ms')));
        // M5 AI gateway: no client at all unless a key AND a model are configured.
        $this->app->bind(\App\Diagnostics\Ai\AiClient::class, fn () => config('upfixy.ai.openai_key') && config('upfixy.ai.model')
            ? new \App\Diagnostics\Ai\OpenAiResponsesClient(config('upfixy.ai.openai_key'), config('upfixy.ai.model'), config('upfixy.ai.base_url'),
                config('upfixy.ai.timeout'), config('upfixy.ai.max_output_tokens'))
            : null);
        $this->app->bind(\App\Diagnostics\Ai\Gateway::class, fn ($app) => new \App\Diagnostics\Ai\Gateway($app->make(\App\Diagnostics\Ai\AiClient::class), config('upfixy.ai')));
        $this->app->bind(\App\Monitoring\Recorder::class, fn () => new \App\Monitoring\Recorder(
            gethostname() . ':' . getmypid(), $m('worker_version'), $m('region')));
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Code guessing: 6 attempts per minute per user per MFA endpoint. A challenge
        // token also expires after 5 minutes, which caps guesses per sign-in.
        RateLimiter::for('mfa', fn (Request $r) => Limit::perMinute(6)->by(($r->user()?->id ?? $r->ip()) . '|' . $r->path()));
    }
}
