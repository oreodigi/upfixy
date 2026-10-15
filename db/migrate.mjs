// Applies db/migrations/*.sql in order as the schema owner.
// Usage: node migrate.mjs [--reset]   (--reset drops and recreates public; dev only)
// When the Laravel API lands, its migrations will execute these same files
// so there is one schema source of truth.
import { readdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import pg from 'pg';

const here = path.dirname(fileURLToPath(import.meta.url));
const dir = path.join(here, 'migrations');

export const ownerUrl =
  process.env.UPFIXY_OWNER_URL ?? 'postgres://upfixy_owner:devonly@127.0.0.1:5432/upfixy_dev';

export async function migrate({ reset = false, url = ownerUrl, log = console.log } = {}) {
  if (reset && !/127\.0\.0\.1|localhost/.test(url)) {
    throw new Error('Refusing --reset against a non-local database');
  }
  const client = new pg.Client({ connectionString: url });
  await client.connect();
  try {
    if (reset) {
      await client.query('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
      log('reset: public schema recreated');
    }
    await client.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
      name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())`);
    const done = new Set((await client.query('SELECT name FROM schema_migrations')).rows.map(r => r.name));
    const files = (await readdir(dir)).filter(f => f.endsWith('.sql')).sort();
    for (const f of files) {
      if (done.has(f)) continue;
      const sql = await readFile(path.join(dir, f), 'utf8');
      await client.query('BEGIN');
      try {
        await client.query(sql);
        await client.query('INSERT INTO schema_migrations(name) VALUES ($1)', [f]);
        await client.query('COMMIT');
        log(`applied ${f}`);
      } catch (e) {
        await client.query('ROLLBACK');
        throw new Error(`${f}: ${e.message}`);
      }
    }
  } finally {
    await client.end();
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  migrate({ reset: process.argv.includes('--reset') }).catch(e => {
    console.error(e.message);
    process.exit(1);
  });
}
