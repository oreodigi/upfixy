#!/usr/bin/env node
// DEV ONLY. Seeds a realistic local workspace for screenshots, UX review and
// performance checks. Refuses to touch a non-local database.
//
//   node scripts/seed-dev.mjs                 # demo agency + solo workspace
//   node scripts/seed-dev.mjs --sites 200     # also a 200-site agency for NFR-22
//
// Sign in as owner@demo.upfixy.test / demo-password-123 (no MFA), or
// tech@ / client@ with the same password.
import { execFileSync } from 'node:child_process';
import pg from 'pg';

const URL = process.env.UPFIXY_OWNER_URL ?? 'postgres://upfixy_owner:devonly@127.0.0.1:5432/upfixy_dev';
if (!/127\.0\.0\.1|localhost/.test(URL)) throw new Error('seed-dev refuses non-local databases');
const bigSites = Number(process.argv[process.argv.indexOf('--sites') + 1] || 0);
const PASSWORD = 'demo-password-123';
const hash = execFileSync('php', ['-r', `echo password_hash('${PASSWORD}', PASSWORD_BCRYPT, ['cost' => 10]);`]).toString();

const db = new pg.Client({ connectionString: URL });
await db.connect();
const one = async (sql, args) => (await db.query(sql, args)).rows[0];

await db.query('BEGIN');
// Remove a previous seed (cascades through memberships, sites, tickets...).
await db.query("ALTER TABLE audit_events DISABLE TRIGGER USER; ALTER TABLE quotes DISABLE TRIGGER USER; ALTER TABLE approvals DISABLE TRIGGER USER");
const demoWs = "SELECT id FROM workspaces WHERE name LIKE 'Demo %' OR name LIKE 'Load test %'";
for (const t of ['approvals', 'quotes']) await db.query(`DELETE FROM ${t} WHERE workspace_id IN (${demoWs})`);
await db.query(`DELETE FROM workspaces WHERE id IN (${demoWs})`);
await db.query("DELETE FROM personal_access_tokens WHERE tokenable_id IN (SELECT id FROM users WHERE email LIKE '%@demo.upfixy.test')");
await db.query("DELETE FROM users WHERE email LIKE '%@demo.upfixy.test'");
await db.query("ALTER TABLE audit_events ENABLE TRIGGER USER; ALTER TABLE quotes ENABLE TRIGGER USER; ALTER TABLE approvals ENABLE TRIGGER USER");

const user = async (name) => (await one(
  "INSERT INTO users(email, password_hash, email_verified_at) VALUES ($1, $2, now()) RETURNING id", [`${name}@demo.upfixy.test`, hash])).id;
const owner = await user('owner');
const tech = await user('tech');
const clientUser = await user('client');

async function workspace(name, kind) {
  const ws = (await one('INSERT INTO workspaces(name, kind) VALUES ($1, $2) RETURNING id', [name, kind])).id;
  await db.query("INSERT INTO memberships(workspace_id, user_id, role) VALUES ($1, $2, 'owner')", [ws, owner]);
  return ws;
}

/** Plausible redacted evidence for a demo reading, shaped like the probe worker's. */
function evidence(kind, host, code) {
  if (kind === 'http') {
    const status = code === 'HTTP_OK' ? 200 : Number(code.replace('HTTP_', '')) || null;
    return status ? { chain: [{ url: `https://${host}/`, ip: '203.0.113.10', status, ms: 340 }], status, ms_total: 340, ms_ttfb: 280, ms_connect: 40, bytes: 48213, content_type: 'text/html; charset=UTF-8', attempts: 1, outcome: status >= 500 ? 'target_failure' : 'ok' }
      : { chain: [{ url: `https://${host}/`, ip: '203.0.113.10', error: code }], attempts: 3, outcome: 'target_failure' };
  }
  if (kind === 'tls') {
    const days = code === 'TLS_EXPIRING' ? 9 : 64;
    return { ip: '203.0.113.10', subject_cn: host, issuer_cn: 'R11', sans: [host, `www.${host.replace(/^www\./, '')}`], not_before: new Date(Date.now() - 26 * 864e5).toISOString(),
      not_after: new Date(Date.now() + days * 864e5).toISOString(), days_left: days, sha256: 'AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89', chain_length: 2, protocol: 'TLSv1.3', verified: true, attempts: 1 };
  }
  const answered = code === 'DNS_OK';
  const r = (rcode) => ({ status: 'answered', rcode, ad: false, ms: 18, addresses: answered ? 1 : 0, cname: [] });
  return { resolvers: { '1.1.1.1': r(answered ? 'NOERROR' : 'SERVFAIL'), '8.8.8.8': r(answered ? 'NOERROR' : 'SERVFAIL'), '9.9.9.9': r(answered ? 'NOERROR' : 'SERVFAIL') },
    dnssec: 'not_validated', ...(answered ? { addresses: ['203.0.113.10'] } : {}), attempts: 1 };
}

/** severity per check, minutes ago (null = never checked) */
async function site(ws, client, host, readings, { active = true } = {}) {
  const w = (await one('INSERT INTO websites(workspace_id, client_id, display_name) VALUES ($1, $2, $3) RETURNING id', [ws, client, host])).id;
  // Seeded readings are cycle 1; the probe worker continues from cycle 2 in 5 minutes.
  const t = (await one(`INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, state, last_cycle_no, next_check_at)
                        VALUES ($1, $2, 'https', $3, 443, $4, 1, now() + interval '5 minutes') RETURNING id`, [ws, w, host, active ? 'active' : 'pending_validation'])).id;
  const seen = readings.filter(([, , mins]) => mins !== null);
  if (seen.length) {
    const mins = Math.max(...seen.map(([, , m]) => m));
    const failed = seen.some(([, sev]) => sev === 'critical');
    const run = (await one(`INSERT INTO check_runs(workspace_id, monitor_target_id, cycle_no, scheduled_at, started_at, finished_at, worker, worker_version, region, outcome, attempts)
                            VALUES ($1, $2, 1, now() - make_interval(mins => $3), now() - make_interval(mins => $3), now() - make_interval(mins => $3),
                                    'seed', 'seed', 'dev-local', $4, 1) RETURNING id`, [ws, t, mins, failed ? 'target_failure' : 'ok'])).id;
    for (const [kind, sev, m, code] of seen) {
      await db.query(`INSERT INTO observations(workspace_id, monitor_target_id, check_kind, cycle_no, severity, result_code, observed_at, detail, check_run_id, region)
                      VALUES ($1, $2, $3, 1, $4, $5, now() - make_interval(mins => $6), $7, $8, 'dev-local')`,
        [ws, t, kind, sev, code, m, JSON.stringify(evidence(kind, host, code)), run]);
    }
  }
  return { w, t };
}
const ok = (mins = 2) => [['http', 'ok', mins, 'HTTP_OK'], ['tls', 'ok', mins, 'TLS_OK'], ['dns', 'ok', mins, 'DNS_OK']];

// ------------------------------------------------------------ demo agency
const agency = await workspace('Demo Agency', 'agency');
await db.query("INSERT INTO memberships(workspace_id, user_id, role) VALUES ($1, $2, 'technician'), ($1, $3, 'client_owner')", [agency, tech, clientUser]);
const client = async (name) => (await one('INSERT INTO clients(workspace_id, name) VALUES ($1, $2) RETURNING id', [agency, name])).id;
const mitra = await client('Mitra Traders');
const kalaghar = await client('Kalaghar Jewels');
const greenfield = await client('Greenfield School');
const anand = await client('Anand Chemicals');
const skyline = await client('Skyline Realty');
const fresh = await client('New Client Store');
for (const c of [mitra, kalaghar, skyline]) {
  await db.query('INSERT INTO client_assignments(workspace_id, client_id, user_id) VALUES ($1, $2, $3)', [agency, c, tech]);
}
await db.query('INSERT INTO client_assignments(workspace_id, client_id, user_id) VALUES ($1, $2, $3)', [agency, mitra, clientUser]);

const shop = await site(agency, mitra, 'shop.mitratraders.in', [['http', 'ok', 2, 'HTTP_OK'], ['tls', 'warning', 2, 'TLS_EXPIRING'], ['dns', 'ok', 2, 'DNS_OK']]);
await site(agency, mitra, 'mitratraders.in', ok(3));
await site(agency, mitra, 'www.mitratraders.in', ok(3));
const kj = await site(agency, kalaghar, 'kalaghar-jewels.com', [['http', 'critical', 4, 'HTTP_503'], ['tls', 'ok', 4, 'TLS_OK'], ['dns', 'ok', 4, 'DNS_OK']]);
await site(agency, kalaghar, 'booking.kalaghar-jewels.com', ok(4));
await site(agency, greenfield, 'greenfield-school.edu.in', ok(47));
await site(agency, anand, 'anandchem.co.in', ok(1));
await site(agency, anand, 'portal.anandchem.co.in', ok(1));
await site(agency, skyline, 'skyline-realty.in', [['http', 'unknown', 6, 'HTTP_NOT_CHECKED'], ['tls', 'unknown', 6, 'TLS_NOT_CHECKED'], ['dns', 'critical', 6, 'DNS_SERVFAIL']]);
await site(agency, fresh, 'newclient-store.in', [['http', 'ok', null], ['tls', 'ok', null], ['dns', 'ok', null]], { active: false });
await site(agency, null, 'demo-agency.in', ok(2));

// M4: a verified ownership proof for shop.mitratraders.in and a read-only WHM reseller connection
// (dummy sealed token: the dev connector worker can't open it, which is fine for the demo).
await db.query(`INSERT INTO ownership_claims(workspace_id, website_id, host, method, nonce, status, verified_at, expires_at, challenge_expires_at)
  VALUES ($1, $2, 'shop.mitratraders.in', 'dns_txt', 'demo-nonce-' || gen_random_uuid(), 'verified', now() - interval '1 day', now() + interval '89 days', now())`, [agency, shop.w]);
const whm = (await one(`INSERT INTO connectors(workspace_id, kind, label, base_host, username, secret_sealed, declared_capabilities, verified_capabilities,
    status, resource_scope, created_by, last_success_at, next_sync_at, sync_no)
  VALUES ($1, 'whm_reseller', 'Larihost reseller (demo)', 'panel.demo-reseller.test', 'demores', 'v1:demo-not-a-real-token',
    connector_declared('whm_reseller'), connector_declared('whm_reseller'), 'active', '{"reseller":"demores","accounts":3}', $2, now() - interval '20 minutes',
    now() + interval '40 minutes', 1) RETURNING id`, [agency, owner])).id;
const asset = (kind, ext, name, parent, facts, websiteId = null) => db.query(`INSERT INTO connector_assets(workspace_id, connector_id, kind, external_id, name,
  parent_external_id, facts, last_seen_sync, import_state, website_id) VALUES ($1, $2, $3, $4, $5, $6, $7, 1, $8, $9)`,
  [agency, whm, kind, ext, name, parent, JSON.stringify(facts), websiteId ? 'imported' : 'discovered', websiteId]);
const sslUntil = (d) => ({ not_after: new Date(Date.now() + d * 864e5).toISOString(), self_signed: false, issuer: "Let's Encrypt" });
await asset('account', 'mitrashop', 'shop.mitratraders.in', null, { suspended: false, plan: 'demores_basic', disk_used: '840M', disk_limit: '2048M' }, shop.w);
await asset('domain', 'shop.mitratraders.in', 'shop.mitratraders.in', 'mitrashop', { ssl: sslUntil(9), ssl_known: true }, shop.w);
await asset('account', 'sunrise', 'sunrise-bakery.in', null, { suspended: false, plan: 'demores_basic', disk_used: '210M', disk_limit: '1024M' });
await asset('domain', 'sunrise-bakery.in', 'sunrise-bakery.in', 'sunrise', { ssl: sslUntil(61), ssl_known: true });
await asset('domain', 'orders.sunrise-bakery.in', 'orders.sunrise-bakery.in', 'sunrise', { ssl: null, ssl_known: true });
await asset('account', 'oldclient', 'old-client.in', null, { suspended: true, suspend_reason: 'Unpaid invoice', plan: 'demores_basic' });
await asset('domain', 'old-client.in', 'old-client.in', 'oldclient', { ssl: sslUntil(-3), ssl_known: true });

// M6: an AMC coverage contract for Mitra Traders (₹0 provider-covered work). No merchant
// profile is seeded: Razorpay test keys are entered by the owner under Billing.
await db.query(`INSERT INTO coverage_contracts(workspace_id, client_id, reference, scope, valid_from, valid_until, created_by)
  VALUES ($1, $2, 'AMC-2026-MITRA', 'Annual hosting maintenance: server-side faults on Mitra Traders sites are fixed at no charge.',
          current_date - 90, current_date + 275, $3)`, [agency, mitra, owner]);

// Tickets in several stages.
const ticket = async (w, title, ...states) => {
  const id = (await one("INSERT INTO tickets(workspace_id, website_id, title) VALUES ($1, $2, $3) RETURNING id", [agency, w, title])).id;
  for (const s of states) await db.query('UPDATE tickets SET state = $2, version = version + 1 WHERE id = $1', [id, s]);
  return id;
};
const kj503 = await ticket(kj.w, 'Homepage returns 503', 'investigating');

// M7: a scoped Cloudflare connection (dummy sealed value, unusable) and an executor-read preview.
const zone = 'a'.repeat(32), rec = 'b'.repeat(32);
const conn = (await one(`INSERT INTO dns_connections(workspace_id, website_id, provider, zone_id, zone_name, secret_sealed, created_by, verified_at, verify_result)
  VALUES ($1, $2, 'cloudflare', $3, 'kalaghar-jewels.com', 'v1:demo-not-a-real-token', $4, now() - interval '2 hours', 'active') RETURNING id`, [agency, kj.w, zone, owner])).id;
const plan = {
  version: 'repair-plan-v1', operation: 'cloudflare.dns.update_address_record', connection_id: conn, zone_id: zone, zone_name: 'kalaghar-jewels.com',
  record_id: rec, record_name: 'kalaghar-jewels.com', record_type: 'A',
  before: { content: '93.184.216.34', ttl: 1, proxied: false }, after: { content: '93.184.216.35', ttl: 1, proxied: false },
  verification: { resolve: 'kalaghar-jewels.com', expect: '93.184.216.35', http_target_id: kj.t, http_was_ok: false },
  rollback: 'If public DNS does not show the new address, or the website stops responding because of this change, the old address is put back automatically.',
};
await db.query(`INSERT INTO repair_drafts(workspace_id, ticket_id, monitor_target_id, connection_id, record_id, proposed_content, state, plan, requested_by, read_at)
  VALUES ($1, $2, $3, $4, $5, '93.184.216.35', 'ready', $6, $7, now())`, [agency, kj503, kj.t, conn, rec, JSON.stringify(plan), owner]);
const ssl = await ticket(shop.w, 'Renew SSL certificate', 'investigating');
await db.query(`INSERT INTO quotes(workspace_id, ticket_id, monitor_target_id, action_code, description, amount_paise, issued_by, fingerprint, expires_at)
                VALUES ($1, $2, $3, 'cpanel.ssl_fix', 'Re-run AutoSSL and confirm the certificate covers shop.mitratraders.in', 0, $4, repeat('0', 64), NULL)`,
  [agency, ssl, shop.t, owner]);
await db.query("UPDATE tickets SET state = 'quote_pending', version = version + 1 WHERE id = $1", [ssl]);
await ticket(shop.w, 'Contact form not sending', 'waiting_for_customer');
await ticket(kj.w, 'Slow product pages');

// Incidents (M3): one open, one resolved yesterday, with in-app alerts.
const incident = async (w, t, code, root, openedMins, resolvedMins = null) => (await one(
  `INSERT INTO incidents(workspace_id, website_id, monitor_target_id, status, root_kind, result_code, opened_at, first_failure_at, last_failure_at, resolved_at, recovered_cycle)
   VALUES ($1, $2, $3, $4, $5, $6, now() - make_interval(mins => $7), now() - make_interval(mins => $7 + 10), now() - make_interval(mins => coalesce($8, 4)),
           now() - make_interval(mins => $8), CASE WHEN $8 IS NULL THEN NULL ELSE 1 END) RETURNING id`,
  [agency, w, t, resolvedMins === null ? 'open' : 'resolved', root, code, openedMins, resolvedMins])).id;
const kjInc = await incident(kj.w, kj.t, 'HTTP_503', 'http', 14);
const sky = (await one("SELECT w.id AS w, t.id AS t FROM websites w JOIN monitor_targets t ON t.website_id = w.id WHERE w.display_name = 'skyline-realty.in'"));
const skyInc = await incident(sky.w, sky.t, 'DNS_SERVFAIL', 'dns', 16);
const oldInc = await incident(shop.w, shop.t, 'HTTP_TIMEOUT', 'http', 26 * 60, 25 * 60);
for (const [i, kind] of [[kjInc, 'opened'], [skyInc, 'opened'], [oldInc, 'opened'], [oldInc, 'resolved']]) {
  await db.query("INSERT INTO incident_events(workspace_id, incident_id, kind) VALUES ($1, $2, $3)", [agency, i, kind]);
}
for (const u of [owner, tech]) {
  for (const [i, kind] of [[kjInc, 'incident_opened'], [skyInc, 'incident_opened'], [oldInc, 'incident_resolved']]) {
    await db.query('INSERT INTO notifications(workspace_id, user_id, incident_id, kind, read_at) VALUES ($1, $2, $3, $4, $5)',
      [agency, u, i, kind, kind === 'incident_resolved' ? new Date() : null]);
  }
}

// ------------------------------------------------------- solo workspace
const solo = await workspace('Demo Solo', 'solo');
await site(solo, null, 'my-portfolio.in', [['http', 'ok', 3, 'HTTP_OK'], ['tls', 'warning', 3, 'TLS_EXPIRING'], ['dns', 'ok', 3, 'DNS_OK']]);

// -------------------------------------------------- load-test workspace
if (bigSites > 0) {
  const big = await workspace(`Load test ${bigSites}`, 'agency');
  const sevs = ['ok', 'ok', 'ok', 'ok', 'warning', 'critical'];
  const clients = [];
  for (let i = 0; i < Math.ceil(bigSites / 5); i++) {
    clients.push((await one('INSERT INTO clients(workspace_id, name) VALUES ($1, $2) RETURNING id', [big, `Client ${String(i + 1).padStart(3, '0')}`])).id);
  }
  for (let i = 0; i < bigSites; i++) {
    const s = sevs[i % sevs.length];
    await site(big, clients[i % clients.length], `site-${String(i + 1).padStart(3, '0')}.example.in`,
      [['http', s, i % 7 === 0 ? 40 : 3, 'HTTP'], ['tls', 'ok', 3, 'TLS'], ['dns', 'ok', 3, 'DNS']]);
  }
}

await db.query('COMMIT');
await db.end();
console.log(`Seeded. Sign in as owner@demo.upfixy.test / ${PASSWORD}${bigSites ? ` (plus "Load test ${bigSites}")` : ''}`);
