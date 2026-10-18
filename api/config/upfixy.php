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
];
