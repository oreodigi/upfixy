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

/** severity per check, minutes ago (null = never checked) */
async function site(ws, client, host, readings, { active = true } = {}) {
  const w = (await one('INSERT INTO websites(workspace_id, client_id, display_name) VALUES ($1, $2, $3) RETURNING id', [ws, client, host])).id;
  const t = (await one(`INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, state)
                        VALUES ($1, $2, 'https', $3, 443, $4) RETURNING id`, [ws, w, host, active ? 'active' : 'pending_validation'])).id;
  for (const [kind, sev, mins, code] of readings) {
    if (mins === null) continue;
    await db.query(`INSERT INTO observations(workspace_id, monitor_target_id, check_kind, cycle_no, severity, result_code, observed_at, detail)
                    VALUES ($1, $2, $3, 1, $4, $5, now() - make_interval(mins => $6), $7)`,
      [ws, t, kind, sev, code, mins, JSON.stringify({})]);
  }
  return { w, t };
}
const ok = (mins = 2) => [['http', 'ok', mins, 'HTTP_200'], ['tls', 'ok', mins, 'TLS_VALID'], ['dns', 'ok', mins, 'DNS_OK']];

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

const shop = await site(agency, mitra, 'shop.mitratraders.in', [['http', 'ok', 2, 'HTTP_200'], ['tls', 'warning', 2, 'TLS_EXPIRING'], ['dns', 'ok', 2, 'DNS_OK']]);
await site(agency, mitra, 'mitratraders.in', ok(3));
await site(agency, mitra, 'www.mitratraders.in', ok(3));
const kj = await site(agency, kalaghar, 'kalaghar-jewels.com', [['http', 'critical', 4, 'HTTP_503'], ['tls', 'ok', 4, 'TLS_VALID'], ['dns', 'ok', 4, 'DNS_OK']]);
await site(agency, kalaghar, 'booking.kalaghar-jewels.com', ok(4));
await site(agency, greenfield, 'greenfield-school.edu.in', ok(47));
await site(agency, anand, 'anandchem.co.in', ok(1));
await site(agency, anand, 'portal.anandchem.co.in', ok(1));
await site(agency, skyline, 'skyline-realty.in', [['http', 'critical', 6, 'HTTP_TIMEOUT'], ['tls', 'unknown', 6, 'TLS_INCONCLUSIVE'], ['dns', 'critical', 6, 'DNS_SERVFAIL']]);
await site(agency, fresh, 'newclient-store.in', [['http', 'ok', null], ['tls', 'ok', null], ['dns', 'ok', null]], { active: false });
await site(agency, null, 'demo-agency.in', ok(2));

// Tickets in several stages.
const ticket = async (w, title, ...states) => {
  const id = (await one("INSERT INTO tickets(workspace_id, website_id, title) VALUES ($1, $2, $3) RETURNING id", [agency, w, title])).id;
  for (const s of states) await db.query('UPDATE tickets SET state = $2, version = version + 1 WHERE id = $1', [id, s]);
  return id;
};
await ticket(kj.w, 'Homepage returns 503', 'investigating');
const ssl = await ticket(shop.w, 'Renew SSL certificate', 'investigating');
await db.query(`INSERT INTO quotes(workspace_id, ticket_id, monitor_target_id, action_code, description, amount_paise, issued_by, fingerprint, expires_at)
                VALUES ($1, $2, $3, 'cpanel.ssl_fix', 'Re-run AutoSSL and confirm the certificate covers shop.mitratraders.in', 0, $4, repeat('0', 64), NULL)`,
  [agency, ssl, shop.t, owner]);
await db.query("UPDATE tickets SET state = 'quote_pending', version = version + 1 WHERE id = $1", [ssl]);
await ticket(shop.w, 'Contact form not sending', 'waiting_for_customer');
await ticket(kj.w, 'Slow product pages');

// ------------------------------------------------------- solo workspace
const solo = await workspace('Demo Solo', 'solo');
await site(solo, null, 'my-portfolio.in', [['http', 'ok', 3, 'HTTP_200'], ['tls', 'warning', 3, 'TLS_EXPIRING'], ['dns', 'ok', 3, 'DNS_OK']]);

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
