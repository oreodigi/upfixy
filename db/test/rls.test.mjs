// Tenant-isolation and workflow-guard tests against real PostgreSQL 16.
// Every assertion runs as upfixy_app (not the table owner), so RLS applies.
// Test names carry the FRD acceptance-criterion IDs they evidence.
import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import pg from 'pg';
import { migrate } from '../migrate.mjs';

const OWNER = process.env.UPFIXY_TEST_OWNER_URL ?? 'postgres://upfixy_owner:devonly@127.0.0.1:5432/upfixy_test';
const APP = process.env.UPFIXY_TEST_APP_URL ?? 'postgres://upfixy_app:devonly@127.0.0.1:5432/upfixy_test';

let owner, app;
const id = {};

/** Run fn inside a transaction as upfixy_app with request context set. */
async function as(userId, wsId, fn) {
  await app.query('BEGIN');
  try {
    await app.query("SELECT set_config('app.user_id', $1, true), set_config('app.workspace_id', $2, true)",
      [userId ?? '', wsId ?? '']);
    const out = await fn(app);
    await app.query('COMMIT');
    return out;
  } catch (e) {
    await app.query('ROLLBACK');
    throw e;
  }
}
const rows = (r) => r.rows;

beforeAll(async () => {
  await migrate({ reset: true, url: OWNER, log: () => {} });
  owner = new pg.Client({ connectionString: OWNER });
  app = new pg.Client({ connectionString: APP });
  await owner.connect();
  await app.connect();

  // Seed as the owner role (bypasses RLS) — this is fixture setup, not app behaviour.
  const user = async (email) =>
    (await owner.query("INSERT INTO users(email, password_hash) VALUES ($1, 'x') RETURNING id", [email])).rows[0].id;
  for (const u of ['alice', 'bob', 'tina', 'carl', 'cara', 'bill', 'stan', 'sam']) id[u] = await user(`${u}@test.local`);

  const ws = async (name, kind) =>
    (await owner.query('INSERT INTO workspaces(name, kind) VALUES ($1, $2) RETURNING id', [name, kind])).rows[0].id;
  id.wsA = await ws('Agency A', 'agency');
  id.wsB = await ws('Agency B', 'agency');
  id.wsSolo = await ws('Sam solo', 'solo');

  const member = (w, u, role) => owner.query('INSERT INTO memberships VALUES ($1, $2, $3)', [w, u, role]);
  await member(id.wsA, id.alice, 'owner');
  await member(id.wsA, id.tina, 'technician');
  await member(id.wsA, id.carl, 'client_owner');
  await member(id.wsA, id.cara, 'client_owner');
  await member(id.wsA, id.bill, 'billing');
  await member(id.wsA, id.stan, 'client_staff');
  await member(id.wsB, id.bob, 'owner');
  await member(id.wsSolo, id.sam, 'owner');

  const client = async (w, name) =>
    (await owner.query('INSERT INTO clients(workspace_id, name) VALUES ($1, $2) RETURNING id', [w, name])).rows[0].id;
  id.clientX = await client(id.wsA, 'Mitra Traders');
  id.clientY = await client(id.wsA, 'Other Co');
  id.clientB = await client(id.wsB, 'B client');

  const assign = (w, c, u) => owner.query('INSERT INTO client_assignments(workspace_id, client_id, user_id) VALUES ($1,$2,$3)', [w, c, u]);
  await assign(id.wsA, id.clientX, id.tina);
  await assign(id.wsA, id.clientX, id.carl);
  await assign(id.wsA, id.clientX, id.stan);
  await assign(id.wsA, id.clientY, id.cara);

  const site = async (w, c, name) =>
    (await owner.query('INSERT INTO websites(workspace_id, client_id, display_name) VALUES ($1,$2,$3) RETURNING id', [w, c, name])).rows[0].id;
  id.siteX = await site(id.wsA, id.clientX, 'shop.example');
  id.siteY = await site(id.wsA, id.clientY, 'other.example');
  id.siteB = await site(id.wsB, id.clientB, 'b.example');
  id.siteSolo = await site(id.wsSolo, null, 'sam.example');

  const ticket = async (w, s) =>
    (await owner.query("INSERT INTO tickets(workspace_id, website_id, title) VALUES ($1,$2,'t') RETURNING id", [w, s])).rows[0].id;
  id.ticketX = await ticket(id.wsA, id.siteX);
  id.ticketY = await ticket(id.wsA, id.siteY);
  id.ticketSolo = await ticket(id.wsSolo, id.siteSolo);
});

afterAll(async () => {
  await owner?.end();
  await app?.end();
});

/** Move a ticket as the owner role through allowed states (fixture helper). */
async function setState(ticketId, ...states) {
  for (const s of states) {
    await owner.query('UPDATE tickets SET state = $2, version = version + 1 WHERE id = $1', [ticketId, s]);
  }
}

describe('tenant isolation', () => {
  it('returns nothing without request context', async () => {
    const r = await as(null, null, (c) => c.query('SELECT * FROM websites'));
    expect(rows(r)).toHaveLength(0);
  });

  it('AC-ACC-003.1: a user in workspace B cannot read a workspace A website by ID', async () => {
    const r = await as(id.bob, id.wsB, (c) => c.query('SELECT * FROM websites WHERE id = $1', [id.siteX]));
    expect(rows(r)).toHaveLength(0);
  });

  it('AC-ACC-003.1: claiming another workspace in the context header gives no access', async () => {
    const r = await as(id.bob, id.wsA, (c) => c.query('SELECT * FROM websites'));
    expect(rows(r)).toHaveLength(0);
  });

  it('owner sees every site in their own workspace only', async () => {
    const r = await as(id.alice, id.wsA, (c) => c.query('SELECT id FROM websites ORDER BY display_name'));
    expect(rows(r).map((x) => x.id).sort()).toEqual([id.siteX, id.siteY].sort());
  });

  it('AC-ACC-005.1: a client owner sees only their assigned client sites', async () => {
    const r = await as(id.carl, id.wsA, (c) => c.query('SELECT id FROM websites'));
    expect(rows(r).map((x) => x.id)).toEqual([id.siteX]);
  });

  it('AC-ACC-006.2: a technician assigned to client X cannot read client Y tickets by ID', async () => {
    const r = await as(id.tina, id.wsA, (c) => c.query('SELECT * FROM tickets WHERE id = $1', [id.ticketY]));
    expect(rows(r)).toHaveLength(0);
  });

  it('§6: billing role has no board/website visibility', async () => {
    const r = await as(id.bill, id.wsA, (c) => c.query('SELECT * FROM websites'));
    expect(rows(r)).toHaveLength(0);
  });

  it('a website cannot be linked to another workspace\'s client (composite FK)', async () => {
    await expect(owner.query(
      "INSERT INTO websites(workspace_id, client_id, display_name) VALUES ($1, $2, 'x')", [id.wsA, id.clientB],
    )).rejects.toThrow(/foreign key/);
  });

  it('create_workspace makes the caller owner; joining another workspace is refused', async () => {
    const ws = await as(id.bob, null, async (c) =>
      (await c.query("SELECT create_workspace('Bob two', 'agency') AS id")).rows[0].id);
    const role = await as(id.bob, ws, async (c) => (await c.query('SELECT app_role() AS r')).rows[0].r);
    expect(role).toBe('owner');
    await expect(as(id.bob, id.wsA, (c) =>
      c.query("INSERT INTO memberships VALUES ($1, $2, 'admin')", [id.wsA, id.bob]),
    )).rejects.toThrow(/row-level security/);
  });
});

describe('monitor targets', () => {
  it('AC-AST-001.3: apex and www are distinct targets; exact duplicates are rejected', async () => {
    await as(id.alice, id.wsA, async (c) => {
      const ins = (host) => c.query(
        "INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, path) VALUES ($1,$2,'https',$3,443,'/')",
        [id.wsA, id.siteX, host]);
      await ins('example.com');
      await ins('www.example.com');
      await expect(ins('example.com')).rejects.toThrow(/duplicate key/);
    }).catch(() => {}); // the duplicate aborts the txn; verify what committed below

    await as(id.alice, id.wsA, async (c) => {
      await c.query(
        "INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, path) VALUES ($1,$2,'https','example.com',443,'/'),($1,$2,'https','www.example.com',443,'/')",
        [id.wsA, id.siteY]);
    });
    const r = await as(id.alice, id.wsA, (c) =>
      c.query('SELECT host FROM monitor_targets WHERE website_id = $1 ORDER BY host', [id.siteY]));
    expect(rows(r).map((x) => x.host)).toEqual(['example.com', 'www.example.com']);
  });

  it('rejects uppercase hosts and non-allowlisted ports at the schema level', async () => {
    await expect(owner.query(
      "INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port) VALUES ($1,$2,'https','Example.com',443)",
      [id.wsA, id.siteX])).rejects.toThrow(/check constraint/);
    await expect(owner.query(
      "INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port) VALUES ($1,$2,'https','x.com',22)",
      [id.wsA, id.siteX])).rejects.toThrow(/check constraint/);
  });
});

describe('ticket workflow (§18.2)', () => {
  it('new tickets always start in New, version 1', async () => {
    const r = await as(id.carl, id.wsA, (c) => c.query(
      "INSERT INTO tickets(workspace_id, website_id, title, state, version) VALUES ($1,$2,'x','ready_for_work',9) RETURNING state, version",
      [id.wsA, id.siteX]));
    expect(rows(r)[0]).toEqual({ state: 'new', version: 1 });
  });

  it('AC-BRD-003.2: Investigating → Ready for work is rejected', async () => {
    await setState(id.ticketX, 'investigating');
    await expect(as(id.alice, id.wsA, (c) => c.query(
      "UPDATE tickets SET state = 'ready_for_work', version = version + 1 WHERE id = $1", [id.ticketX],
    ))).rejects.toThrow(/not allowed/);
  });

  it('AC-SVC-001.1: a stale version update is rejected', async () => {
    await expect(as(id.alice, id.wsA, (c) => c.query(
      "UPDATE tickets SET state = 'quote_pending', version = version WHERE id = $1", [id.ticketX],
    ))).rejects.toThrow(/stale ticket version/);
  });

  it('client staff cannot move workflow stages', async () => {
    const r = await as(id.stan, id.wsA, (c) => c.query(
      "UPDATE tickets SET state = 'quote_pending', version = version + 1 WHERE id = $1", [id.ticketX]));
    expect(r.rowCount).toBe(0);
  });

  it('AC-SVC-003.3: Quote pending → Ready for work needs a recorded approval, even at ₹0', async () => {
    await setState(id.ticketX, 'quote_pending');
    await expect(as(id.alice, id.wsA, (c) => c.query(
      "UPDATE tickets SET state = 'ready_for_work', version = version + 1 WHERE id = $1", [id.ticketX],
    ))).rejects.toThrow(/no valid approval/);

    await as(id.carl, id.wsA, (c) => c.query(
      "INSERT INTO approvals(workspace_id, ticket_id, amount_paise, action_fingerprint, approved_by, expires_at) VALUES ($1,$2,0,'dns:a-record:shop.example',$3, now() + interval '1 day')",
      [id.wsA, id.ticketX, id.carl]));
    const r = await as(id.alice, id.wsA, (c) => c.query(
      "UPDATE tickets SET state = 'ready_for_work', version = version + 1 WHERE id = $1 RETURNING state", [id.ticketX]));
    expect(rows(r)[0].state).toBe('ready_for_work');
  });

  it('paid work stays blocked until payment is verified (client cannot self-mark paid)', async () => {
    const t = (await owner.query("INSERT INTO tickets(workspace_id, website_id, title) VALUES ($1,$2,'paid') RETURNING id", [id.wsA, id.siteX])).rows[0].id;
    await setState(t, 'investigating', 'quote_pending');
    await as(id.carl, id.wsA, (c) => c.query(
      "INSERT INTO approvals(workspace_id, ticket_id, amount_paise, action_fingerprint, approved_by, expires_at, payment_verified) VALUES ($1,$2,10000,'dns:cname',$3, now() + interval '1 day', true)",
      [id.wsA, t, id.carl]));
    await expect(as(id.alice, id.wsA, (c) => c.query(
      "UPDATE tickets SET state = 'ready_for_work', version = version + 1 WHERE id = $1", [t],
    ))).rejects.toThrow(/no valid approval/);
  });

  it('AC-SVC-003.4: agency owner cannot approve client work unless the contract delegates payment', async () => {
    const approve = () => as(id.alice, id.wsA, (c) => c.query(
      "INSERT INTO approvals(workspace_id, ticket_id, amount_paise, action_fingerprint, approved_by, expires_at) VALUES ($1,$2,0,'x',$3, now() + interval '1 day')",
      [id.wsA, id.ticketY, id.alice]));
    await expect(approve()).rejects.toThrow(/authorised payer/);
    await owner.query('UPDATE clients SET agency_may_pay = true WHERE id = $1', [id.clientY]);
    await expect(approve()).resolves.toBeDefined();
  });

  it('a client owner cannot approve another client\'s work', async () => {
    await expect(as(id.cara, id.wsA, (c) => c.query(
      "INSERT INTO approvals(workspace_id, ticket_id, amount_paise, action_fingerprint, approved_by, expires_at) VALUES ($1,$2,0,'x',$3, now() + interval '1 day')",
      [id.wsA, id.ticketX, id.cara]))).rejects.toThrow();
  });

  it('solo owner can approve their own site work', async () => {
    await expect(as(id.sam, id.wsSolo, (c) => c.query(
      "INSERT INTO approvals(workspace_id, ticket_id, amount_paise, action_fingerprint, approved_by, expires_at) VALUES ($1,$2,0,'x',$3, now() + interval '1 day')",
      [id.wsSolo, id.ticketSolo, id.sam]))).resolves.toBeDefined();
  });
});

describe('UI and database agree on the workflow', () => {
  it('ticket_transition_allowed() matches @upfixy/core TRANSITIONS for all 81 pairs', async () => {
    const { TRANSITIONS, TICKET_STATES } = await import('../../packages/core/src/tickets.ts');
    const r = await owner.query(
      'SELECT f::text AS f, t::text AS t, ticket_transition_allowed(f, t) AS ok FROM unnest(enum_range(NULL::ticket_state)) f, unnest(enum_range(NULL::ticket_state)) t');
    expect(r.rows).toHaveLength(TICKET_STATES.length ** 2);
    for (const { f, t, ok } of r.rows) expect([f, t, ok]).toEqual([f, t, TRANSITIONS[f].includes(t)]);
  });
});

describe('audit log (ACC-007)', () => {
  it('AC-ACC-007.1: audit rows are append-only, even for the owner role', async () => {
    await as(id.alice, id.wsA, (c) => c.query(
      "INSERT INTO audit_events(workspace_id, actor_id, action, outcome) VALUES ($1,$2,'role.change','allowed')",
      [id.wsA, id.alice]));
    await expect(owner.query("UPDATE audit_events SET outcome = 'denied'")).rejects.toThrow(/append-only/);
    await expect(owner.query('DELETE FROM audit_events')).rejects.toThrow(/append-only/);
    await expect(as(id.alice, id.wsA, (c) => c.query('DELETE FROM audit_events'))).rejects.toThrow(/permission denied/);
  });

  it('a user cannot write audit rows attributed to someone else', async () => {
    await expect(as(id.tina, id.wsA, (c) => c.query(
      "INSERT INTO audit_events(workspace_id, actor_id, action, outcome) VALUES ($1,$2,'x','allowed')",
      [id.wsA, id.alice]))).rejects.toThrow(/row-level security/);
  });
});
