<?php
// Test-only HTTP routes for CurlFetcher tests (php -S 127.0.0.1:<port> http-router.php).
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/ok') { header('Content-Type: text/html'); echo 'hello'; return; }
if ($p === '/redirect') { header('Location: /ok', true, 302); return; }
if ($p === '/big') { echo str_repeat('x', 300000); return; }
if ($p === '/slow') { sleep(4); echo 'late'; return; }
if ($p === '/cookie') { setcookie('s', 'x'); http_response_code(isset($_SERVER['HTTP_COOKIE']) ? 400 : 204); return; }
if ($p === '/headers') { header('X-Seen-Host: ' . $_SERVER['HTTP_HOST']); echo $_SERVER['HTTP_USER_AGENT'] ?? ''; return; }
http_response_code(503); echo 'down';
