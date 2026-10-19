// Upfixy UI kit v1 (docs/UI_REDESIGN_DIRECTION.md §Components).
// Every visual here is driven by data passed in. Nothing invents a reading:
// a missing value renders as a gap, "—" or an explicit "No readings" state.
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import {
  CircleAlert, CircleCheck, CircleDashed, CircleHelp, Info, Microscope, Plus, TriangleAlert, ChevronDown, EllipsisVertical,
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

/**
 * KPI card (reference composition: icon disc, label, big value, tiny trend). The trend
 * is drawn only from a REAL series (`spark` line or `bars`); `trendLabel` says what it is.
 */
export function MetricCard({ tone, icon: Icon, label, value, sub, subIcon: SubIcon, href, spark, bars, trendLabel, sparkDomain, ariaLabel }: {
  tone: Tone; icon: LucideIcon; label: string; value: React.ReactNode; sub?: React.ReactNode; subIcon?: LucideIcon;
  href?: string; spark?: Array<number | null>; bars?: Array<number | null>; trendLabel?: string; sparkDomain?: [number, number]; ariaLabel?: string;
}) {
  const hasSpark = !!spark && spark.filter((v) => v !== null).length > 1;
  const hasBars = !!bars && bars.some((v) => v !== null && v > 0);
  const body = (
    <>
      <span className="ux-kpi__icon" aria-hidden="true"><Icon /></span>
      <span className="ux-kpi__body">
        <span className="ux-kpi__label">{label}</span>
        <span className="ux-kpi__row">
          <span className={`ux-kpi__value${typeof value === 'string' && /[a-z]{4,}/i.test(value) && !/^\d/.test(value) ? ' ux-kpi__value--text' : ''}`}>{value}</span>
          {(hasSpark || hasBars) && (
            <span className="ux-kpi__trend" title={trendLabel}>
              {hasSpark ? <Sparkline values={spark!} className="ux-kpi__spark" domain={sparkDomain} area /> : <MiniBars values={bars!} className="ux-kpi__spark" />}
              {trendLabel && <span className="sr-only">{trendLabel}</span>}
            </span>
          )}
        </span>
        {sub && <span className="ux-kpi__sub">{SubIcon && <SubIcon aria-hidden="true" />}<span>{sub}</span></span>}
      </span>
    </>
  );
  return href
    ? <a className={`ux-kpi ux-kpi--${tone}`} href={href} onClick={onLinkClick} aria-label={ariaLabel}>{body}</a>
    : <div className={`ux-kpi ux-kpi--${tone}`} aria-label={ariaLabel} role={ariaLabel ? 'group' : undefined}>{body}</div>;
}

// ------------------------------------------------------------------ curves
/** Monotone cubic path through points (no overshoot, so 100% never draws above 100%). */
export function smoothPath(pts: Array<[number, number]>): string {
  const n = pts.length;
  if (n === 0) return '';
  if (n < 3) return pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join('');
  const dx: number[] = [], m: number[] = [];
  for (let i = 0; i < n - 1; i++) { dx.push(pts[i + 1][0] - pts[i][0]); m.push((pts[i + 1][1] - pts[i][1]) / (dx[i] || 1)); }
  const t: number[] = [m[0]];
  for (let i = 1; i < n - 1; i++) t.push(m[i - 1] * m[i] <= 0 ? 0 : (3 * (dx[i - 1] + dx[i])) / ((2 * dx[i] + dx[i - 1]) / m[i - 1] + (dx[i] + 2 * dx[i - 1]) / m[i]));
  t.push(m[n - 2]);
  let d = `M${pts[0][0].toFixed(1)},${pts[0][1].toFixed(1)}`;
  for (let i = 0; i < n - 1; i++) {
    const [x0, y0] = pts[i], [x1, y1] = pts[i + 1], h = dx[i] / 3;
    d += `C${(x0 + h).toFixed(1)},${(y0 + t[i] * h).toFixed(1)} ${(x1 - h).toFixed(1)},${(y1 - t[i + 1] * h).toFixed(1)} ${x1.toFixed(1)},${y1.toFixed(1)}`;
  }
  return d;
}

// ------------------------------------------------------------------ sparkline
/** Decorative trend of a REAL series; gaps (null) break the line. Hidden from assistive tech; the value is in text nearby. */
export function Sparkline({ values, className = 'ux-spark', color = 'currentColor', domain, area = false }: {
  values: Array<number | null>; className?: string; color?: string; domain?: [number, number]; area?: boolean;
}) {
  const gid = useId().replace(/:/g, '');
  const nums = values.filter((v): v is number => v !== null);
  if (nums.length < 2) return null;
  const W = 100, H = 32;
  const min = domain ? domain[0] : Math.min(...nums), max = domain ? domain[1] : Math.max(...nums), span = max - min || 1;
  const x = (i: number) => (i / Math.max(values.length - 1, 1)) * W;
  const y = (v: number) => H - 3 - ((Math.min(Math.max(v, min), max) - min) / span) * (H - 6);
  const runs: Array<Array<[number, number]>> = [];
  let cur: Array<[number, number]> = [];
  values.forEach((v, i) => { if (v === null) { if (cur.length) runs.push(cur); cur = []; } else cur.push([x(i), y(v)]); });
  if (cur.length) runs.push(cur);
  return (
    <svg className={className} viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" aria-hidden="true" focusable="false">
      {area && <defs><linearGradient id={`s${gid}`} x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor={color} stopOpacity=".28" /><stop offset="1" stopColor={color} stopOpacity="0" /></linearGradient></defs>}
      {area && runs.filter((r) => r.length > 1).map((r, i) => (
        <path key={`a${i}`} d={`${smoothPath(r)}L${r[r.length - 1][0]},${H}L${r[0][0]},${H}Z`} fill={`url(#s${gid})`} stroke="none" />
      ))}
      {runs.map((r, i) => r.length > 1 && <path key={i} d={smoothPath(r)} fill="none" stroke={color} strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinecap="round" strokeLinejoin="round" />)}
    </svg>
  );
}

/** Tiny bar trend of a REAL count series (e.g. failed checks per hour). */
export function MiniBars({ values, className = 'ux-spark', color = 'currentColor' }: { values: Array<number | null>; className?: string; color?: string }) {
  const max = Math.max(1, ...values.map((v) => v ?? 0));
  const W = 100, H = 32, bw = W / Math.max(values.length, 1);
  return (
    <svg className={className} viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" aria-hidden="true" focusable="false">
      {values.map((v, i) => {
        const h = v ? Math.max(3, (v / max) * (H - 2)) : 1.5;
        return <rect key={i} x={i * bw + bw * 0.18} y={H - h} width={bw * 0.64} height={h} rx={Math.min(1.5, bw * 0.3)} fill={color} opacity={v ? 0.9 : 0.22} />;
      })}
    </svg>
  );
}

// ------------------------------------------------------------------ menu
/** Accessible "⋮" menu for secondary card actions (keyboard: Enter/Space opens, Esc closes, arrows move). */
export function KebabMenu({ label, items }: { label: string; items: Array<{ label: string; onSelect: () => void; icon?: LucideIcon } | false | null | undefined> }) {
  const list = items.filter(Boolean) as Array<{ label: string; onSelect: () => void; icon?: LucideIcon }>;
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => { if (!root.current?.contains(e.target as Node)) setOpen(false); };
    document.addEventListener('mousedown', close);
    root.current?.querySelector<HTMLButtonElement>('[role=menuitem]')?.focus();
    return () => document.removeEventListener('mousedown', close);
  }, [open]);
  if (!list.length) return null;
  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') { setOpen(false); root.current?.querySelector<HTMLButtonElement>('.ux-kebab__btn')?.focus(); }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const els = [...(root.current?.querySelectorAll<HTMLButtonElement>('[role=menuitem]') ?? [])];
      const i = els.indexOf(document.activeElement as HTMLButtonElement);
      els[(i + (e.key === 'ArrowDown' ? 1 : -1) + els.length) % els.length]?.focus();
    }
  };
  return (
    <div className="ux-kebab" ref={root} onKeyDown={onKey}>
      <button type="button" className="ux-kebab__btn" aria-haspopup="menu" aria-expanded={open} aria-label={label} onClick={() => setOpen(!open)}>
        <EllipsisVertical aria-hidden="true" />
      </button>
      {open && (
        <div className="ux-menu ux-kebab__menu" role="menu" aria-label={label}>
          {list.map((it) => {
            const I = it.icon;
            return <button key={it.label} type="button" role="menuitem" onClick={() => { setOpen(false); it.onSelect(); }}>{I && <I aria-hidden="true" width={16} />}{it.label}</button>;
          })}
        </div>
      )}
    </div>
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
  const pad = { l: 46, r: 12, t: 14, b: 28 };
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
  const line = (r: Array<[number, number]>) => smoothPath(r);
  const area = (r: Array<[number, number]>) => `${line(r)}L${r[r.length - 1][0].toFixed(1)},${pad.t + ih}L${r[0][0].toFixed(1)},${pad.t + ih}Z`;
  const every = W < 480 ? 8 : 4;
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
                <stop offset="0%" stopColor={color} stopOpacity=".32" />
                <stop offset="60%" stopColor={color} stopOpacity=".08" />
                <stop offset="100%" stopColor={color} stopOpacity="0" />
              </linearGradient>
            </defs>
            {ticks.map((t) => (
              <g key={t}>
                <line className="ux-chart__grid" x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} strokeDasharray={t === lo ? undefined : '3 4'} />
                <text className="ux-chart__axis" x={pad.l - 8} y={y(t) + 4} textAnchor="end">{format(t)}</text>
              </g>
            ))}
            {points.map((p, i) => ((i % every === 0 && points.length - 1 - i >= every / 2) || i === points.length - 1) && (
              <text key={p.t} className="ux-chart__axis" x={x(i)} y={H - 6} textAnchor={i === 0 ? 'start' : i === points.length - 1 ? 'end' : 'middle'}>{hh(p.t)}</text>
            ))}
            {runs.map((r, i) => r.length > 1 && <path key={`a${i}`} d={area(r)} fill={`url(#g${gid})`} />)}
            {runs.map((r, i) => r.length > 1
              ? <path key={`l${i}`} className="ux-chart__line" d={line(r)} fill="none" stroke={color} strokeWidth="2.75" strokeLinejoin="round" strokeLinecap="round" />
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
