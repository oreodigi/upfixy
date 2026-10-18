// Upfixy UI kit v1 (docs/UI_REDESIGN_DIRECTION.md §Components).
// Every visual here is driven by data passed in. Nothing invents a reading:
// a missing value renders as a gap, "—" or an explicit "No readings" state.
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import {
  CircleAlert, CircleCheck, CircleDashed, CircleHelp, Info, Microscope, Plus, TriangleAlert, ChevronDown,
  type LucideIcon,
} from 'lucide-react';
import { api, type ApiMetrics } from '../api';
import { onLinkClick } from '../router';

// ------------------------------------------------------------------ status
export type UiStatus = 'healthy' | 'ok' | 'warning' | 'critical' | 'unknown' | 'stale' | 'needs_setup' | 'not_connected' | 'info' | 'investigating';

export const STATUS_ICON: Record<UiStatus, LucideIcon> = {
  healthy: CircleCheck, ok: CircleCheck, warning: TriangleAlert, critical: CircleAlert, unknown: CircleHelp, stale: CircleHelp,
  needs_setup: Plus, not_connected: CircleDashed, info: Info, investigating: Microscope,
};
export const STATUS_LABEL: Record<UiStatus, string> = {
  healthy: 'Healthy', ok: 'OK', warning: 'Warning', critical: 'Critical', unknown: 'Unknown', stale: 'Stale',
  needs_setup: 'Needs setup', not_connected: 'Not connected', info: 'Info', investigating: 'Investigating',
};

/** Status is always icon + words, never colour alone. */
export function StatusBadge({ status, label, className = '' }: { status: UiStatus; label?: string; className?: string }) {
  const Icon = STATUS_ICON[status];
  return (
    <span className={`ux-status ux-status--${status} ${className}`}>
      <Icon aria-hidden="true" />{label ?? STATUS_LABEL[status]}
    </span>
  );
}

// ------------------------------------------------------------------ metric card
export type Tone = 'blue' | 'aqua' | 'mint' | 'orange' | 'violet' | 'coral';

export function MetricCard({ tone, icon: Icon, label, value, sub, subIcon: SubIcon, href, spark, ariaLabel }: {
  tone: Tone; icon: LucideIcon; label: string; value: React.ReactNode; sub?: React.ReactNode; subIcon?: LucideIcon;
  href?: string; spark?: Array<number | null>; ariaLabel?: string;
}) {
  const body = (
    <>
      <span className="ux-kpi__icon" aria-hidden="true"><Icon /></span>
      <span className="ux-kpi__body">
        <span className="ux-kpi__label">{label}</span>
        <span className="ux-kpi__value">{value}</span>
        {sub && <span className="ux-kpi__sub">{SubIcon && <SubIcon aria-hidden="true" />}{sub}</span>}
      </span>
      {spark && spark.some((v) => v !== null) && <Sparkline values={spark} className="ux-kpi__spark" />}
    </>
  );
  return href
    ? <a className={`ux-kpi ux-kpi--${tone}`} href={href} onClick={onLinkClick} aria-label={ariaLabel}>{body}</a>
    : <div className={`ux-kpi ux-kpi--${tone}`} aria-label={ariaLabel} role={ariaLabel ? 'group' : undefined}>{body}</div>;
}

// ------------------------------------------------------------------ sparkline
/** Decorative trend of a REAL series; gaps (null) break the line. Hidden from assistive tech; the value is in text nearby. */
export function Sparkline({ values, className = 'ux-spark', color = 'currentColor' }: { values: Array<number | null>; className?: string; color?: string }) {
  const nums = values.filter((v): v is number => v !== null);
  if (nums.length < 2) return null;
  const W = 100, H = 30, min = Math.min(...nums), max = Math.max(...nums), span = max - min || 1;
  const x = (i: number) => (i / Math.max(values.length - 1, 1)) * W;
  const y = (v: number) => H - 3 - ((v - min) / span) * (H - 6);
  const segs: string[] = [];
  let cur = '';
  values.forEach((v, i) => {
    if (v === null) { if (cur) segs.push(cur); cur = ''; return; }
    cur += `${cur ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`;
  });
  if (cur) segs.push(cur);
  return (
    <svg className={className} viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" aria-hidden="true" focusable="false">
      {segs.map((d, i) => <path key={i} d={d} fill="none" stroke={color} strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinecap="round" strokeLinejoin="round" />)}
    </svg>
  );
}

// ------------------------------------------------------------------ time series
function useWidth<T extends HTMLElement>(): [React.RefObject<T | null>, number] {
  const ref = useRef<T>(null);
  const [w, setW] = useState(0);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    setW(el.clientWidth);
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(([e]) => setW(Math.round(e.contentRect.width)));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  return [ref, w];
}

const niceMax = (v: number) => {
  if (v <= 0) return 1;
  const p = 10 ** Math.floor(Math.log10(v));
  return [1, 2, 2.5, 5, 10].map((m) => m * p).find((m) => m >= v)!;
};

export interface SeriesPoint { t: string; v: number | null; n?: number }

/**
 * Area chart over real hourly buckets. Hours with no readings are gaps, never
 * zero. Units and interval are stated; a table alternative is always present.
 */
export function TimeSeriesChart({ points, unit, color, label, format, domain, height = 220 }: {
  points: SeriesPoint[]; unit: string; color: string; label: string;
  format: (v: number) => string; domain?: [number, number]; height?: number;
}) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);
  const gid = useId().replace(/:/g, '');
  const nums = points.map((p) => p.v).filter((v): v is number => v !== null);
  const pad = { l: 44, r: 10, t: 12, b: 26 };
  const W = Math.max(width, 260), H = height;
  const iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
  const [lo, hi] = domain ?? [0, niceMax(Math.max(...nums, 1) * 1.15)];
  const x = (i: number) => pad.l + (i / Math.max(points.length - 1, 1)) * iw;
  const y = (v: number) => pad.t + ih - ((Math.min(Math.max(v, lo), hi) - lo) / (hi - lo || 1)) * ih;
  const ticks = [0, 1, 2, 3, 4].map((k) => lo + ((hi - lo) * k) / 4);

  const runs: Array<Array<[number, number]>> = [];
  let run: Array<[number, number]> = [];
  points.forEach((p, i) => {
    if (p.v === null) { if (run.length) runs.push(run); run = []; return; }
    run.push([x(i), y(p.v)]);
  });
  if (run.length) runs.push(run);
  const line = (r: Array<[number, number]>) => r.map(([a, b], i) => `${i ? 'L' : 'M'}${a.toFixed(1)},${b.toFixed(1)}`).join('');
  const area = (r: Array<[number, number]>) => `${line(r)}L${r[r.length - 1][0].toFixed(1)},${pad.t + ih}L${r[0][0].toFixed(1)},${pad.t + ih}Z`;
  const hh = (t: string) => new Date(t).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Kolkata' });

  const onMove = useCallback((e: React.PointerEvent<SVGRectElement>) => {
    const box = e.currentTarget.getBoundingClientRect();
    const i = Math.round(((e.clientX - box.left) / box.width) * (points.length - 1));
    setHover(Math.min(Math.max(i, 0), points.length - 1));
  }, [points.length]);

  const hp = hover !== null ? points[hover] : null;
  return (
    <figure className="ux-chart" style={{ margin: 0 }}>
      <div ref={ref} style={{ position: 'relative' }}>
        {nums.length === 0 ? (
          <div className="ux-empty" style={{ minHeight: H }}><strong>No readings in this period</strong>Charts appear once checks have run.</div>
        ) : (
          <svg width={W} height={H} role="img" aria-label={`${label}. Table below.`}>
            <defs>
              <linearGradient id={`g${gid}`} x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stopColor={color} stopOpacity=".28" />
                <stop offset="100%" stopColor={color} stopOpacity="0" />
              </linearGradient>
            </defs>
            {ticks.map((t) => (
              <g key={t}>
                <line className="ux-chart__grid" x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} strokeDasharray={t === lo ? undefined : '3 4'} />
                <text className="ux-chart__axis" x={pad.l - 8} y={y(t) + 4} textAnchor="end">{format(t)}</text>
              </g>
            ))}
            {points.map((p, i) => (i % 6 === 0 || i === points.length - 1) && (
              <text key={p.t} className="ux-chart__axis" x={x(i)} y={H - 6} textAnchor={i === 0 ? 'start' : i === points.length - 1 ? 'end' : 'middle'}>{hh(p.t)}</text>
            ))}
            {runs.map((r, i) => r.length > 1 && <path key={`a${i}`} d={area(r)} fill={`url(#g${gid})`} />)}
            {runs.map((r, i) => r.length > 1
              ? <path key={`l${i}`} d={line(r)} fill="none" stroke={color} strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
              : <circle key={`l${i}`} cx={r[0][0]} cy={r[0][1]} r="3" fill={color} />)}
            {hp && (
              <g>
                <line x1={x(hover!)} x2={x(hover!)} y1={pad.t} y2={pad.t + ih} stroke={color} strokeOpacity=".35" />
                {hp.v !== null && <circle cx={x(hover!)} cy={y(hp.v)} r="5" fill="#fff" stroke={color} strokeWidth="2.5" />}
              </g>
            )}
            <rect x={pad.l} y={pad.t} width={iw} height={ih} fill="transparent" onPointerMove={onMove} onPointerLeave={() => setHover(null)} />
          </svg>
        )}
        {hp && (
          <div className="ux-chart__tip" style={{ left: x(hover!), top: hp.v !== null ? y(hp.v) : pad.t + ih / 2 }} aria-hidden="true">
            <strong>{hp.v === null ? 'No readings' : `${format(hp.v)}`}</strong>
            {hh(hp.t)}–{hh(new Date(new Date(hp.t).getTime() + 3600_000).toISOString())} IST{hp.n !== undefined ? ` · ${hp.n} checks` : ''}
          </div>
        )}
      </div>
      <details className="ux-details">
        <summary>Show as table<ChevronDown aria-hidden="true" /></summary>
        <div className="ux-table-wrap">
          <table className="ux-table">
            <caption className="sr-only">{label}</caption>
            <thead><tr><th scope="col">Hour (IST)</th><th scope="col" className="num">{unit}</th>{points[0]?.n !== undefined && <th scope="col" className="num">Checks</th>}</tr></thead>
            <tbody>
              {points.map((p) => (
                <tr key={p.t}><td>{hh(p.t)}</td><td className="num">{p.v === null ? 'No readings' : format(p.v)}</td>{p.n !== undefined && <td className="num">{p.n}</td>}</tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </figure>
  );
}

// ------------------------------------------------------------------ donut
export interface DonutSegment { key: string; label: string; value: number; color: string }
export function Donut({ segments, total, centerLabel, size = 150, legendAction }: {
  segments: DonutSegment[]; total?: number; centerLabel: string; size?: number;
  legendAction?: (s: DonutSegment) => (() => void) | undefined;
}) {
  const sum = total ?? segments.reduce((a, s) => a + s.value, 0);
  const R = 52, C = 2 * Math.PI * R;
  let acc = 0;
  return (
    <div className="ux-donut">
      <svg width={size} height={size} viewBox="0 0 140 140" role="img" aria-label={`${centerLabel}: ${sum}. ${segments.map((s) => `${s.label} ${s.value}`).join(', ')}`}>
        <circle cx="70" cy="70" r={R} fill="none" stroke="var(--unk-bg)" strokeWidth="18" />
        {sum > 0 && segments.filter((s) => s.value > 0).map((s) => {
          const len = (s.value / sum) * C;
          const el = <circle key={s.key} cx="70" cy="70" r={R} fill="none" stroke={s.color} strokeWidth="18"
            strokeDasharray={`${Math.max(len - 2, 0.5)} ${C}`} strokeDashoffset={-acc} transform="rotate(-90 70 70)" />;
          acc += len;
          return el;
        })}
        <text x="70" y="68" textAnchor="middle" style={{ font: '750 28px var(--font)', fill: 'var(--ink)' }}>{sum}</text>
        <text x="70" y="88" textAnchor="middle" style={{ font: '500 11px var(--font)', fill: 'var(--ink-3)' }}>{centerLabel}</text>
      </svg>
      <ul className="ux-donut__legend">
        {segments.map((s) => {
          const act = legendAction?.(s);
          return (
            <li key={s.key}>
              <i style={{ width: 10, height: 10, borderRadius: 3, background: s.color, flex: 'none' }} aria-hidden="true" />
              {act ? <span><button type="button" className="ux-link" style={{ color: 'var(--ink-2)', fontWeight: 500, fontSize: 'inherit' }} onClick={act}>{s.label}</button></span> : <span>{s.label}</span>}
              <b>{s.value}</b>
              <span style={{ flex: 'none', width: 44, textAlign: 'right', color: 'var(--ink-3)' }}>{sum ? `${Math.round((s.value / sum) * 100)}%` : '—'}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

// ------------------------------------------------------------------ layout bits
export function Panel({ title, sub, action, children, className = '', id }: {
  title: string; sub?: React.ReactNode; action?: React.ReactNode; children: React.ReactNode; className?: string; id?: string;
}) {
  const hid = useId();
  return (
    <section className={`ux-panel ${className}`} aria-labelledby={hid} id={id}>
      <div className="ux-panel__head">
        <div><h2 id={hid}>{title}</h2>{sub && <p className="ux-panel__sub">{sub}</p>}</div>
        {action}
      </div>
      {children}
    </section>
  );
}

export function EmptyState({ icon: Icon, title, children }: { icon: LucideIcon; title: string; children?: React.ReactNode }) {
  return <div className="ux-empty"><Icon aria-hidden="true" /><strong>{title}</strong>{children}</div>;
}

/** Collapsible section: open by default on desktop, closed on phones (dense pages). */
export function Accordion({ title, children, count, defaultOpenDesktop = true }: { title: string; children: React.ReactNode; count?: number; defaultOpenDesktop?: boolean }) {
  const [open, setOpen] = useState(() => defaultOpenDesktop && typeof window !== 'undefined' && window.matchMedia?.('(min-width: 1024px)').matches);
  return (
    <details className="ux-panel ux-acc" open={open} onToggle={(e) => setOpen((e.target as HTMLDetailsElement).open)}>
      <summary className="ux-acc__sum">
        <h2>{title}</h2>{count !== undefined && <span className="ux-count" style={{ background: 'var(--surface-2)' }}>{count}</span>}
        <ChevronDown aria-hidden="true" />
      </summary>
      <div className="ux-acc__body">{children}</div>
    </details>
  );
}

/** Pick one of a few views. On phones the tabs scroll horizontally. */
export function ResponsiveTabs<K extends string>({ tabs, value, onChange, label }: {
  tabs: Array<{ key: K; label: string; count?: number; tone?: string }>; value: K; onChange: (k: K) => void; label: string;
}) {
  return (
    <div className="ux-tabs ux-tabs--scroll" role="group" aria-label={label}>
      {tabs.map((t) => (
        <button key={t.key} type="button" className={`ux-tab ${t.tone ? `ux-tab--${t.tone}` : ''}`} aria-pressed={value === t.key} onClick={() => onChange(t.key)}>
          {t.label}{t.count !== undefined && <span className="ux-count">{t.count}</span>}
        </button>
      ))}
    </div>
  );
}

const MONO = ['#2563eb', '#7c3aed', '#0891b2', '#059669', '#ea580c', '#db2777', '#4f46e5', '#0d9488'];
export function Monogram({ name, size }: { name: string; size?: number }) {
  let h = 0;
  for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  const c = MONO[h % MONO.length];
  return (
    <span className="ux-mono" aria-hidden="true" style={{ background: `linear-gradient(135deg, ${c}, color-mix(in srgb, ${c} 60%, #000))`, ...(size ? { width: size, height: size } : {}) }}>
      {name.replace(/^www\./, '').trim().charAt(0).toUpperCase() || '?'}
    </span>
  );
}

// ------------------------------------------------------------------ data
/** GET boards/metrics (read-only, RLS-scoped). Refreshes each minute while mounted. */
export function useMetrics(workspaceId: string | undefined): ApiMetrics | null {
  const [m, setM] = useState<ApiMetrics | null>(null);
  useEffect(() => {
    if (!workspaceId) return;
    let live = true;
    const load = () => api<ApiMetrics>('GET', 'boards/metrics').then((r) => live && setM(r)).catch(() => undefined);
    void load();
    const t = window.setInterval(load, 60_000);
    return () => { live = false; window.clearInterval(t); };
  }, [workspaceId]);
  return m;
}

export const fmtPct = (v: number | null | undefined, digits = 2) => (v === null || v === undefined ? '—' : `${v.toFixed(v === 100 ? 0 : digits)}%`);
export const fmtMs = (v: number | null | undefined) => (v === null || v === undefined ? '—' : v >= 1000 ? `${(v / 1000).toFixed(2)} s` : `${Math.round(v)} ms`);
