#!/usr/bin/env python3
# Dev-only end-to-end check. Needs: npm run api, npm run dev, MAIL_MAILER=log, local Postgres.
# Usage: python3 -I scripts/e2e.py
"""End-to-end check of the M1 hardening through the Vite proxy (as the browser would).
Mail is read from Laravel's log mailer. Payment is out of scope (M6)."""
import base64, hashlib, hmac, json, re, struct, subprocess, sys, time, urllib.request, urllib.error

BASE = 'http://127.0.0.1:5173/api/v1/'
LOG = '/home/claude/upfixy/api/storage/logs/laravel.log'
RUN = str(int(time.time()))
ok_count = 0

def call(method, path, body=None, token=None, ws=None):
    req = urllib.request.Request(BASE + path, method=method, data=json.dumps(body).encode() if body is not None else None)
    req.add_header('Accept', 'application/json'); req.add_header('Content-Type', 'application/json')
    if token: req.add_header('Authorization', 'Bearer ' + token)
    if ws: req.add_header('X-Workspace-Id', ws)
    try:
        with urllib.request.urlopen(req) as r:
            txt = r.read().decode(); return r.status, (json.loads(txt) if txt else None)
    except urllib.error.HTTPError as e:
        txt = e.read().decode(); return e.code, (json.loads(txt) if txt else None)

def check(label, cond, detail=''):
    global ok_count
    if not cond:
        print(f'FAIL  {label}  {detail}'); sys.exit(1)
    ok_count += 1; print(f'ok    {label}')

def mail_token(kind, email):
    log = open(LOG).read()
    # Laravel's log mailer writes the full message; take the last link for this recipient.
    blocks = [b for b in log.split('local.DEBUG:') if f'To: {email}' in b]
    m = re.findall(rf'/{kind}\?token=([0-9a-f]{{64}})', blocks[-1]) if blocks else []
    return m[-1] if m else None

def totp(secret, step_offset=0):
    key = base64.b32decode(secret.upper() + '=' * (-len(secret) % 8))
    counter = int(time.time()) // 30 + step_offset
    h = hmac.new(key, struct.pack('>Q', counter), hashlib.sha1).digest()
    o = h[-1] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)

def psql_worker(sql):
    return subprocess.run(['psql', 'postgres://upfixy_worker:devonly@127.0.0.1/upfixy_dev', '-tAc', sql],
                          capture_output=True, text=True, check=True).stdout.strip()

# 1. Agency owner signs up and confirms email ------------------------------
olive = f'olive.{RUN}@e2e.test'
s, r = call('POST', 'auth/register', {'email': olive, 'password': 'a long enough password', 'account_type': 'agency', 'workspace_name': 'E2E Agency'})
check('agency owner registers', s == 201, r)
tok, ws = r['token'], r['workspace_id']
check('token has the scanner-friendly prefix', '|upfx_' in tok)
s, r = call('POST', 'tickets', {'website_id': '00000000-0000-4000-8000-000000000000', 'title': 'x'}, tok, ws)
check('unknown ids answer 404', s == 404)
vt = mail_token('verify-email', olive)
check('verification email sent with a link', vt is not None)
check('link confirms email', call('POST', 'auth/verify-email', {'token': vt})[0] == 200)
check('link is single-use', call('POST', 'auth/verify-email', {'token': vt})[0] == 422)

# 2. Inviting needs MFA; enrol ---------------------------------------------
s, r = call('POST', 'invitations', {'email': 'x@e2e.test', 'role': 'technician'}, tok, ws)
check('invite blocked until MFA is on', s == 403 and r['code'] == 'mfa_setup_required', r)
s, r = call('POST', 'mfa/setup', None, tok)
secret = r['secret']
check('MFA setup returns an otpauth URL', s == 200 and r['otpauth_url'].startswith('otpauth://totp/'))
s, r = call('POST', 'mfa/confirm', {'code': totp(secret)}, tok)
check('MFA confirmed, 8 recovery codes', s == 200 and len(r['recovery_codes']) == 8, r)
recovery = r['recovery_codes']

# 3. Sign in again: password then code -------------------------------------
s, r = call('POST', 'auth/login', {'email': olive, 'password': 'a long enough password'})
check('password step returns only a challenge', s == 200 and r['mfa_required'] and 'token' not in r, r)
challenge = r['challenge_token']
check('challenge token cannot read workspaces', call('GET', 'workspaces', None, challenge)[0] == 403)
check('replayed TOTP step is refused', call('POST', 'auth/mfa/login', {'code': totp(secret)}, challenge)[0] == 422)
s, r = call('POST', 'auth/mfa/login', {'code': recovery[0]}, challenge)
check('recovery code completes sign-in', s == 200, r)
tok = r['token']
check('challenge token is gone after use', call('POST', 'auth/mfa/login', {'code': recovery[1]}, challenge)[0] == 401)

# 4. Client, site, invitation ----------------------------------------------
s, r = call('POST', 'clients', {'name': 'Mitra Traders'}, tok, ws); client = r['id']
s, r = call('POST', 'websites', {'url': f'shop-{RUN}.example', 'client_id': client}, tok, ws)
check('site added without proof (public monitoring)', s == 201, r)
site, host = r['id'], r['target']['host']
s, r = call('POST', 'websites', {'url': 'http://169.254.169.254/latest/meta-data'}, tok, ws)
check('metadata address refused', s == 422)

carl = f'carl.{RUN}@e2e.test'
s, r = call('POST', 'invitations', {'email': carl, 'role': 'client_owner', 'client_id': client}, tok, ws)
check('invitation sent (session is MFA-fresh)', s == 201, r)
it = mail_token('accept-invite', carl)
check('invitation email has a link', it is not None)

s, r = call('POST', 'auth/register', {'email': carl, 'password': 'another long password', 'account_type': 'solo', 'workspace_name': "Carl's own"})
carl_tok, carl_own_ws = r['token'], r['workspace_id']
s, r = call('POST', 'invitations/accept', {'token': it}, carl_tok)
check('unverified email cannot accept', s == 403 and r.get('code') == 'email_unverified', r)
call('POST', 'auth/verify-email', {'token': mail_token('verify-email', carl)})
s, r = call('POST', 'invitations/accept', {'token': it}, carl_tok)
check('verified invitee joins the agency workspace', s == 200 and r['workspace_id'] == ws, r)
s, r = call('GET', 'websites', None, carl_tok, ws)
check('client owner sees their client site', s == 200 and len(r['data']) == 1)

# 5. Ownership verification -------------------------------------------------
s, r = call('POST', f'websites/{site}/claims', {'host': host, 'method': 'dns_txt'}, tok, ws)
check('DNS challenge issued with exact record', s == 201 and r['instructions']['name'] == f'_upfixy-challenge.{host}', r)
claim, value = r['id'], r['instructions']['value']
check('check queued', call('POST', f'claims/{claim}/check', None, tok, ws)[0] == 202)
s, r = call('GET', f'websites/{site}/claims', None, tok, ws)
check('real lookup ran in the worker and reported back', r['data'][0]['last_check_result'] is not None and r['data'][0]['status'] == 'challenge_issued', r['data'][0])
print('      worker said:', r['data'][0]['last_check_result'])
check('app role cannot mark it verified', subprocess.run(['psql', 'postgres://upfixy_app:devonly@127.0.0.1/upfixy_dev', '-tAc',
      f"UPDATE ownership_claims SET status='verified' WHERE id='{claim}'"], capture_output=True, text=True).returncode != 0)

# 6. Work request, quote, approval ------------------------------------------
s, r = call('POST', 'tickets', {'website_id': site, 'title': 'SSL expiring'}, carl_tok, ws); ticket = r['id']
check('client owner reports a problem', s == 201)
call('POST', f'tickets/{ticket}/transition', {'to': 'investigating', 'version': 1}, tok, ws)
s, r = call('GET', f'websites/{site}', None, tok, ws); target = r['targets'][0]['id']
s, q = call('POST', f'tickets/{ticket}/quotes', {'monitor_target_id': target, 'action_code': 'dns.record_fix', 'description': 'Fix the A record', 'amount_paise': 1}, tok, ws)
check('quote price comes from the catalogue (₹100), not the request', s == 201 and q['amount_paise'] == 10000, q)
s, q = call('POST', f'tickets/{ticket}/quotes', {'monitor_target_id': target, 'action_code': 'provider.covered_fix', 'description': 'Renew certificate under hosting contract'}, tok, ws)
check('replacement ₹0 quote issued', s == 201 and q['amount_paise'] == 0)
call('POST', f'tickets/{ticket}/transition', {'to': 'quote_pending', 'version': 2}, tok, ws)
s, r = call('POST', f'tickets/{ticket}/approvals', {'quote_id': q['id'], 'fingerprint': q['fingerprint']}, carl_tok, ws)
check('approval refused while ownership is unverified', s == 422 and 'Verify ownership' in r['message'], r)

# The worker's real job: report the token it observed. Simulated here because the
# test domain has no public DNS; it goes through the same worker-only function.
status = psql_worker(f"SELECT worker_record_claim_check('{claim}', '{value}', 'e2e: simulated TXT match')")
check('worker-only function verifies the exact token', status == 'verified', status)

s, r = call('POST', f'tickets/{ticket}/approvals', {'quote_id': q['id'], 'fingerprint': '0' * 64}, carl_tok, ws)
check('stale/forged fingerprint refused', s == 409, r)
s, r = call('POST', f'tickets/{ticket}/approvals', {'quote_id': q['id'], 'fingerprint': q['fingerprint']}, tok, ws)
check('agency owner cannot approve client work without contract', s == 403, r)
s, r = call('POST', f'tickets/{ticket}/approvals', {'quote_id': q['id'], 'fingerprint': q['fingerprint']}, carl_tok, ws)
check('client owner approves the exact quote', s == 201, r)
s, r = call('POST', f'tickets/{ticket}/transition', {'to': 'ready_for_work', 'version': 3}, tok, ws)
check('work can start', s == 200 and r['state'] == 'ready_for_work', r)

# 7. Isolation and revocation -----------------------------------------------
s, r = call('GET', f'tickets/{ticket}', None, carl_tok, carl_own_ws)
check("from Carl's own workspace the agency ticket is 404", s == 404)
s, r = call('PATCH', f"members/{call('GET', 'auth/me', None, carl_tok)[1]['id']}", {'active': False}, tok, ws)
check('owner removes Carl (session still MFA-fresh)', s == 204, r)
check('Carl loses access at once', call('GET', 'websites', None, carl_tok, ws)[0] == 404)
check('logout-all ends every session', call('POST', 'auth/logout-all', None, tok)[0] == 204 and call('GET', 'auth/me', None, tok)[0] == 401)

print(f'\nall {ok_count} end-to-end checks passed')
