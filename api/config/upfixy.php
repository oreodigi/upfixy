<?php

return [
    // D-01: temporary development domain; links are generated from this (AC-D01-001).
    'frontend_url' => rtrim(env('UPFIXY_BASE_URL', 'http://127.0.0.1:5173'), '/'),

    'email_verification_minutes' => 24 * 60,
    'invitation_days' => 7,
    'mfa_fresh_minutes' => 15,

    // Outbound checks (ownership verification now, probes in M3) connect
    // directly to the pinned, validated IP. Set only if egress must go via a proxy;
    // pinning then happens at the proxy, which must enforce the same blocklist.
    'egress_proxy' => env('UPFIXY_EGRESS_PROXY'),


    // M3 public monitoring (MON-001..008, §17). REC defaults; capacity targets need load tests (D-22).
    'monitor' => [
        'region' => env('UPFIXY_PROBE_REGION', 'dev-local'),
        'worker_version' => env('UPFIXY_WORKER_VERSION', 'dev'),
        // Independent public resolvers (EXT). Two or more are required for agreement checks.
        'resolvers' => array_values(array_filter(explode(',', env('UPFIXY_DNS_RESOLVERS', '1.1.1.1,8.8.8.8,9.9.9.9')))),
        // Well-known names used to tell "our probe is broken" from "the site is down".
        'canaries' => array_values(array_filter(explode(',', env('UPFIXY_PROBE_CANARIES', 'one.one.one.one,dns.google')))),
        'user_agent' => env('UPFIXY_PROBE_UA', 'UpfixyMonitor/1.0 (+' . rtrim(env('UPFIXY_BASE_URL', 'http://127.0.0.1:5173'), '/') . '/about-monitoring)'),
        'retry_delays_ms' => [2000, 5000],
        'lease_seconds' => 120,
        'claim_limit' => 10,
        'per_workspace' => 5,
        'max_claims' => 3,          // a cycle claimed this often without finishing is recorded as a probe error
        'http_connect_timeout' => 5,
        'http_total_timeout' => 15,
        'http_max_bytes' => 65536,
        'tls_timeout' => 8,
        'dns_timeout' => 2.0,
    ],
];
