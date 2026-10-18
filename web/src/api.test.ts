// Step-up MFA retry and error handling in the API client.
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, ApiError, session, setStepUpHandler } from './api';

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const memory = new Map<string, string>();

beforeEach(() => {
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => memory.get(k) ?? null,
    setItem: (k: string, v: string) => void memory.set(k, v),
    removeItem: (k: string) => void memory.delete(k),
  });
  session.setToken('1|abc');
  session.setWorkspace('ws-1');
});
afterEach(() => { vi.unstubAllGlobals(); setStepUpHandler(null); memory.clear(); });

it('asks for a code on mfa_step_up_required, then retries the same request once', async () => {
  const fetch = vi.fn()
    .mockResolvedValueOnce(json(403, { code: 'mfa_step_up_required', message: 'Enter your code' }))
    .mockResolvedValueOnce(json(201, { id: 'inv-1' }));
  vi.stubGlobal('fetch', fetch);
  const handler = vi.fn().mockResolvedValue(true);
  setStepUpHandler(handler);

  await expect(api('POST', 'invitations', { email: 'a@b.c' })).resolves.toEqual({ id: 'inv-1' });
  expect(handler).toHaveBeenCalledTimes(1);
  expect(fetch).toHaveBeenCalledTimes(2);
  expect(fetch.mock.calls[1][1].body).toBe(JSON.stringify({ email: 'a@b.c' }));
  expect(fetch.mock.calls[1][1].headers['X-Workspace-Id']).toBe('ws-1');
});

it('does not retry when the user cancels, and never loops', async () => {
  const fetch = vi.fn().mockImplementation(async () => json(403, { code: 'mfa_step_up_required', message: 'Enter your code' }));
  vi.stubGlobal('fetch', fetch);
  setStepUpHandler(vi.fn().mockResolvedValue(false));
  await expect(api('POST', 'invitations', {})).rejects.toMatchObject({ status: 403, code: 'mfa_step_up_required' });
  expect(fetch).toHaveBeenCalledTimes(1);

  setStepUpHandler(vi.fn().mockResolvedValue(true)); // code accepted but server still refuses: one retry only
  fetch.mockClear();
  await expect(api('POST', 'invitations', {})).rejects.toBeInstanceOf(ApiError);
  expect(fetch).toHaveBeenCalledTimes(2);
});

it('passes setup-required through with its code so the UI can point to Security', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json(403, { code: 'mfa_setup_required', message: 'Turn on two-step verification' })));
  const handler = vi.fn();
  setStepUpHandler(handler);
  await expect(api('PATCH', 'members/u', { active: false })).rejects.toMatchObject({ code: 'mfa_setup_required' });
  expect(handler).not.toHaveBeenCalled();
});

it('clears the session on 401 and surfaces the first validation error', async () => {
  vi.stubGlobal('fetch', vi.fn()
    .mockResolvedValueOnce(json(422, { message: 'Invalid', errors: { url: ['That address points to a private network'] } }))
    .mockResolvedValueOnce(json(401, { message: 'Unauthenticated.' })));
  await expect(api('POST', 'websites', { url: 'http://10.0.0.1' })).rejects.toThrow('That address points to a private network');
  expect(session.token()).toBe('1|abc');
  await expect(api('GET', 'workspaces')).rejects.toMatchObject({ status: 401 });
  expect(session.token()).toBeNull();
});
