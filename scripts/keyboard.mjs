#!/usr/bin/env node
// DEV ONLY. Keyboard-only checks for the main flows at phone and desktop width.
//   node scripts/keyboard.mjs   (needs npm run api + npm run dev + seed-dev)
import { createRequire } from 'node:module';
const require = createRequire('/home/claude/upfixy/web/package.json');
const { chromium } = require('playwright-core');
const B='http://127.0.0.1:5173';
const j = await (await fetch(B+'/api/v1/auth/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({email:'owner@demo.upfixy.test',password:'demo-password-123'})})).json();
const ws = (await (await fetch(B+'/api/v1/workspaces',{headers:{Authorization:'Bearer '+j.token,Accept:'application/json'}})).json()).data.find(w=>w.name==='Demo Agency');
const b = await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
const results = [];
const ok = (name, cond) => { results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}`); };
for (const vp of [{width:390,height:844},{width:1440,height:900}]) {
  const c = await b.newContext({viewport:vp});
  await c.addInitScript(([t,w])=>{localStorage.setItem('upfixy.token',t);localStorage.setItem('upfixy.workspace',w)},[j.token,ws.id]);
  const p = await c.newPage();
  const tag = vp.width < 900 ? 'mobile' : 'desktop';
  await p.goto(B+'/', {waitUntil:'networkidle'});
  // 1. Skip link is the first tab stop and moves focus to main.
  await p.keyboard.press('Tab');
  ok(`${tag}: first Tab lands on skip link`, await p.evaluate(()=>document.activeElement?.className==='skip'));
  await p.keyboard.press('Enter');
  // 2. Reach "Work" nav link by keyboard and open it.
  let found=false;
  for (let i=0;i<80 && !found;i++){ await p.keyboard.press('Tab'); found = await p.evaluate(()=>{const a=document.activeElement; return a?.tagName==='A' && a.textContent?.trim().endsWith('Work queue') && getComputedStyle(a).display!=='none';}); }
  ok(`${tag}: Work queue nav reachable by Tab`, found);
  if (found) { await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); }
  ok(`${tag}: Enter navigates to /work`, p.url().endsWith('/work'));
  // 3. Move a ticket with keyboard only: focus first "Move to" select, choose with arrows.
  let sel=false;
  for (let i=0;i<120 && !sel;i++){ await p.keyboard.press('Tab'); sel = await p.evaluate(()=>document.activeElement?.closest('.move')!==null); }
  ok(`${tag}: "Move to" control reachable by Tab`, sel);
  // 4. Ticket title opens dialog with Enter, Escape closes it and returns to /work.
  await p.goto(B+'/work',{waitUntil:'networkidle'});
  let t=false;
  for (let i=0;i<120 && !t;i++){ await p.keyboard.press('Tab'); t = await p.evaluate(()=>document.activeElement?.classList.contains('title-btn')); }
  ok(`${tag}: request title reachable by Tab`, t);
  await p.keyboard.press('Enter'); await p.waitForTimeout(400);
  ok(`${tag}: Enter opens request dialog`, await p.evaluate(()=>!!document.querySelector('dialog[open]')) && p.url().includes('ticket='));
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  ok(`${tag}: Escape closes dialog`, await p.evaluate(()=>!document.querySelector('dialog[open]')) && !p.url().includes('ticket='));
  // 5. Mobile More sheet.
  if (tag==='mobile') {
    await p.getByRole('button',{name:'More'}).focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
    ok('mobile: More opens sheet with Settings link', await p.getByRole('link',{name:/Settings/}).isVisible());
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
    ok('mobile: Escape closes More sheet', !(await p.locator('.sheet').count()));
  }
  // 6. Lane chips toggle with keyboard.
  await p.goto(B+'/websites',{waitUntil:'networkidle'});
  const chip = p.getByRole('button',{name:/^Critical/}).first(); await chip.focus(); await p.keyboard.press('Space'); await p.waitForTimeout(150);
  ok(`${tag}: Space toggles lane chip`, (await chip.getAttribute('aria-pressed'))==='true');
  // 7. Back button restores previous page.
  await p.goto(B+'/clients',{waitUntil:'networkidle'}); await p.getByRole('link',{name:'Mitra Traders'}).first().click(); await p.waitForLoadState('networkidle');
  await p.goBack(); await p.waitForTimeout(200);
  ok(`${tag}: browser Back returns to /clients`, p.url().endsWith('/clients'));
  // 8. Deep link renders directly.
  await p.goto(B+'/websites?lane=critical',{waitUntil:'networkidle'});
  ok(`${tag}: deep link ?lane=critical pre-selects chip`, (await p.getByRole('button',{name:/^Critical/}).first().getAttribute('aria-pressed'))==='true');
  // 9. M3: open evidence details and queue a live check from the keyboard.
  await p.goto(B+'/websites',{waitUntil:'networkidle'});
  await p.getByRole('link',{name:/mitratraders/}).first().focus(); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle');
  await p.getByRole('tab',{name:'Activity',exact:true}).focus(); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle');
  const sum = p.locator('details.evidence summary').first(); await sum.focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(150);
  ok(`${tag}: Enter opens evidence details`, await p.locator('details.evidence[open] .facts').first().isVisible());
  await p.getByRole('button',{name:'Check now'}).focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(500);
  ok(`${tag}: Check now reports what it did`, /queued|last 2 minutes/.test(await p.locator('.headline [role=status]').innerText()));
  // 9b. M5: Analyze from the keyboard opens the report, which has no "Fix now".
  await p.goto(B+'/websites',{waitUntil:'networkidle'});
  await p.getByRole('link',{name:/kalaghar-jewels\.com/}).first().focus(); await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle');
  await p.getByRole('button',{name:'Analyze & find solution'}).focus(); await p.keyboard.press('Enter');
  await p.waitForURL(/\/diagnostics\//, {timeout: 10000}).catch(() => undefined);
  await p.waitForSelector('#obs-h', {timeout: 10000}).catch(() => undefined);
  ok(`${tag}: Analyze opens the diagnostic report`, /\/diagnostics\//.test(p.url()) && await p.locator('#obs-h').isVisible());
  ok(`${tag}: report offers no "Fix now"`, (await p.getByRole('button',{name:/fix now/i}).count()) === 0);
  await c.close();
}
// 10. Acknowledge an incident with the keyboard (once; it changes data).
{
  const c = await b.newContext({viewport:{width:1440,height:900}});
  await c.addInitScript(([t,w])=>{localStorage.setItem('upfixy.token',t);localStorage.setItem('upfixy.workspace',w)},[j.token,ws.id]);
  const p = await c.newPage(); await p.goto(B+'/',{waitUntil:'networkidle'});
  const before = await p.getByRole('button',{name:'Acknowledge'}).count();
  if (before === 0) {
    results.push('SKIP  desktop: Enter acknowledges an incident (no unacknowledged incident; run seed-dev first)');
  } else {
    await p.getByRole('button',{name:'Acknowledge'}).first().focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(600);
    ok('desktop: Enter acknowledges an incident', (await p.getByRole('button',{name:'Acknowledge'}).count()) === before - 1);
  }
  await c.close();
}
await b.close();
console.log(results.join('\n'));
process.exit(results.some(r=>r.startsWith('FAIL'))?1:0);
