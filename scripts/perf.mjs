#!/usr/bin/env node
// DEV ONLY. Board load timing for the "Load test N" workspace (seed-dev --sites N).
// API: median/p95 of 20 calls per endpoint. UI: time until the board shows cards,
// with Chromium throttled to a "Fast 4G" profile (9 Mbps down, 1.5 Mbps up, 60 ms RTT) and 4x CPU slowdown.
import { createRequire } from 'node:module';
const require = createRequire(new URL('../web/package.json', import.meta.url));
const { chromium } = require('playwright-core');
const B = process.env.UPFIXY_WEB ?? 'http://127.0.0.1:5173';
const j = await (await fetch(B + '/api/v1/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email: 'owner@demo.upfixy.test', password: 'demo-password-123' }) })).json();
const ws = (await (await fetch(B + '/api/v1/workspaces', { headers: { Authorization: 'Bearer ' + j.token, Accept: 'application/json' } })).json()).data.find((w) => w.name.startsWith('Load test'));
if (!ws) throw new Error('Run: node scripts/seed-dev.mjs --sites 200');
const H = { Authorization: 'Bearer ' + j.token, Accept: 'application/json', 'X-Workspace-Id': ws.id };
const stats = (xs) => { const s = [...xs].sort((a, b) => a - b); return { median: s[Math.floor(s.length / 2)], p95: s[Math.ceil(s.length * 0.95) - 1] }; };
console.log(`Workspace: ${ws.name}`);
for (const ep of ['boards/websites', 'boards/clients', 'overview', 'tickets']) {
  const t = [];
  for (let i = 0; i < 20; i++) { const s = performance.now(); const r = await fetch(`${B}/api/v1/${ep}`, { headers: H }); await r.text(); t.push(performance.now() - s); }
  const { median, p95 } = stats(t);
  console.log(`API  ${ep.padEnd(16)} median ${median.toFixed(0)} ms  p95 ${p95.toFixed(0)} ms`);
}
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium' });
for (const [path, sel] of [['/websites', '.card'], ['/clients', '.card'], ['/', '.lanebar']]) {
  const t = [];
  for (let i = 0; i < 5; i++) {
    const c = await b.newContext({ viewport: { width: 390, height: 844 } });
    await c.addInitScript(([tk, w]) => { localStorage.setItem('upfixy.token', tk); localStorage.setItem('upfixy.workspace', w); }, [j.token, ws.id]);
    const p = await c.newPage();
    const cdp = await c.newCDPSession(p);
    await cdp.send('Network.enable');
    await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 60, downloadThroughput: 9e6 / 8, uploadThroughput: 1.5e6 / 8 });
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    const s = Date.now();
    await p.goto(B + path);
    await p.waitForSelector(sel, { timeout: 30000 });
    t.push(Date.now() - s);
    await c.close();
  }
  const { median, p95 } = stats(t);
  console.log(`UI   ${path.padEnd(16)} median ${median} ms  worst ${p95} ms  (Fast 4G, 4x CPU, phone)`);
}
await b.close();
