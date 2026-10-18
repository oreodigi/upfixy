// SCR-11 Diagnostic report (DX-002/003/005). Measured facts, possible causes,
// what we could not see, and the AI explanation are kept visibly separate.
// There is deliberately no "Fix now" here: a fix needs a quote and approval.
import { useEffect, useState } from 'react';
import { api, ApiError, type ApiDiagnosis, type Confidence } from '../api';
import { useWs } from '../app-context';
import { href, onLinkClick } from '../router';

const CONF: Record<Confidence, string> = { confirmed: 'Confirmed', likely: 'Likely', possible: 'Possible', unknown: 'Unknown' };
const CONF_HINT: Record<Confidence, string> = {
  confirmed: 'Proven by the measurements',
  likely: 'Strongly suggested by more than one measurement',
  possible: 'Fits the measurements, not proven',
  unknown: 'Cannot be judged without more access',
};

function ConfidenceTag({ c }: { c: Confidence }) {
  return <span className={`conf conf--${c}`} title={CONF_HINT[c]}>{CONF[c]}<span className="sr-only">: {CONF_HINT[c]}</span></span>;
}

function Ev({ ids, d }: { ids: string[]; d: ApiDiagnosis }) {
  return (
    <span className="ev">
      Evidence: {ids.map((id) => {
        const e = d.evidence.find((x) => x.evidence_id === id);
        return <code key={id} title={e ? `${e.kind} on ${e.host} at ${new Date(e.observed_at).toLocaleString('en-IN')}` : id}>{id.slice(0, 8)}</code>;
      })}
    </span>
  );
}

export default function Diagnosis({ id }: { id: string }) {
  const { setError } = useWs();
  const [d, setD] = useState<ApiDiagnosis | null>(null);
  const [missing, setMissing] = useState(false);
  const [copied, setCopied] = useState(false);
  const [tries, setTries] = useState(0);

  const load = () => api<ApiDiagnosis>('GET', `diagnostics/${id}`).then(setD)
    .catch((e) => (e instanceof ApiError && e.status === 404 ? setMissing(true) : setError(e.message)));
  useEffect(() => { setD(null); setTries(0); void load(); }, [id]); // eslint-disable-line react-hooks/exhaustive-deps
  // The AI explanation arrives a few seconds after the measured report.
  useEffect(() => {
    if (d?.ai.status !== 'pending' || tries >= 15) return;
    const t = window.setTimeout(() => { setTries(tries + 1); void load(); }, 2000);
    return () => window.clearTimeout(t);
  }, [d, tries]); // eslint-disable-line react-hooks/exhaustive-deps

  if (missing) return <div className="page"><h1>Report not found</h1><p>It may belong to a website you no longer have access to.</p></div>;
  if (!d) return <p className="hint" role="status">Loading the report…</p>;

  const steps = Array.from(new Set(d.observations.flatMap((o) => o.recommended_actions.map((a) => a.text))));
  const support = `Hi, Upfixy's diagnosis of ${d.website} (${new Date(d.created_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' })} IST) measured:\n`
    + d.observations.map((o) => `- ${o.observed_fact}`).join('\n')
    + `\nThe cause is not confirmed from public checks. Could you please look into this? Report ID: ${d.id}`;

  return (
    <div className="page report">
      <nav className="crumbs" aria-label="Breadcrumb">
        <a href={href({ name: 'website', id: d.website_id })} onClick={onLinkClick}>{d.website}</a><span aria-hidden="true"> / </span>
      </nav>
      <div className="headline">
        <h1>Diagnosis</h1>
        <p className="headline__sub">Based on readings available at {new Date(d.created_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}. Nothing has been changed on your website.</p>
      </div>

      <section className="panel-box" aria-labelledby="obs-h">
        <h2 id="obs-h">What we measured</h2>
        {d.observations.length === 0 ? <p className="empty-inline">No problems in the latest readings.</p> : (
          <ul className="rows">
            {d.observations.map((o) => (
              <li key={o.key} className="row row--critical"><div className="row__main"><span>{o.observed_fact}</span><Ev ids={o.evidence_ids} d={d} /></div>
                <ConfidenceTag c="confirmed" /></li>
            ))}
          </ul>
        )}
      </section>

      {d.hypotheses.length > 0 && (
        <section className="panel-box" aria-labelledby="hyp-h">
          <h2 id="hyp-h">Possible causes</h2>
          <p className="hint">Each cause has its own confidence. A measured problem is not proof of its cause.</p>
          <ul className="rows">
            {d.hypotheses.map((h) => (
              <li key={h.key} className="row"><div className="row__main"><span>{h.hypothesis}</span><Ev ids={h.supporting_evidence_ids} d={d} /></div>
                <ConfidenceTag c={h.confidence} /></li>
            ))}
          </ul>
        </section>
      )}

      {(d.missing.length > 0 || d.skipped.length > 0) && (
        <section className="panel-box" aria-labelledby="miss-h">
          <h2 id="miss-h">What we couldn't see</h2>
          <ul className="plain">
            {d.skipped.map((s) => <li key={s.check}>{s.label}: <em>{s.reason}</em></li>)}
            {d.missing.map((m, i) => <li key={`${m.key}-${i}`}>{m.detail} <strong>{m.next_step}</strong></li>)}
          </ul>
        </section>
      )}

      {steps.length > 0 && (
        <section className="panel-box" aria-labelledby="next-h">
          <h2 id="next-h">Suggested next steps</h2>
          <ul className="plain">{steps.map((s) => <li key={s}>{s}</li>)}</ul>
          <h3>Message for support</h3>
          <p className="help__msg" style={{ whiteSpace: 'pre-line' }}>{support}</p>
          <button type="button" className="btn btn--quiet" onClick={() => navigator.clipboard?.writeText(support).then(() => setCopied(true))}>{copied ? 'Copied' : 'Copy message'}</button>
        </section>
      )}

      <section className="panel-box ai" aria-labelledby="ai-h" aria-live="polite">
        <h2 id="ai-h">AI-assisted explanation</h2>
        {d.ai.status === 'succeeded' ? (
          <>
            <p className="hint">Written by an AI model from the measurements above. Every statement was checked against this report's evidence, but suggestions are not verified causes.</p>
            <p>{d.ai.summary}</p>
            {(d.ai.findings ?? []).map((f) => (
              <div key={f.finding_id} className="ai__finding">
                <p><strong>{f.observed_fact}</strong> <Ev ids={f.evidence_ids} d={d} /></p>
                <ul className="rows">
                  {f.hypotheses.map((h, i) => <li key={i} className="row"><div className="row__main"><span>{h.text}</span></div><ConfidenceTag c={h.confidence} /></li>)}
                </ul>
                {f.recommended_actions.length > 0 && <ul className="plain">{f.recommended_actions.map((a, i) => <li key={i}>{a.text}</li>)}</ul>}
                {f.unknowns.length > 0 && <p className="hint">Not known: {f.unknowns.join(' ')}</p>}
              </div>
            ))}
          </>
        ) : <p className={d.ai.status === 'pending' ? 'hint' : 'notice'}>{d.ai.status === 'pending' && tries >= 15 ? 'AI unavailable — measured findings only.' : d.ai.message}</p>}
        {d.ai.audit && (
          <details className="evidence">
            <summary>Audit details</summary>
            <dl className="facts">
              <div><dt>Model</dt><dd>{d.ai.audit.model ?? 'none'}</dd></div>
              <div><dt>Prompt</dt><dd>{d.ai.audit.prompt_version}</dd></div>
              <div><dt>Schema</dt><dd>{d.ai.audit.schema_version}</dd></div>
              <div><dt>Rules</dt><dd>{d.rule_version}</dd></div>
              {d.ai.audit.rejection_reasons.length > 0 && <div><dt>Not used because</dt><dd>{d.ai.audit.rejection_reasons.join('; ')}</dd></div>}
            </dl>
          </details>
        )}
      </section>
    </div>
  );
}
