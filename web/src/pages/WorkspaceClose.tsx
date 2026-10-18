// NFR-14 soft close (owner) and the notice members see in a closed workspace.
import { useState } from 'react';
import { api, type ApiWorkspace } from '../api';
import { useWs } from '../app-context';

const errText = (e: unknown) => (e instanceof Error ? e.message : String(e));

export function ClosedWorkspace({ ws }: { ws: ApiWorkspace }) {
  const [msg, setMsg] = useState<string | null>(null);
  const until = new Date(new Date(ws.closed_at!).getTime() + 30 * 864e5);
  const canReopen = ws.role === 'owner' && until.getTime() > Date.now();
  return (
    <div className="page">
      <h1>{ws.name} is closed</h1>
      <p>It was closed on {new Date(ws.closed_at!).toLocaleDateString('en-IN')}. Monitoring has stopped and provider connections were removed. Quotes, payments, receipts and the audit trail are kept.</p>
      {canReopen ? (
        <>
          <p className="hint">You can reopen it until {until.toLocaleDateString('en-IN')}. Afterwards you'll need to reconnect providers and verify ownership again.</p>
          <button type="button" className="btn" onClick={() => void api('POST', `workspaces/${ws.id}/reopen`, undefined, { workspace: false })
            .then(() => window.location.reload()).catch((e) => setMsg(errText(e)))}>Reopen workspace</button>
        </>
      ) : <p className="hint">Choose another workspace from the menu above.</p>}
      {msg && <p className="error" role="alert">{msg}</p>}
    </div>
  );
}

export function CloseWorkspace() {
  const { role, workspace } = useWs();
  const [open, setOpen] = useState(false);
  const [f, setF] = useState({ confirm_name: '', reason: '' });
  const [msg, setMsg] = useState<string | null>(null);
  if (role !== 'owner' || !workspace) return null;
  return (
    <section className="panel-box" aria-labelledby="close-h">
      <h2 id="close-h">Close this workspace</h2>
      <p className="hint">Stops all monitoring and alerts, removes provider connections, pauses automated fixes and cancels open quotes. Financial records and the audit trail are kept. You can reopen within 30 days.</p>
      {!open ? <button type="button" className="btn btn--quiet" onClick={() => setOpen(true)}>Close workspace…</button> : (
        <form className="stack" onSubmit={(e) => { e.preventDefault(); void api('POST', 'workspace/close', f).then(() => window.location.reload()).catch((err) => setMsg(errText(err))); }}>
          <label className="field">Type <strong>{workspace.name}</strong> to confirm<input required value={f.confirm_name} onChange={(e) => setF({ ...f, confirm_name: e.target.value })} /></label>
          <label className="field">Reason<input required minLength={3} maxLength={300} value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></label>
          <div className="actions">
            <button className="btn btn--danger" disabled={f.confirm_name !== workspace.name}>Close workspace</button>
            <button type="button" className="btn btn--quiet" onClick={() => setOpen(false)}>Cancel</button>
          </div>
          {msg && <p className="error" role="alert">{msg}</p>}
        </form>
      )}
    </section>
  );
}
