#!/usr/bin/env node
// DEV ONLY. Signs in to the running app, captures screens at phone and desktop
// width, and runs an axe accessibility audit on each (WCAG 2.1 A/AA rules).
//   node scripts/screens.mjs [outDir]     (needs npm run api + npm run dev + seed-dev)
// Exit code 1 if any serious or critical accessibility violation is found.
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const require = createRequire(new URL('../web/package.json', import.meta.url));
const { chromium } = require('playwright-core');
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const BASE = process.env.UPFIXY_WEB ?? 'http://127.0.0.1:5173';
const OUT = process.argv[2] ?? 'screens';
const EXE = process.env.CHROMIUM_PATH ?? ['/opt/pw-browsers/chromium', '/usr/bin/chromium'].find(existsSync);
mkdirSync(OUT, { recursive: true });

async function login(email) {
  const r = await fetch(`${BASE}/api/v1/auth/login`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, password: 'demo-password-123' }),
  });
  const j = await r.json();
  if (!j.token) throw new Error(`login failed for ${email}: ${JSON.stringify(j)}`);
  const ws = await (await fetch(`${BASE}/api/v1/workspaces`, { headers: { Authorization: `Bearer ${j.token}`, Accept: 'application/json' } })).json();
  return { token: j.token, workspaces: ws.data };
}

// 320 px is the narrowest supported width (NFR-23).
const VIEWPORTS = { narrow: { width: 320, height: 640 }, mobile: { width: 390, height: 844 }, desktop: { width: 1440, height: 900 } };

// [name, user, workspace name, steps]
const SCREENS = [
  ['signin', null, null, []],
  ['agency-overview', 'owner', 'Demo Agency', []],
  ['agency-clients', 'owner', 'Demo Agency', [{ goto: '/clients' }]],
  ['agency-client-detail', 'owner', 'Demo Agency', [{ goto: '/clients' }, { link: 'Mitra Traders' }]],
  ['agency-websites', 'owner', 'Demo Agency', [{ goto: '/websites' }]],
  ['agency-websites-list', 'owner', 'Demo Agency', [{ goto: '/websites?lane=critical' }, { click: 'List' }]],
  ['agency-website-detail', 'owner', 'Demo Agency', [{ goto: '/websites' }, { link: /mitratraders/, nth: 0 }]],
  ['agency-website-ssl', 'owner', 'Demo Agency', [{ goto: '/websites' }, { link: /mitratraders/, nth: 0 }, { tab: 'SSL' }]],
  ['agency-website-activity', 'owner', 'Demo Agency', [{ goto: '/websites' }, { link: /mitratraders/, nth: 0 }, { tab: 'Activity' }, { click: 'Check now' }]],
  ['agency-alerts', 'owner', 'Demo Agency', [{ goto: '/alerts' }]],
  ['agency-diagnosis', 'owner', 'Demo Agency', [{ goto: '/websites' }, { link: /kalaghar-jewels\.com/, nth: 0 }, { click: 'Analyze & find solution' }]],
  ['client-owner-diagnosis', 'client', 'Demo Agency', [{ goto: '/websites' }, { link: /shop\.mitratraders/, nth: 0 }, { click: 'Analyze & find solution' }]],
  ['agency-work', 'owner', 'Demo Agency', [{ goto: '/work' }]],
  ['agency-ticket', 'owner', 'Demo Agency', [{ goto: '/work' }, { text: 'Renew SSL certificate', nth: 0 }]],
  ['agency-repair-preview', 'owner', 'Demo Agency', [{ goto: '/work' }, { text: 'Homepage returns 503', nth: 0 }, { waitFor: 'Send this change as a quote' }]],
  ['agency-website-dns-connection', 'owner', 'Demo Agency', [{ goto: '/websites' }, { link: /kalaghar-jewels\.com/, nth: 0 }, { tab: 'Domain' }, { waitFor: 'DNS provider connection' }]],
  ['agency-integrations', 'owner', 'Demo Agency', [{ goto: '/integrations' }]],
  ['agency-integration-preview', 'owner', 'Demo Agency', [{ goto: '/integrations' }, { link: 'Larihost reseller (demo)' }, { waitFor: 'Choose what to add' }]],
  ['agency-website-hosting', 'owner', 'Demo Agency', [{ goto: '/websites' }, { link: /shop\.mitratraders/, nth: 0 }, { tab: 'Hosting' }, { waitFor: 'From your provider connections' }]],
  ['agency-team', 'owner', 'Demo Agency', [{ goto: '/team' }]],
  ['settings', 'owner', 'Demo Agency', [{ goto: '/settings' }]],
  ['technician-overview', 'tech', 'Demo Agency', []],
  ['solo-websites', 'owner', 'Demo Solo', [{ goto: '/websites' }]],
  ['client-owner-websites', 'client', 'Demo Agency', [{ goto: '/websites' }]],
  ['client-owner-quote', 'client', 'Demo Agency', [{ goto: '/work' }, { text: 'Renew SSL certificate', nth: 0 }]],
  ...(process.env.SCREENS_EXTRA ? JSON.parse(process.env.SCREENS_EXTRA) : []),
];

const browser = await chromium.launch({ executablePath: EXE, headless: true });
const sessions = {};
let serious = 0;
const report = [];

for (const [name, who, wsName, steps] of SCREENS) {
  for (const [vpName, vp] of Object.entries(VIEWPORTS)) {
    const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 1, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
    if (who) {
      sessions[who] ??= await login(`${who}@demo.upfixy.test`);
      const s = sessions[who];
      const ws = s.workspaces.find((w) => w.name === wsName);
      await ctx.addInitScript(([t, w]) => {
        localStorage.setItem('upfixy.token', t);
        localStorage.setItem('upfixy.workspace', w);
      }, [s.token, ws.id]);
    }
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    for (const st of steps) {
      if (st.click) await page.getByRole('button', { name: st.click, exact: true }).first().click();
      if (st.link) await page.getByRole('link', { name: st.link }).nth(st.nth ?? 0).click();
      if (st.tab) await page.getByRole('tab', { name: st.tab, exact: true }).click();
      if (st.text) await page.getByText(st.text).nth(st.nth ?? 0).click();
      if (st.goto) await page.goto(BASE + st.goto, { waitUntil: 'networkidle' });
      if (st.waitFor) await page.getByText(st.waitFor).first().waitFor({ timeout: 10000 });
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(250);
    }
    const file = path.join(OUT, `${name}-${vpName}.png`);
    await page.screenshot({ path: file, fullPage: true });

    // Horizontal overflow at phone width is a layout bug (no sideways page scroll).
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    await page.addScriptTag({ content: axeSource });
    const axe = await page.evaluate(async () => (await window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    })).violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, sample: v.nodes[0]?.target?.join(' ') })));
    const bad = axe.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    serious += bad.length;
    report.push({ screen: `${name} (${vpName})`, overflow, errors, axe });
    console.log(`${name.padEnd(24)} ${vpName.padEnd(8)} overflow=${overflow}px  a11y=${axe.length ? axe.map((v) => `${v.id}[${v.impact}]x${v.nodes}`).join(', ') : 'clean'}${errors.length ? '  ERRORS: ' + errors.join(' | ') : ''}`);
    await ctx.close();
  }
}
await browser.close();
if (process.env.SCREENS_JSON) console.log(JSON.stringify(report, null, 1));
process.exit(serious > 0 ? 1 : 0);
