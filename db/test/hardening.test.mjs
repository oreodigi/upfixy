// M1 hardening: identity, tokens, ownership verification, delegations,
// quote-bound approvals, memberships and invitations. Real PostgreSQL 16.
// App assertions run as upfixy_app; verification runs as upfixy_worker.
import { describe, it, expect, beforeAll, afterAll, beforeEach } from 'vitest';
import { createHash, randomBytes } from 'node:crypto';
import pg from 'pg';
import { migrate } from '../migrate.mjs';

const BASE = process.env.UPFIXY_TEST_DB_BASE ?? 'postgres://%s:devonly@127.0.0.1:5432/upfixy_test';
const url = (role) => BASE.replace('%s', role);
const sha = (s) => createHash('sha256').update(s).digest('hex');
const token = () => randomBytes(32).toString('hex');

let owner, app, worker;
let id;

async function as(userId, wsId, fn, client = app) {
  await client.query('BEGIN');
  try {
    await client.query("SELECT set_config('app.user_id', $1, true), set_config('app.workspace_id', $2, true)",
      [userId ?? '', wsId ?? '']);
    const out = await fn(client);
    await client.query('COMMIT');
    return out;
  } catch (e) {
    await client.query('ROLLBACK');
    throw e;
  }
}
const one = async (q, sql, args) => (await q.query(sql, args)).rows[0];

beforeAll(async () => {
  await migrate({ reset: true, url: url('upfixy_owner'), log: () => {} });
  owner = new pg.Client({ connectionString: url('upfixy_owner') });
  app = new pg.Client({ connectionString: url('upfixy_app') });
  worker = new pg.Client({ connectionString: url('upfixy_worker') });
  await Promise.all([owner.connect(), app.connect(), worker.connect()]);
});

afterAll(async () => {
  await Promise.all([owner?.end(), app?.end(), worker?.end()]);
});

// Fresh fixtures per test: agency A (owner, admin, tech, client owner X, client owner Y),
// agency B, one client site with apex + www targets, one ticket.
beforeEach(async () => {
  await owner.query(`DO $$ DECLARE t text; BEGIN
    ALTER TABLE audit_events DISABLE TRIGGER USER; ALTER TABLE quotes DISABLE TRIGGER USER;
    FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public'
             AND tablename NOT IN ('schema_migrations', 'action_catalog') LOOP
      EXECUTE format('TRUNCATE %I RESTART IDENTITY CASCADE', t);
    END LOOP;
    ALTER TABLE audit_events ENABLE TRIGGER USER; ALTER TABLE quotes ENABLE TRIGGER USER; END $$`);
  id = {};
  const user = async (n, verified = true) => (await one(owner,
    "INSERT INTO users(email, password_hash, email_verified_at) VALUES ($1, '$2y$04$abcdefghijklmnopqrstuuJ1yG3W2x5bY6v7z8A9B0C1D2E3F4G5H', $2) RETURNING id",
    [`${n}@test.local`, verified ? new Date() : null])).id;
  for (const n of ['olive', 'adam', 'tina', 'carl', 'cara', 'bob']) id[n] = await user(n);
  id.unverified = await user('unverified', false);
  id.wsA = (await one(owner, "INSERT INTO workspaces(name, kind) VALUES ('A', 'agency') RETURNING id")).id;
  id.wsB = (await one(owner, "INSERT INTO workspaces(name, kind) VALUES ('B', 'agency') RETURNING id")).id;
  const m = (w, u, r) => owner.query('INSERT INTO memberships VALUES ($1, $2, $3)', [w, u, r]);
  await m(id.wsA, id.olive, 'owner'); await m(id.wsA, id.adam, 'admin'); await m(id.wsA, id.tina, 'technician');
  await m(id.wsA, id.carl, 'client_owner'); await m(id.wsA, id.cara, 'client_owner'); await m(id.wsB, id.bob, 'owner');
  id.clientX = (await one(owner, "INSERT INTO clients(workspace_id, name) VALUES ($1, 'X') RETURNING id", [id.wsA])).id;
  id.clientY = (await one(owner, "INSERT INTO clients(workspace_id, name) VALUES ($1, 'Y') RETURNING id", [id.wsA])).id;
  for (const [c, u] of [[id.clientX, id.tina], [id.clientX, id.carl], [id.clientY, id.cara]]) {
    await owner.query('INSERT INTO client_assignments(workspace_id, client_id, user_id) VALUES ($1,$2,$3)', [id.wsA, c, u]);
  }
  id.site = (await one(owner, "INSERT INTO websites(workspace_id, client_id, display_name) VALUES ($1, $2, 'shop.x.example') RETURNING id", [id.wsA, id.clientX])).id;
  id.apex = (await one(owner, "INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, state) VALUES ($1,$2,'https','x.example',443,'active') RETURNING id", [id.wsA, id.site])).id;
  id.www = (await one(owner, "INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, state) VALUES ($1,$2,'https','www.x.example',443,'active') RETURNING id", [id.wsA, id.site])).id;
  id.siteY = (await one(owner, "INSERT INTO websites(workspace_id, client_id, display_name) VALUES ($1, $2, 'y.example') RETURNING id", [id.wsA, id.clientY])).id;
  id.yTarget = (await one(owner, "INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, state) VALUES ($1,$2,'https','y.example',443,'active') RETURNING id", [id.wsA, id.siteY])).id;
  id.ticket = (await one(owner, "INSERT INTO tickets(workspace_id, website_id, title) VALUES ($1,$2,'t') RETURNING id", [id.wsA, id.site])).id;
});

/** Verify ownership of a host the way production does: API challenge, worker check. */
async function verifyHost(host, websiteId = id.site, userId = id.olive) {
  const ch = await as(userId, id.wsA, (c) => one(c, 'SELECT * FROM create_ownership_challenge($1, $2, $3)', [websiteId, host, 'dns_txt']));
  const status = (await one(worker, 'SELECT worker_record_claim_check($1, $2, $3) AS s', [ch.id, `upfixy-verify=${ch.nonce}`, 'TXT matched'])).s;
  expect(status).toBe('verified');
  return ch;
}

async function moveTicket(...states) {
  for (const s of states) await owner.query('UPDATE tickets SET state = $2, version = version + 1 WHERE id = $1', [id.ticket, s]);
}

async function issueQuote(userId = id.tina, extra = {}) {
  const q = { action: 'dns.record_fix', target: id.apex, amount: null, description: 'Fix A record', ...extra };
  return as(userId, id.wsA, (c) => one(c,
    `INSERT INTO quotes(workspace_id, ticket_id, monitor_target_id, action_code, description, amount_paise, issued_by, fingerprint, expires_at)
     VALUES ($1, $2, $3, $4, $5, $6, $7, repeat('0', 64), $8) RETURNING *`,
    [id.wsA, id.ticket, q.target, q.action, q.description, q.amount ?? 1, userId, q.expires ?? null]));
}

const approve = (userId, quote, fingerprint = quote.fingerprint) => as(userId, id.wsA, (c) => c.query(
  `INSERT INTO approvals(workspace_id, ticket_id, quote_id, fingerprint, approved_by, amount_paise, action_fingerprint, expires_at)
   VALUES ($1, $2, $3, $4, $5, 0, 'x', now() + interval '1 day') RETURNING *`,
  [id.wsA, id.ticket, quote.id, fingerprint, userId]));

const startWork = (userId = id.tina) => as(userId, id.wsA, (c) => c.query(
  "UPDATE tickets SET state = 'ready_for_work', version = version + 1 WHERE id = $1 RETURNING state", [id.ticket]));

// ======================================================================
describe('database roles', () => {
  it('runtime roles cannot bypass RLS, are not superusers and own nothing', async () => {
    const r = await owner.query(`SELECT rolname, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb
                                   FROM pg_roles WHERE rolname IN ('upfixy_app', 'upfixy_worker')`);
    for (const row of r.rows) expect(row).toMatchObject({ rolsuper: false, rolbypassrls: false, rolcreaterole: false, rolcreatedb: false });
    const owned = await owner.query(`SELECT count(*)::int AS n FROM pg_tables WHERE schemaname = 'public'
                                       AND tableowner IN ('upfixy_app', 'upfixy_worker')`);
    expect(owned.rows[0].n).toBe(0);
  });

  it('runtime roles cannot create objects in the schema', async () => {
    await expect(app.query('CREATE TABLE sneaky(x int)')).rejects.toThrow(/permission denied/);
    await expect(worker.query('CREATE FUNCTION sneaky() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$')).rejects.toThrow(/permission denied/);
  });

  it('no function is executable by PUBLIC', async () => {
    const r = await owner.query(`SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                                  WHERE n.nspname = 'public' AND has_function_privilege('public', p.oid, 'EXECUTE')
                                    AND p.proname NOT IN (SELECT extname FROM pg_extension) AND p.prokind = 'f'
                                    AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')`);
    expect(r.rows.map((x) => x.proname)).toEqual([]);
  });

  it('the app cannot call worker-only functions', async () => {
    await expect(app.query("SELECT worker_record_claim_check(gen_random_uuid(), 'x', 'x')")).rejects.toThrow(/permission denied/);
    await expect(app.query("SELECT worker_grant_delegation(gen_random_uuid(), now())")).rejects.toThrow(/permission denied/);
  });
});

describe('identity and tokens', () => {
  it('the app role can never read password hashes or MFA secrets, even its own', async () => {
    await expect(as(id.olive, null, (c) => c.query('SELECT password_hash FROM users WHERE id = $1', [id.olive])))
      .rejects.toThrow(/permission denied/);
    await expect(as(id.olive, null, (c) => c.query('SELECT mfa_secret_enc FROM users'))).rejects.toThrow(/permission denied/);
  });

  it('a user sees only their own user row; owners see co-members of the current workspace only', async () => {
    const mine = await as(id.tina, id.wsA, (c) => c.query('SELECT id FROM users'));
    expect(mine.rows.map((r) => r.id)).toEqual([id.tina]);
    const team = await as(id.olive, id.wsA, (c) => c.query('SELECT email FROM workspace_members ORDER BY email'));
    expect(team.rows.map((r) => r.email)).toEqual(['adam@test.local', 'cara@test.local', 'carl@test.local', 'olive@test.local', 'tina@test.local']);
  });

  it('a user cannot set their own email or mark it verified', async () => {
    await expect(as(id.unverified, null, (c) => c.query('UPDATE users SET email_verified_at = now() WHERE id = $1', [id.unverified])))
      .rejects.toThrow(/permission denied/);
  });

  it('token hashes are not selectable; expired tokens are never returned by lookup', async () => {
    const live = (await one(owner, "INSERT INTO personal_access_tokens(tokenable_type, tokenable_id, name, token, abilities, expires_at, created_at) VALUES ('App\\Models\\User', $1, 'web', $2, '[\"*\"]', now() + interval '1 day', now()) RETURNING id", [id.olive, sha('live')])).id;
    const dead = (await one(owner, "INSERT INTO personal_access_tokens(tokenable_type, tokenable_id, name, token, abilities, expires_at, created_at) VALUES ('App\\Models\\User', $1, 'web', $2, '[\"*\"]', now() - interval '1 minute', now()) RETURNING id", [id.olive, sha('dead')])).id;
    await expect(as(id.olive, null, (c) => c.query('SELECT token FROM personal_access_tokens'))).rejects.toThrow(/permission denied/);
    expect((await app.query('SELECT * FROM auth_token_lookup($1)', [live])).rows).toHaveLength(1);
    expect((await app.query('SELECT * FROM auth_token_lookup($1)', [dead])).rows).toHaveLength(0);
  });

  it('a user can only delete their own tokens', async () => {
    await owner.query("INSERT INTO personal_access_tokens(tokenable_type, tokenable_id, name, token, expires_at) VALUES ('App\\Models\\User', $1, 'web', $2, now() + interval '1 day')", [id.bob, sha('b')]);
    const r = await as(id.olive, null, (c) => c.query('DELETE FROM personal_access_tokens'));
    expect(r.rowCount).toBe(0);
  });

  it('new tokens must have an expiry', async () => {
    await expect(owner.query("INSERT INTO personal_access_tokens(tokenable_type, tokenable_id, name, token) VALUES ('x', $1, 'web', $2)", [id.olive, sha('n')]))
      .rejects.toThrow(/pat_expires/);
  });
});

describe('narrowed writes', () => {
  it('a new monitor target always starts pending; the app cannot activate it', async () => {
    const t = await as(id.olive, id.wsA, (c) => one(c,
      "INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, state) VALUES ($1,$2,'https','new.x.example',443,'active') RETURNING id, state",
      [id.wsA, id.site]));
    expect(t.state).toBe('pending_validation');
    await expect(as(id.olive, id.wsA, (c) => c.query("UPDATE monitor_targets SET state = 'active' WHERE id = $1", [t.id])))
      .rejects.toThrow(/permission denied/);
  });

  it('only the workspace owner can grant the agency payment authority for a client', async () => {
    await expect(as(id.adam, id.wsA, (c) => c.query('UPDATE clients SET agency_may_pay = true WHERE id = $1', [id.clientX])))
      .rejects.toThrow(/only the workspace owner/);
    const r = await as(id.olive, id.wsA, (c) => c.query('UPDATE clients SET agency_may_pay = true WHERE id = $1', [id.clientX]));
    expect(r.rowCount).toBe(1);
  });
});

// ======================================================================
describe('ownership verification (D-07)', () => {
  it('AC-AST-002.4: adding and publicly monitoring a site needs no proof', async () => {
    const w = await as(id.olive, id.wsA, (c) => one(c, "INSERT INTO websites(workspace_id, display_name) VALUES ($1, 'unproven.example') RETURNING id", [id.wsA]));
    expect(w.id).toBeTruthy();
  });

  it('the app cannot mark a claim verified or edit it directly', async () => {
    const ch = await as(id.olive, id.wsA, (c) => one(c, 'SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'dns_txt']));
    await expect(as(id.olive, id.wsA, (c) => c.query("UPDATE ownership_claims SET status = 'verified' WHERE id = $1", [ch.id])))
      .rejects.toThrow(/permission denied/);
    await expect(as(id.olive, id.wsA, (c) => c.query(
      "INSERT INTO ownership_claims(workspace_id, website_id, host, method, nonce, status, verified_at, expires_at, challenge_expires_at) VALUES ($1,$2,'x.example','dns_txt','n','verified',now(),now()+interval '1 year',now())",
      [id.wsA, id.site]))).rejects.toThrow(/permission denied/);
  });

  it('repeated requests reuse the live challenge instead of rotating the token', async () => {
    const a = await as(id.olive, id.wsA, (c) => one(c, 'SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'dns_txt']));
    const b = await as(id.olive, id.wsA, (c) => one(c, 'SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'dns_txt']));
    expect(b.id).toBe(a.id);
    expect(a.nonce).toMatch(/^[0-9a-f]{48}$/);
  });

  it('only hosts that are actually monitored on the website can be claimed', async () => {
    await expect(as(id.olive, id.wsA, (c) => c.query('SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'other.example', 'dns_txt'])))
      .rejects.toThrow(/not monitored/);
  });

  it('a client owner can verify their own client\'s site; another client owner cannot see it', async () => {
    await expect(as(id.carl, id.wsA, (c) => c.query('SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'html_file'])))
      .resolves.toBeDefined();
    await expect(as(id.cara, id.wsA, (c) => c.query('SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'html_file'])))
      .rejects.toThrow(/website not found/);
    await expect(as(id.tina, id.wsA, (c) => c.query('SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'html_file'])))
      .rejects.toThrow(/not allowed/);
  });

  it('another workspace cannot start a challenge on this website', async () => {
    await expect(as(id.bob, id.wsB, (c) => c.query('SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'dns_txt'])))
      .rejects.toThrow(/website not found/);
  });

  it('AC-AST-002.5: a wrong or reused token does not verify', async () => {
    const a = await as(id.olive, id.wsA, (c) => one(c, 'SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'dns_txt']));
    const b = await as(id.olive, id.wsA, (c) => one(c, 'SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'www.x.example', 'dns_txt']));
    const check = (claim, found) => one(worker, 'SELECT worker_record_claim_check($1, $2, $3) AS s', [claim, found, 'test']);
    expect((await check(a.id, 'upfixy-verify=wrong')).s).toBe('challenge_issued');
    expect((await check(b.id, `upfixy-verify=${a.nonce}`)).s).toBe('challenge_issued'); // A's proof can't verify B
    expect((await check(a.id, a.nonce)).s).toBe('challenge_issued');                  // bare nonce without prefix
    expect((await check(a.id, `upfixy-verify=${a.nonce}`)).s).toBe('verified');
    const row = await one(owner, 'SELECT status, expires_at > now() + interval \'89 days\' AS ttl_ok, check_count FROM ownership_claims WHERE id = $1', [a.id]);
    expect(row).toMatchObject({ status: 'verified', ttl_ok: true });
    expect(Number(row.check_count)).toBe(3); // wrong, bare nonce, correct (the 4th check was against claim B)
  });

  it('an expired challenge cannot be verified', async () => {
    const a = await as(id.olive, id.wsA, (c) => one(c, 'SELECT * FROM create_ownership_challenge($1, $2, $3)', [id.site, 'x.example', 'dns_txt']));
    await owner.query("UPDATE ownership_claims SET challenge_expires_at = now() - interval '1 second' WHERE id = $1", [a.id]);
    expect((await one(worker, 'SELECT worker_record_claim_check($1, $2, $3) AS s', [a.id, `upfixy-verify=${a.nonce}`, 'late'])).s).toBe('expired');
  });

  it('proof is per exact host: verifying the apex does not unlock www', async () => {
    await verifyHost('x.example');
    const r = await one(owner, 'SELECT site_private_access($1, $2, $3) AS apex, site_private_access($1, $4, $3) AS www',
      [id.wsA, id.apex, 'repair', id.www]);
    expect(r).toEqual({ apex: true, www: false });
  });

  it('proof does not cross workspaces', async () => {
    await verifyHost('x.example');
    expect((await one(owner, 'SELECT site_private_access($1, $2, $3) AS ok', [id.wsB, id.apex, 'read'])).ok).toBe(false);
  });

  it('AC-AST-002.2 / .6: revoked or lapsed proof hides private readings at once; public readings stay', async () => {
    const ch = await verifyHost('x.example');
    await owner.query(`INSERT INTO observations(workspace_id, monitor_target_id, check_kind, cycle_no, severity, source, result_code, observed_at)
                       VALUES ($1,$2,'http',1,'ok','public','200',now()), ($1,$2,'tls',1,'ok','connector','autossl_ok',now())`, [id.wsA, id.apex]);
    const visible = () => as(id.olive, id.wsA, async (c) => (await c.query('SELECT source::text FROM observations ORDER BY source::text')).rows.map((r) => r.source));
    expect(await visible()).toEqual(['connector', 'public']);
    await as(id.olive, id.wsA, (c) => c.query('SELECT revoke_ownership_claim($1)', [ch.id]));
    expect(await visible()).toEqual(['public']);
  });

  it('a verified claim past its 90-day lifetime no longer grants access', async () => {
    const ch = await verifyHost('x.example');
    await owner.query("UPDATE ownership_claims SET expires_at = now() - interval '1 second' WHERE id = $1", [ch.id]);
    expect((await one(owner, 'SELECT site_private_access($1, $2, $3) AS ok', [id.wsA, id.apex, 'repair'])).ok).toBe(false);
  });
});

describe('delegations', () => {
  const request = (userId, website, perms = ['read', 'repair']) => as(userId, id.wsA, (c) => one(c,
    "INSERT INTO delegations(workspace_id, website_id, grantor_label, permissions, status, expires_at) VALUES ($1,$2,'Larihost',$3,'granted',now()+interval '30 days') RETURNING id, status",
    [id.wsA, website, perms]));

  it('the app can request but not grant; a request grants nothing', async () => {
    const d = await request(id.olive, id.site);
    expect(d.status).toBe('requested');
    await expect(as(id.olive, id.wsA, (c) => c.query("UPDATE delegations SET status = 'granted' WHERE id = $1", [d.id])))
      .rejects.toThrow(/can only be revoked/);
    expect((await one(owner, 'SELECT site_private_access($1, $2, $3) AS ok', [id.wsA, id.apex, 'read'])).ok).toBe(false);
  });

  it('AC-AST-002.3 / .6: a granted delegation covers its website only, its permissions only, until revoked', async () => {
    const d = await request(id.olive, id.site, ['read']);
    await worker.query("SELECT worker_grant_delegation($1, now() + interval '30 days')", [d.id]);
    const access = async () => one(owner, `SELECT site_private_access($1, $2, 'read') AS read_s, site_private_access($1, $2, 'repair') AS repair_s,
                                                  site_private_access($1, $3, 'read') AS read_other`, [id.wsA, id.www, id.yTarget]);
    expect(await access()).toEqual({ read_s: true, repair_s: false, read_other: false });
    await as(id.olive, id.wsA, (c) => c.query("UPDATE delegations SET status = 'revoked' WHERE id = $1", [d.id]));
    expect((await access()).read_s).toBe(false);
  });

  it('only owners and admins can request delegations', async () => {
    await expect(request(id.tina, id.site)).rejects.toThrow(/row-level security/);
  });
});

// ======================================================================
describe('quotes and approvals', () => {
  it('the price comes from the catalogue, not from the caller; the fingerprint is server-made', async () => {
    await moveTicket('investigating');
    const q = await issueQuote(id.tina, { amount: 1 });
    expect(q.amount_paise).toBe('10000');
    expect(q.fingerprint).toMatch(/^[0-9a-f]{64}$/);
    expect(q.fingerprint).not.toBe('0'.repeat(64));
    expect(q.issued_by).toBe(id.tina);
  });

  it('custom work needs an explicit positive amount', async () => {
    await moveTicket('investigating');
    await expect(issueQuote(id.tina, { action: 'custom.quoted_work', amount: 0 })).rejects.toThrow(/positive amount/);
    expect((await issueQuote(id.tina, { action: 'custom.quoted_work', amount: 250000 })).amount_paise).toBe('250000');
  });

  it('unknown actions and addresses from another website are refused', async () => {
    await moveTicket('investigating');
    await expect(issueQuote(id.tina, { action: 'server.root_shell' })).rejects.toThrow(/foreign key|catalogue/);
    await expect(issueQuote(id.olive, { target: id.yTarget })).rejects.toThrow(/belong to the ticket/);
  });

  it('client users cannot issue quotes', async () => {
    await moveTicket('investigating');
    await expect(issueQuote(id.carl)).rejects.toThrow(/row-level security/);
  });

  it('quotes cannot be edited, only voided; deleting is impossible', async () => {
    await moveTicket('investigating');
    const q = await issueQuote();
    await expect(as(id.olive, id.wsA, (c) => c.query('UPDATE quotes SET amount_paise = 1 WHERE id = $1', [q.id])))
      .rejects.toThrow(/permission denied/);
    await expect(owner.query('UPDATE quotes SET amount_paise = 1 WHERE id = $1', [q.id])).rejects.toThrow(/cannot be edited/);
    await expect(owner.query('DELETE FROM quotes WHERE id = $1', [q.id])).rejects.toThrow(/append-only/);
  });

  it('AC-AST-002.7: verified ownership without an approval still cannot start work (₹0 included)', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    await issueQuote(id.tina, { action: 'provider.covered_fix' });
    await moveTicket('quote_pending');
    await expect(startWork()).rejects.toThrow(/no valid approval/);
  });

  it('D-07: an approval is refused while the quoted host is unverified', async () => {
    await moveTicket('investigating');
    const q = await issueQuote(id.tina, { action: 'provider.covered_fix' });
    await moveTicket('quote_pending');
    await expect(approve(id.carl, q)).rejects.toThrow(/not verified/);
  });

  it('the payer must echo the exact fingerprint they were shown', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    const q = await issueQuote(id.tina, { action: 'provider.covered_fix' });
    await moveTicket('quote_pending');
    await expect(approve(id.carl, q, 'f'.repeat(64))).rejects.toThrow(/fingerprint mismatch/);
  });

  it('approval terms come from the quote, never the request', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    const q = await issueQuote();
    await moveTicket('quote_pending');
    const a = (await approve(id.carl, q)).rows[0];
    expect(a.amount_paise).toBe('10000');
    expect(a.action_fingerprint).toBe(q.fingerprint);
    expect(new Date(a.expires_at).getTime()).toBe(new Date(q.expires_at).getTime());
    expect(a.payment_verified).toBe(false);
  });

  it('AC-SVC-003.3: ₹0 work starts with a live approval of the live quote and verified ownership', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    const q = await issueQuote(id.tina, { action: 'provider.covered_fix' });
    await moveTicket('quote_pending');
    await approve(id.carl, q);
    expect((await startWork()).rows[0].state).toBe('ready_for_work');
  });

  it('paid work waits for a verified payment that no app user can set', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    const q = await issueQuote();
    await moveTicket('quote_pending');
    await approve(id.carl, q);
    await expect(startWork()).rejects.toThrow(/no valid approval/);
    await expect(as(id.carl, id.wsA, (c) => c.query('UPDATE approvals SET payment_verified = true'))).rejects.toThrow(/permission denied/);
  });

  it('AC-SVC-003.1: a new quote voids the old quote and its approval', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    const q1 = await issueQuote(id.tina, { action: 'provider.covered_fix' });
    await moveTicket('quote_pending');
    await approve(id.carl, q1);
    const q2 = await issueQuote(id.tina, { action: 'provider.covered_fix', description: 'Different scope' });
    expect(q2.fingerprint).not.toBe(q1.fingerprint);
    const old = await one(owner, 'SELECT q.voided_at IS NOT NULL AS q_void, a.voided_at IS NOT NULL AS a_void FROM quotes q JOIN approvals a ON a.quote_id = q.id WHERE q.id = $1', [q1.id]);
    expect(old).toEqual({ q_void: true, a_void: true });
    await expect(startWork()).rejects.toThrow(/no valid approval/);
    await expect(approve(id.carl, q1)).rejects.toThrow(/no longer valid/);
  });

  it('AC-SVC-003.2: an expired quote cannot be approved or start work', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    const q = await issueQuote(id.tina, { action: 'provider.covered_fix', expires: new Date(Date.now() + 1500) });
    await moveTicket('quote_pending');
    await approve(id.carl, q);
    await new Promise((r) => setTimeout(r, 1600));
    await expect(startWork()).rejects.toThrow(/no valid approval/);
  });

  it('losing ownership proof after approval blocks the start of work', async () => {
    const ch = await verifyHost('x.example');
    await moveTicket('investigating');
    const q = await issueQuote(id.tina, { action: 'provider.covered_fix' });
    await moveTicket('quote_pending');
    await approve(id.carl, q);
    await as(id.olive, id.wsA, (c) => c.query('SELECT revoke_ownership_claim($1)', [ch.id]));
    await expect(startWork()).rejects.toThrow(/not verified/);
  });

  it('AC-SVC-003.4: payer rule — agency owner needs contract delegation; technicians and other clients never', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    const q = await issueQuote(id.tina, { action: 'provider.covered_fix' });
    await moveTicket('quote_pending');
    await expect(approve(id.olive, q)).rejects.toThrow(/authorised payer/);
    await expect(approve(id.tina, q)).rejects.toThrow(/authorised payer|row-level/);
    await expect(approve(id.cara, q)).rejects.toThrow(/quote not found/);   // other client: same answer as "doesn't exist"
    await expect(approve(id.bob, q)).rejects.toThrow(/quote not found/);    // other workspace
    await owner.query('UPDATE clients SET agency_may_pay = true WHERE id = $1', [id.clientX]);
    await expect(approve(id.olive, q)).resolves.toBeDefined();
  });

  it('approvals are only taken while the ticket awaits a quote decision', async () => {
    await verifyHost('x.example');
    await moveTicket('investigating');
    const q = await issueQuote(id.tina, { action: 'provider.covered_fix' });
    await expect(approve(id.carl, q)).rejects.toThrow(/awaiting a quote decision/);
  });
});

// ======================================================================
describe('memberships', () => {
  it('memberships cannot be inserted directly; only via workspace creation or invitation', async () => {
    await expect(as(id.olive, id.wsA, (c) => c.query("INSERT INTO memberships VALUES ($1, $2, 'admin')", [id.wsA, id.bob])))
      .rejects.toThrow(/permission denied/);
  });

  it('an admin cannot change or suspend an owner, or create one', async () => {
    await expect(as(id.adam, id.wsA, (c) => c.query('UPDATE memberships SET suspended_at = now() WHERE user_id = $1', [id.olive])))
      .rejects.toThrow(/only an owner/);
    await expect(as(id.adam, id.wsA, (c) => c.query("UPDATE memberships SET role = 'owner' WHERE user_id = $1", [id.tina])))
      .rejects.toThrow(/only an owner/);
  });

  it('the last owner cannot leave, be suspended or demoted', async () => {
    await expect(as(id.olive, id.wsA, (c) => c.query("UPDATE memberships SET role = 'admin' WHERE user_id = $1", [id.olive])))
      .rejects.toThrow(/at least one owner/);
  });

  it('suspension cuts access, client assignments and ticket assignments immediately', async () => {
    await owner.query('UPDATE tickets SET assignee_id = $2, version = version + 1 WHERE id = $1', [id.ticket, id.tina]);
    await as(id.olive, id.wsA, (c) => c.query('UPDATE memberships SET suspended_at = now() WHERE user_id = $1 AND workspace_id = $2', [id.tina, id.wsA]));
    expect((await as(id.tina, id.wsA, (c) => c.query('SELECT * FROM websites'))).rows).toHaveLength(0);
    expect((await one(owner, 'SELECT count(*)::int AS n FROM client_assignments WHERE user_id = $1', [id.tina])).n).toBe(0);
    expect((await one(owner, 'SELECT assignee_id FROM tickets WHERE id = $1', [id.ticket])).assignee_id).toBeNull();
  });

  it('members of another workspace are not visible or changeable', async () => {
    const r = await as(id.olive, id.wsA, (c) => c.query('UPDATE memberships SET suspended_at = now() WHERE user_id = $1', [id.bob]));
    expect(r.rowCount).toBe(0);
  });
});

describe('invitations', () => {
  const invite = (by, email, role, client = null, hash = sha(token())) => as(by, id.wsA, (c) => one(c,
    "INSERT INTO invitations(workspace_id, email, role, client_id, token_hash, invited_by, expires_at) VALUES ($1,$2,$3,$4,$5,$6, now() + interval '7 days') RETURNING id",
    [id.wsA, email, role, client, hash, by]));
  const accept = (userId, hash) => as(userId, null, (c) => one(c, 'SELECT accept_invitation($1) AS ws', [hash]));

  it('owners/admins invite; owner role can never be invited', async () => {
    await expect(invite(id.adam, 'new@test.local', 'technician')).resolves.toBeDefined();
    await expect(invite(id.olive, 'boss@test.local', 'owner')).rejects.toThrow(/row-level security|check constraint/);
  });

  it('a client owner can invite staff for their own client only; technicians cannot invite', async () => {
    await expect(invite(id.carl, 's1@test.local', 'client_staff', id.clientX)).resolves.toBeDefined();
    await expect(invite(id.carl, 's2@test.local', 'client_staff', id.clientY)).rejects.toThrow(/row-level security/);
    await expect(invite(id.carl, 's3@test.local', 'technician')).rejects.toThrow(/row-level security/);
    await expect(invite(id.tina, 's4@test.local', 'client_staff', id.clientX)).rejects.toThrow(/row-level security/);
  });

  it('client roles must name a client', async () => {
    await expect(invite(id.olive, 'c@test.local', 'client_owner')).rejects.toThrow(/check constraint/);
  });

  it('accepting needs the same, verified email; it adds membership and the client assignment once', async () => {
    const hash = sha(token());
    await invite(id.olive, 'bob@test.local', 'client_owner', id.clientX, hash);
    await expect(accept(id.tina, hash)).rejects.toThrow(/different email|already a member/);
    expect((await accept(id.bob, hash)).ws).toBe(id.wsA);
    const r = await one(owner, 'SELECT m.role, EXISTS (SELECT 1 FROM client_assignments a WHERE a.user_id = m.user_id AND a.client_id = $2) AS assigned FROM memberships m WHERE m.workspace_id = $1 AND m.user_id = $3', [id.wsA, id.clientX, id.bob]);
    expect(r).toEqual({ role: 'client_owner', assigned: true });
    await expect(accept(id.bob, hash)).rejects.toThrow(/not valid/);
  });

  it('unverified emails cannot accept; expired and revoked invitations do not work', async () => {
    const h1 = sha(token());
    await invite(id.olive, 'unverified@test.local', 'technician', null, h1);
    await expect(accept(id.unverified, h1)).rejects.toThrow(/verify your email/);

    const h2 = sha(token());
    const inv = await invite(id.olive, 'bob@test.local', 'technician', null, h2);
    await as(id.olive, id.wsA, (c) => c.query('UPDATE invitations SET revoked_at = now() WHERE id = $1', [inv.id]));
    await expect(accept(id.bob, h2)).rejects.toThrow(/not valid/);

    const h3 = sha(token());
    const inv3 = await invite(id.olive, 'bob@test.local', 'technician', null, h3);
    await owner.query("UPDATE invitations SET expires_at = now() - interval '1 second' WHERE id = $1", [inv3.id]);
    await expect(accept(id.bob, h3)).rejects.toThrow(/not valid/);
  });

  it('invitation token hashes are never readable by the app', async () => {
    await invite(id.olive, 'x@test.local', 'technician');
    await expect(as(id.olive, id.wsA, (c) => c.query('SELECT token_hash FROM invitations'))).rejects.toThrow(/permission denied/);
  });

  it('suspending a member revokes the invitations they sent', async () => {
    const inv = await invite(id.adam, 'x@test.local', 'technician');
    await as(id.olive, id.wsA, (c) => c.query('UPDATE memberships SET suspended_at = now() WHERE user_id = $1 AND workspace_id = $2', [id.adam, id.wsA]));
    expect((await one(owner, 'SELECT revoked_at IS NOT NULL AS r FROM invitations WHERE id = $1', [inv.id])).r).toBe(true);
  });
});

describe('email verification', () => {
  it('only the newest link works, once, before it expires', async () => {
    const t1 = token(); const t2 = token();
    await as(id.unverified, null, (c) => c.query('SELECT issue_email_verification($1, 60)', [sha(t1)]));
    await as(id.unverified, null, (c) => c.query('SELECT issue_email_verification($1, 60)', [sha(t2)]));
    expect((await one(app, 'SELECT confirm_email_verification($1) AS u', [sha(t1)])).u).toBeNull();
    expect((await one(app, 'SELECT confirm_email_verification($1) AS u', [sha(t2)])).u).toBe(id.unverified);
    expect((await one(app, 'SELECT confirm_email_verification($1) AS u', [sha(t2)])).u).toBeNull();
    expect((await one(owner, 'SELECT email_verified_at IS NOT NULL AS v FROM users WHERE id = $1', [id.unverified])).v).toBe(true);
  });

  it('expired links do nothing; verification tokens are invisible to the app', async () => {
    const t = token();
    await as(id.unverified, null, (c) => c.query('SELECT issue_email_verification($1, 60)', [sha(t)]));
    await owner.query("UPDATE email_verification_tokens SET expires_at = now() - interval '1 second'");
    expect((await one(app, 'SELECT confirm_email_verification($1) AS u', [sha(t)])).u).toBeNull();
    await expect(app.query('SELECT * FROM email_verification_tokens')).rejects.toThrow(/permission denied/);
  });
});

// Security audit, 18 Oct 2026 (docs/SECURITY.md S-01 to S-05).
describe('M1 audit fixes', () => {
  it('S-01: admins can rename a workspace but never change id, kind or provider flag', async () => {
    await as(id.adam, id.wsA, (c) => c.query("UPDATE workspaces SET name = 'A renamed' WHERE id = $1", [id.wsA]));
    expect((await one(owner, 'SELECT name FROM workspaces WHERE id = $1', [id.wsA])).name).toBe('A renamed');
    for (const sql of ['UPDATE workspaces SET provider_flag = true WHERE id = $1',
      "UPDATE workspaces SET kind = 'solo' WHERE id = $1",
      'UPDATE workspaces SET id = gen_random_uuid() WHERE id = $1']) {
      await expect(as(id.adam, id.wsA, (c) => c.query(sql, [id.wsA]))).rejects.toThrow(/permission denied/);
    }
    // A technician cannot rename (policy: owner/admin only).
    const r = await as(id.tina, id.wsA, (c) => c.query("UPDATE workspaces SET name = 'hijack' WHERE id = $1", [id.wsA]));
    expect(r.rowCount).toBe(0);
    expect((await one(owner, 'SELECT provider_flag FROM workspaces WHERE id = $1', [id.wsA])).provider_flag).toBe(false);
  });

  it('S-02: client assignments cannot be edited in place', async () => {
    await expect(as(id.olive, id.wsA, (c) => c.query('UPDATE client_assignments SET user_id = $1 WHERE user_id = $2', [id.adam, id.tina])))
      .rejects.toThrow(/permission denied/);
  });

  it('S-03: observations are append-only for the worker, the app and even via SECURITY DEFINER paths', async () => {
    await as(null, id.wsA, (c) => c.query(
      "INSERT INTO observations(workspace_id, monitor_target_id, check_kind, severity, result_code, observed_at, cycle_no) VALUES ($1,$2,'http','critical','HTTP_503',now(),1)",
      [id.wsA, id.apex]), worker);
    await expect(as(null, id.wsA, (c) => c.query("UPDATE observations SET severity = 'ok'"), worker)).rejects.toThrow(/permission denied/);
    await expect(as(null, id.wsA, (c) => c.query('DELETE FROM observations'), worker)).rejects.toThrow(/permission denied/);
    await expect(as(id.olive, id.wsA, (c) => c.query("UPDATE observations SET severity = 'ok'"))).rejects.toThrow(/permission denied/);
    // Even a role with table rights (here the owner, standing in for any future definer function) cannot UPDATE.
    await expect(owner.query("UPDATE observations SET severity = 'ok'")).rejects.toThrow(/append-only/);
    // The worker cannot insert into another workspace.
    await expect(as(null, id.wsA, (c) => c.query(
      "INSERT INTO observations(workspace_id, monitor_target_id, check_kind, severity, result_code, observed_at, cycle_no) VALUES ($1,$2,'http','ok','HTTP_200',now(),2)",
      [id.wsB, id.apex]), worker)).rejects.toThrow();
  });

  it('S-05: site_private_access only answers for the caller\'s own workspace', async () => {
    await verifyHost('x.example');
    expect((await as(id.olive, id.wsA, (c) => one(c, "SELECT site_private_access($1, $2, 'read') AS ok", [id.wsA, id.apex]))).ok).toBe(true);
    // Bob (workspace B) asks about A's target: always false, so existence of proof never leaks.
    expect((await as(id.bob, id.wsB, (c) => one(c, "SELECT site_private_access($1, $2, 'read') AS ok", [id.wsA, id.apex]))).ok).toBe(false);
    expect((await as(id.bob, null, (c) => one(c, "SELECT site_private_access($1, $2, 'read') AS ok", [id.wsA, id.apex]))).ok).toBe(false);
  });

  it('tickets cannot be deleted by the app', async () => {
    await expect(as(id.olive, id.wsA, (c) => c.query('DELETE FROM tickets'))).rejects.toThrow(/permission denied/);
  });
});

describe('workspace removal (NFR-14)', () => {
  it('a workspace with assigned work and pending invitations can be removed in one statement', async () => {
    await owner.query('UPDATE tickets SET assignee_id = $1, version = version + 1 WHERE id = $2', [id.tina, id.ticket]);
    await owner.query("INSERT INTO invitations(workspace_id, email, role, token_hash, invited_by, expires_at) VALUES ($1, 'x@test.local', 'technician', $2, $3, now() + interval '1 day')",
      [id.wsA, sha(token()), id.adam]);
    await owner.query('DELETE FROM workspaces WHERE id = $1', [id.wsA]);
    expect((await one(owner, 'SELECT count(*)::int AS n FROM tickets')).n).toBe(0);
    expect((await one(owner, 'SELECT count(*)::int AS n FROM workspaces WHERE id = $1', [id.wsB])).n).toBe(1);
  });

  it('commercial records block hard deletion: closure must be a retention-aware soft close (open decision D-12)', async () => {
    await moveTicket('investigating');
    await issueQuote();
    // Blocked either by the quote's immutable target reference or by the append-only quote itself.
    await expect(owner.query('DELETE FROM workspaces WHERE id = $1', [id.wsA])).rejects.toThrow(/append-only|violates foreign key/);
    expect((await one(owner, 'SELECT count(*)::int AS n FROM quotes')).n).toBe(1);
  });
});
