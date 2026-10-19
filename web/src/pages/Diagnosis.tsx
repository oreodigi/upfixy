// UX-05 / SCR-11 Diagnosis (DX-002/003/005), organised around the customer's questions:
// what's wrong, how it affects me, what evidence, what might cause it, what can be done,
// can Upfixy fix it, what will it cost. Measured facts, rule-based causes and AI suggestions
// are visibly different. There is no repair button here: a change needs a request, a quote and
// an explicit approval (even at ₹0), and eligibility is decided by the server.
import { useEffect, useMemo, useState } from 'react';
import { TICKET_LABELS } from '@upfixy/core';
import {
  ArrowRight, BadgeCheck, Bot, CircleHelp, ClipboardCopy, Clock, FileSearch, Globe, IndianRupee, Lightbulb, ListChecks, Lock, Network,
  ShieldAlert, Sparkles, Stethoscope, UserCog, Wrench,
} from 'lucide-react';
import { api, ApiError, rupees, type ApiAction, type ApiDiagnosis, type ApiDnsConnection, type ApiRepairControls, type Confidence } from '../api';
import { useWs } from '../app-context';
import { since } from '../components';
import { HELP } from '../help';
import { href, navigate, onLinkClick } from '../router';
import { EmptyState, StatusBadge, type UiStatus } from '../ui/kit';

const CONF: Record<Confidence, string> = { confirmed: 'Confirmed', likely: 'Likely', possible: 'Possible', unknown: 'Unknown' };
const CONF_HINT: Record<Confidence, string> = {
  confirmed: 'Proven by the measurements',
  likely: 'Strongly suggested by more than one measurement',
  possible: 'Fits the measurements, not proven',
  unknown: 'Cannot be judged without more access',
};
const KIND_LABEL: Record<string, string> = { http: 'Website response', tls: 'SSL certificate', dns: 'Domain (DNS)' };
const KIND_ICON: Record<string, typeof Globe> = { http: Globe, tls: Lock, dns: Network };
const RESP: Record<string, string> = {
  provider_covered: 'Likely on the hosting provider’s side',
  customer_side: 'Likely on the website’s side (code, settings or content)',
  needs_quote: 'Needs a quoted piece of work',
  undetermined: 'Not determined from public checks',
};
const fmtWhen = (iso: string) => new Date(iso).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata' });

function Confidence({ c }: { c: Confidence }) {
  const st: UiStatus = c === 'confirmed' ? 'healthy' : c === 'unknown' ? 'unknown' : 'investigating';
  return <span title={CONF_HINT[c]}><StatusBadge status={st} label={CONF[c]} /><span className="sr-only">: {CONF_HINT[c]}</span></span>;
}

function Q({ n, icon: Icon, title, children, tone = 'blue', id }: { n: number; icon: typeof Globe; title: string; children: React.ReactNode; tone?: string; id?: string }) {
  return (
    <section className={`ux-panel ux-q ux-q--${tone}`} aria-labelledby={`q${n}`} id={id}>
      <header className="ux-q__head">
        <span className="ux-q__n" aria-hidden="true">{n}</span>
        <span className="ux-q__icon" aria-hidden="true"><Icon /></span>
        <h2 id={`q${n}`}>{title}</h2>
      </header>
      {children}
    </section>
  );
}

const JOURNEY = ['Problem detected', 'Understand it', 'Analyze the cause', 'See the solution', 'Approve the fix', 'Track the result'];

export default function Diagnosis({ id }: { id: string }) {
  const { setError, role, reload } = useWs();
  const [d, setD] = useState<ApiDiagnosis | null>(null);
  const [missing, setMissing] = useState(false);
  const [copied, setCopied] = useState(false);
  const [tries, setTries] = useState(0);
  const [catalog, setCatalog] = useState<ApiAction[] | null>(null);
  const [controls, setControls] = useState<ApiRepairControls | null>(null);
  const [dnsConn, setDnsConn] = useState<ApiDnsConnection[] | null>(null);
  const [busy, setBusy] = useState(false);

  const load = () => api<ApiDiagnosis>('GET', `diagnostics/${id}`).then(setD)
    .catch((e) => (e instanceof ApiError && e.status === 404 ? setMissing(true) : setError(e.message)));
  useEffect(() => { setD(null); setTries(0); void load(); }, [id]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    api<{ data: ApiAction[] }>('GET', 'actions').then((r) => setCatalog(r.data)).catch(() => setCatalog([]));
    api<ApiRepairControls>('GET', 'repair-controls').then(setControls).catch(() => setControls(null));
  }, []);
  useEffect(() => {
    if (!d) return;
    api<{ data: ApiDnsConnection[] }>('GET', `websites/${d.website_id}/dns-connections`).then((r) => setDnsConn(r.data.filter((c) => !c.revoked_at))).catch(() => setDnsConn([]));
  }, [d?.website_id]); // eslint-disable-line react-hooks/exhaustive-deps
  // The AI explanation arrives a few seconds after the measured report.
  useEffect(() => {
    if (d?.ai.status !== 'pending' || tries >= 15) return;
    const t = window.setTimeout(() => { setTries(tries + 1); void load(); }, 2000);
    return () => window.clearTimeout(t);
  }, [d, tries]); // eslint-disable-line react-hooks/exhaustive-deps

  const codes = useMemo(() => Array.from(new Set((d?.evidence ?? [])
    .filter((e) => e.severity === 'critical' || e.severity === 'warning').map((e) => e.result_code.toUpperCase()))), [d]);

  if (missing) return <EmptyState icon={FileSearch} title="Report not found">It may belong to a website you no longer have access to.</EmptyState>;
  if (!d) return <div className="ux-page" role="status" aria-label="Loading the report"><div className="ux-skel" style={{ height: 140, marginBottom: 16 }} /><div className="ux-skel" style={{ height: 300 }} /></div>;

  const helps = codes.map((c) => HELP[c]).filter(Boolean);
  const headline = helps[0]?.title ?? d.observations[0]?.observed_fact ?? 'No problems in the latest readings';
  const worst: UiStatus = d.evidence.some((e) => e.severity === 'critical') ? 'critical' : d.evidence.some((e) => e.severity === 'warning') ? 'warning' : 'healthy';
  const steps = Array.from(new Set(d.observations.flatMap((o) => o.recommended_actions.map((a) => a.text))));
  const checks = Array.from(new Set(helps.flatMap((h) => h.checks))).filter((c) => !steps.includes(c));
  const kinds = new Set(d.evidence.filter((e) => e.severity === 'critical' || e.severity === 'warning').map((e) => e.kind));
  const responsibility = Array.from(new Set(d.observations.concat(d.hypotheses).map((f) => f.responsibility)));
  const aiOk = d.ai.status === 'succeeded';
  const aiHyps = aiOk ? (d.ai.findings ?? []).flatMap((f) => f.hypotheses.map((h) => ({ ...h, fact: f.observed_fact }))) : [];
  const support = `Hi, Upfixy's diagnosis of ${d.website} (${fmtWhen(d.created_at)} IST) measured:\n`
    + d.observations.map((o) => `- ${o.observed_fact}`).join('\n')
    + `\nThe cause is not confirmed from public checks. Could you please look into this? Report ID: ${d.id}`;

  // Automated fix: only the one allowlisted operation (Cloudflare A/AAAA record), and only when the platform,
  // the workspace switch and a live DNS connection all allow it. The server re-checks every gate.
  const repairsOn = !!controls && controls.platform_enabled && !controls.global.paused && !controls.workspace.paused;
  const automatable = repairsOn && (dnsConn?.length ?? 0) > 0 && (kinds.has('dns') || kinds.has('http'));
  const tier = kinds.has('dns') ? 'domain_dns' : kinds.has('tls') ? 'cpanel' : kinds.has('http') ? 'cpanel' : null;
  const canRequest = role !== 'billing';
  const linked = d.tickets ?? [];
  const journeyAt = linked.length ? 4 : 3;

  async function requestFix() {
    setBusy(true);
    try {
      const r = await api<{ id: string }>('POST', 'tickets', { website_id: d!.website_id, title: headline.slice(0, 200), diagnostic_run_id: d!.id });
      await reload();
      navigate({ name: 'work', ticket: r.id });
    } catch (e) { setError(e instanceof Error ? e.message : String(e)); setBusy(false); }
  }

  return (
    <div className="ux-page ux-diag">
      <nav className="ux-crumbs" aria-label="Breadcrumb">
        <a href="/websites" onClick={onLinkClick}>Websites</a><span aria-hidden="true">/</span>
        <a href={href({ name: 'website', id: d.website_id })} onClick={onLinkClick}>{d.website}</a><span aria-hidden="true">/</span>
        <span aria-current="page">Diagnosis</span>
      </nav>

      <header className={`ux-diaghead ux-diaghead--${worst}`}>
        <div className="ux-diaghead__main">
          <span className="ux-diaghead__icon" aria-hidden="true"><Stethoscope /></span>
          <div style={{ minWidth: 0 }}>
            <p className="ux-eyebrow">Diagnosis · {d.website}</p>
            <h1>{headline}</h1>
            <div className="ux-sitehead__meta">
              <StatusBadge status={worst} label={worst === 'healthy' ? 'Nothing failing' : worst === 'critical' ? 'Critical' : 'Warning'} />
              <span><Clock aria-hidden="true" width={14} style={{ verticalAlign: '-2px' }} /> Readings as of {fmtWhen(d.created_at)}</span>
              <span>Nothing has been changed on your website.</span>
            </div>
          </div>
        </div>
        <div className="ux-sitehead__actions">
          <button type="button" className="ux-btn ux-btn--ghost" onClick={() => navigator.clipboard?.writeText(support).then(() => setCopied(true))}>
            <ClipboardCopy aria-hidden="true" />{copied ? 'Copied' : 'Copy message for support'}
          </button>
          {canRequest && d.observations.length > 0 && (linked.length
            ? <a className="ux-btn ux-btn--primary" href={href({ name: 'work', ticket: linked[0].id })} onClick={onLinkClick}><Wrench aria-hidden="true" />Open the fix request</a>
            : <button type="button" className="ux-btn ux-btn--primary" disabled={busy} onClick={() => void requestFix()}><Wrench aria-hidden="true" />Request a fix</button>)}
        </div>
      </header>

      <ol className="ux-journey" aria-label="From problem to fix" tabIndex={0}>
        {JOURNEY.map((s, i) => (
          <li key={s} className={i < journeyAt ? 'done' : i === journeyAt ? 'now' : ''} aria-current={i === journeyAt ? 'step' : undefined}>
            <span className="ux-journey__dot" aria-hidden="true">{i < journeyAt ? <BadgeCheck /> : i + 1}</span><span>{s}</span>
          </li>
        ))}
      </ol>

      <div className="ux-grid ux-grid--dash1">
        <div className="ux-qs">
          <Q n={1} icon={ShieldAlert} title="What is wrong?" tone="coral">
            {d.observations.length === 0 ? <EmptyState icon={BadgeCheck} title="No problems in the latest readings" /> : (
              <ul className="ux-list ux-list--compact">
                {d.observations.map((o) => (
                  <li key={o.key}>
                    <span className="ux-list__icon ux-tint--critical" aria-hidden="true"><ShieldAlert /></span>
                    <div className="ux-list__main"><span className="ux-list__title">{o.observed_fact}</span>
                      <span className="ux-list__meta">Measured by Upfixy · {o.evidence_ids.length} {o.evidence_ids.length === 1 ? 'reading' : 'readings'}</span></div>
                    <span className="ux-list__side"><StatusBadge status="info" label="Measured" /></span>
                  </li>
                ))}
              </ul>
            )}
          </Q>

          <Q n={2} icon={Globe} title="How does it affect my website?" tone="orange">
            {helps.length === 0 ? <p className="ux-q__p">Upfixy has no plain-language guide for these results yet. The evidence below gives support the details.</p>
              : helps.map((h) => <p key={h.title} className="ux-q__p"><strong>{h.title}.</strong> {h.meaning}</p>)}
          </Q>

          <Q n={3} icon={FileSearch} title="What evidence did Upfixy find?" tone="blue">
            <div className="ux-table-wrap" tabIndex={0} role="region" aria-label="Evidence">
              <table className="ux-table ux-table--cards">
                <thead><tr><th scope="col">Check</th><th scope="col">Address</th><th scope="col">Result</th><th scope="col">When</th><th scope="col">Source</th></tr></thead>
                <tbody>
                  {d.evidence.map((e) => {
                    const Icon = KIND_ICON[e.kind] ?? Globe;
                    const st: UiStatus = e.freshness !== 'fresh' ? 'stale' : e.severity === 'ok' ? 'healthy' : e.severity === 'critical' ? 'critical' : e.severity === 'warning' ? 'warning' : 'unknown';
                    return (
                      <tr key={e.evidence_id}>
                        <td><span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}><Icon aria-hidden="true" width={16} />{KIND_LABEL[e.kind] ?? e.kind}</span></td>
                        <td data-label="Address" style={{ overflowWrap: 'anywhere' }}>{e.host}</td>
                        <td><StatusBadge status={st} label={e.freshness !== 'fresh' ? 'Old reading' : `${e.severity === 'ok' ? 'OK' : e.result_code}`} /></td>
                        <td data-label="When" style={{ whiteSpace: 'nowrap' }}>{since(new Date(e.observed_at))}</td>
                        <td data-label="Source">{e.source === 'connector' ? 'Provider connection' : 'Public check'}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Q>

          <Q n={4} icon={Lightbulb} title="What might have caused it?" tone="violet">
            <h3 className="ux-q__h">From Upfixy’s rules</h3>
            {d.hypotheses.length === 0 ? <p className="ux-q__p">The measurements don’t point to a cause on their own.</p> : (
              <ul className="ux-list ux-list--compact">
                {d.hypotheses.map((h) => (
                  <li key={h.key}>
                    <span className="ux-list__icon ux-tint--investigating" aria-hidden="true"><CircleHelp /></span>
                    <div className="ux-list__main"><span className="ux-list__title">{h.hypothesis}</span>
                      <span className="ux-list__meta">Based on {h.supporting_evidence_ids.length} {h.supporting_evidence_ids.length === 1 ? 'reading' : 'readings'}{h.contradicting_evidence_ids.length ? ` · ${h.contradicting_evidence_ids.length} against` : ''}</span></div>
                    <span className="ux-list__side"><Confidence c={h.confidence} /></span>
                  </li>
                ))}
              </ul>
            )}
            <div className="ux-ai" aria-live="polite">
              <h3 className="ux-q__h"><Sparkles aria-hidden="true" />AI suggestions <span className="ux-ai__tag">Not verified</span></h3>
              {aiOk ? (
                <>
                  <p className="ux-q__p">{d.ai.summary}</p>
                  {aiHyps.length > 0 && (
                    <ul className="ux-list ux-list--compact">
                      {aiHyps.map((h, i) => (
                        <li key={i}>
                          <span className="ux-list__icon ux-tint--investigating" aria-hidden="true"><Bot /></span>
                          <div className="ux-list__main"><span className="ux-list__title">{h.text}</span><span className="ux-list__meta">AI suggestion about: {h.fact}</span></div>
                          <span className="ux-list__side"><Confidence c={h.confidence} /></span>
                        </li>
                      ))}
                    </ul>
                  )}
                  <p className="ux-note">Written by an AI model from the measurements above. Each statement was checked against this report’s evidence, but suggestions are not confirmed causes.</p>
                </>
              ) : <p className="ux-q__p ux-muted">{d.ai.status === 'pending' && tries >= 15 ? 'AI unavailable — measured findings only.' : d.ai.message}</p>}
            </div>
            {(d.missing.length > 0 || d.skipped.length > 0) && (
              <details className="ux-details ux-q__more" open>
                <summary>What Upfixy couldn’t see ({d.missing.length + d.skipped.length})</summary>
                <ul className="ux-bullets">
                  {d.skipped.map((s) => <li key={s.check}>{s.label}: <em>{s.reason}</em></li>)}
                  {d.missing.map((m, i) => <li key={`${m.key}-${i}`}>{m.detail} <strong>{m.next_step}</strong></li>)}
                </ul>
              </details>
            )}
          </Q>

          <Q n={5} icon={ListChecks} title="What can be done?" tone="mint">
            {steps.length === 0 && checks.length === 0 ? <p className="ux-q__p">No action needed right now.</p> : (
              <>
                {steps.length > 0 && <><h3 className="ux-q__h">Recommended next steps</h3><ol className="ux-steps">{steps.map((s) => <li key={s}>{s}</li>)}</ol></>}
                {checks.length > 0 && <><h3 className="ux-q__h">Things you can check yourself</h3><ul className="ux-bullets">{checks.map((c) => <li key={c}>{c}</li>)}</ul></>}
              </>
            )}
            {aiOk && (d.ai.findings ?? []).some((f) => f.recommended_actions.length) && (
              <>
                <h3 className="ux-q__h"><Sparkles aria-hidden="true" />AI-suggested actions <span className="ux-ai__tag">Not verified</span></h3>
                <ul className="ux-bullets">{(d.ai.findings ?? []).flatMap((f) => f.recommended_actions).map((a, i) => <li key={i}>{a.text}</li>)}</ul>
              </>
            )}
          </Q>
        </div>

        <aside className="ux-rail" aria-label="Fix and cost">
          <Q n={6} icon={UserCog} title="Can Upfixy fix it?" tone="aqua" id="can-fix">
            {d.observations.length === 0 ? <p className="ux-q__p">There is nothing to fix in these readings.</p> : (
              <>
                <div className={`ux-verdict ux-verdict--${automatable ? 'auto' : 'tech'}`}>
                  {automatable ? <Wrench aria-hidden="true" /> : <UserCog aria-hidden="true" />}
                  <div>
                    <strong>{automatable ? 'Possibly, with an automated DNS change' : 'A technician needs to do this'}</strong>
                    <p>{automatable
                      ? 'This website has a DNS connection, so a technician can preview one exact, reversible record change. It runs only after you approve the quote and the checks pass beforehand.'
                      : d.human_review_required || !repairsOn
                        ? 'This needs a person to investigate. Raise a request: a technician will reply with the exact work and a quote.'
                        : 'No automated fix applies here. Raise a request and a technician will quote for the exact work.'}</p>
                  </div>
                </div>
                <ul className="ux-arealist">
                  {responsibility.map((r) => <li key={r}><UserCog aria-hidden="true" /><span>Who is likely responsible</span><StatusBadge status="info" label={RESP[r] ?? r} /></li>)}
                  <li><BadgeCheck aria-hidden="true" /><span>Your approval</span><StatusBadge status="info" label="Always required" /></li>
                </ul>
                {linked.length > 0 && (
                  <ul className="ux-list ux-list--compact" style={{ marginTop: 10 }}>
                    {linked.map((t) => (
                      <li key={t.id}>
                        <span className="ux-list__icon ux-tint--investigating" aria-hidden="true"><Wrench /></span>
                        <div className="ux-list__main"><a className="ux-list__title" href={href({ name: 'work', ticket: t.id })} onClick={onLinkClick}>{t.title}</a>
                          <span className="ux-list__meta">Request · {TICKET_LABELS[t.state]}</span></div>
                      </li>
                    ))}
                  </ul>
                )}
                {canRequest && !linked.length && (
                  <button type="button" className="ux-btn ux-btn--primary" style={{ width: '100%', marginTop: 12 }} disabled={busy} onClick={() => void requestFix()}>
                    <Wrench aria-hidden="true" />Request a fix<ArrowRight aria-hidden="true" />
                  </button>
                )}
              </>
            )}
          </Q>

          <Q n={7} icon={IndianRupee} title="How much will it cost?" tone="orange">
            {!catalog ? <div className="ux-skel" style={{ height: 120 }} aria-hidden="true" /> : (
              <>
                <ul className="ux-prices">
                  {catalog.map((a) => (
                    <li key={a.code} className={a.tier === tier ? 'is-likely' : ''}>
                      <span>{a.label}{a.tier === tier && <span className="ux-likely">Likely for this issue</span>}</span>
                      <b>{a.price_paise === null ? 'Quoted' : a.price_paise === 0 ? '₹0*' : rupees(a.price_paise)}</b>
                    </li>
                  ))}
                </ul>
                <p className="ux-note">Indicative prices. The final price and exact work come in a quote that you approve before anything changes. *₹0 only when an active hosting contract covers the website — it still needs your approval.</p>
              </>
            )}
          </Q>

          {d.ai.audit && (
            <details className="ux-panel ux-details" style={{ padding: '6px 18px' }}>
              <summary>Audit details (staff)</summary>
              <dl className="kv">
                <dt>Model</dt><dd>{d.ai.audit.model ?? 'none'}</dd>
                <dt>Prompt</dt><dd>{d.ai.audit.prompt_version}</dd>
                <dt>Schema</dt><dd>{d.ai.audit.schema_version}</dd>
                <dt>Rules</dt><dd>{d.rule_version}</dd>
                {d.ai.audit.rejection_reasons.length > 0 && <><dt>Not used because</dt><dd>{d.ai.audit.rejection_reasons.join('; ')}</dd></>}
              </dl>
            </details>
          )}
        </aside>
      </div>
    </div>
  );
}
