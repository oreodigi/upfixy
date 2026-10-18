# Upfixy UI Redesign — Founder Visual Direction v1.0

**Status:** Founder-approved visual direction; implementation proposal for developer review. **Scope:** React responsive web application / PWA presentation, not a separate Flutter/React Native binary. **Date:** 2026-10-19.

## 1. Reference assets and priority

The founder prefers the **first colorful GodsEYE-style desktop reference screenshots** supplied in the conversation, and the **seven regenerated Upfixy mockups** (overview, agency client-health board, solo websites, website detail, AI diagnostics, support workflow, quote and resolution). These images are **visual references, not factual application data, architecture decisions or evidence of implemented features**.

**Developer action required:** Obtain and open all attached images before writing UI code. Ensure source images are placed under `docs/design/references/` (or a linked design library with durable access); do not claim pixel-match if references cannot be opened. The currently authored GitHub text specification does **not** contain the binary attachments; request them from the founder if missing. Do not copy example identities, live domain names, stats, fake AI confidence values or payment-provider logos as production data.

The new brand throughout is **Upfixy**, tagline *Monitor · Diagnose · Resolve*. Do not use the GodsEYE name or its infrastructure/private server data: these are entirely separate products.

## 2. Founder acceptance: visual quality

Recreate the *design language* of the founder-approved visuals, then refine readability, hierarchy and accessibility:
- Deep navy left navigation with carefully spaced line icons, glowing electric-blue selected state, Upfixy identity, contextual workspace switcher and only genuinely available menu items.
- Bright off-white content canvas; generous but disciplined grid spacing; soft tinted metric cards; blues, aqua, mint, orange, violet and coral tied to **metric type**, not invented status.
- Metric cards use prominent icons, strong large values, clear labels, tiny trend/mini-sparkline only with real time-series data; refined shadows, subtle gradients, rounded corners and consistent card heights.
- Charts: professional axes, units, tooltips, time ranges, legends; accessible table alternatives and responsive rendering. Charts must never mislabel a synthetic or outdated reading as live.
- Status system distinct from decorative accent palettes: **healthy/verified = green; warning = amber; critical/down = red; information/action = blue; unknown/stale = neutral/slate (NOT green); not connected = outlined neutral; investigation = violet**. Include text/icon label alongside colors; do not rely on color alone.
- Icons: use coherent Lucide set or equivalent; motion may gently animate genuinely changing states, polling and transitions. Respect `prefers-reduced-motion`. Avoid distracting infinite animation and false live indicators.
- Modern type scale, restrained gradients, hover/focus/pressed states, consistent loading skeletons, empty/error/offline states, no clipped text and no overcrowded navigation.
- Reproduce compositional fidelity at desktop 1440px and 1920px, not necessarily literal copy of inaccurate text in generated images. A design review should compare screenshots side by side.

## 3. Screen inventory and expected layouts

| ID | Screen | Desktop | Mobile |
|---|---|---|---|
| UX-01 | Agency overview | 4–6 vibrant KPI cards, monitoring time series, alerts and actionable queue, client/site health breakdown, activity | Two-column compact KPIs when feasible, swipeable summary chips, chart tabs, attention-first feed |
| UX-02 | Agency client board | Health-lane Kanban, clear client counts, per-client site/status summary, filters and technician attribution | Horizontal lane selector or segmented control, **one lane at a time**, vertical client cards, list switch |
| UX-03 | Solo website board | Website-centric Kanban, first-site simplified single-card view, search/list view | Website cards, sticky filters and statuses, collapsible technical details |
| UX-04 | Website detail | Website header, HTTP/DNS/SSL/security/backup tiles, trend chart, incidents, verification provenance, 8 tabs where implemented | Sticky compact domain header, primary health summary, segmented content tabs (Overview, Monitoring, Issues, DNS, SSL, Backups, History); overflow menu for additional tabs |
| UX-05 | Diagnose issue | Evidence panel, timestamps, rule-based cause assessment and grounded optional AI summary, missing access callouts, guided checks | Short issue headline and primary action; tabs **Summary / Evidence / Steps / History**; accordions for technical logs, sources and nontechnical explanations |
| UX-06 | Support work queue | Draggable workflow Kanban, ticket assignments, timers, statuses and client references | Workflow-state chips + single-column ticket cards, explicit Move action (no required drag gesture), accessible assignee/action sheet |
| UX-07 | Quote, approval, resolution | Sequenced steps Request → Evidence → Quote → Approval → Payment → Fix → Verification, quote line items, audit timeline and relevant backup/rollback plans | Vertical stepper with active step expanded, quote summary sticky near approval, payment/approval explicit; timeline in accordion |
| UX-08 | Connectors, settings, onboarding | Clear step-by-step add website, optional connector, limited capability disclosure and permissions | Guided full-width flow, fixed bottom CTA, credential privacy warnings and skip option |

**Do not derive private server-centric features (server maps, virtualizer controls, CPU/RAM/disk graphs, backups completed, automatic repair) from the GodsEYE screenshots.** Display them only if Upfixy has correctly scoped and fresh connector evidence and the user's role permits access. Public URL monitoring never implies access to host telemetry.

## 4. Mobile must feel native — without changing the agreed stack

Treat mobile as a **purpose-designed app shell**, not a shrunken desktop grid:
- 320px through 430px first, plus tablet at 768px. Safe-area aware status/nav spacing and viewport-height handling.
- Compact branded top bar (screen title, alerts, workspace/profile), optional bottom navigation with up to 4–5 **high-frequency destinations**, and a More view for secondary screens. Active state and back navigation must be obvious.
- Primary actions near thumb reach; 44×44px or larger touch targets; buttons should not overflow narrow screens. Use sheets or dialogs only where helpful; ensure keyboard/screen-reader access.
- Large sections become horizontal content tabs or semantic accordions, retaining deep links, shareable state, and last-selection behavior where appropriate. Do not hide serious issues solely inside closed accordions.
- Horizontal Kanban may have a lane selector; **never force the entire desktop board to become tiny side-by-side columns**. Allow list mode and filtering.
- Tables become labeled key/value cards; charts show fewer ticks and may horizontally pan only within a clearly identified chart area; prevent page horizontal overflow.
- Full-height list and details views should have sticky key actions where safe, clear skeleton/loading/empty/failed/unknown states, fast scrolling and small network payloads.
- For PWA (if adopted), add installable icon and app-like presentation, but do not claim native device capabilities or a native APK without a separate approved deliverable.

## 5. Functional and integrity constraints

- Existing FRD v0.3, tenant isolation, role permissions, exact-resource verification and authorization gates remain authoritative. UI redesign must **not** weaken them or change server behavior.
- Keep **computed technical health lanes** separate from **editable support workflow lanes**. Do not make a health badge draggable.
- Unknown, stale, unverified, not connected and disabled must be visibly distinct from healthy; show check time and source/freshness. Never show fabricated readings.
- **Help me understand** always offers plain-language explanation, possible reasons, simple checks, next steps and copyable support message. **Analyze & Find Solution** is offered only when eligible and supported with actual evidence; AI labels must not imply unsupported certainty.
- No invented fix preview, live success, guaranteed rollback, backup completed or inferred root cause. If access is missing, explain connector requirements clearly.
- Quote amount must come from the service catalogue and approved rule/contract; draft values: basic DNS ₹100, basic cPanel ₹200, narrow code ₹300, covered provider-side fixes ₹0 only when verified. Complex issues require quotation. Actual payment/automated repair remains gated per FRD and implementation status.
- No unauthorized live backend endpoints, new provider permissions, new paid subscriptions, credential reuse, production deployments or real mutations are authorized by this design document.

## 6. Components and architecture

Refactor the existing React + TypeScript `web/` pages incrementally, favoring reusable:
`AppShell`, `Sidebar`, `MobileTopbar`, `MobileBottomNav`, `MetricCard`, `StatusBadge`, `Sparkline`, `TimeSeriesChart`, `HealthLane`, `ClientCard`, `WebsiteCard`, `IssueCard`, `EvidencePanel`, `PermissionNotice`, `ActivityTimeline`, `QuoteSummary`, `ResponsiveTabs`, `AccordionSection`, `EmptyState`.

Introduce semantic design tokens for spacing, radius, typography, surfaces, shadows, chart palettes, and status semantics; theme should not bind server state to gradient colors. Preserve existing routes, API contracts, tests, keyboard accessibility, authorization rules and deep links. Avoid unnecessary dependency additions.

## 7. Implementation sequence (reviewable, reversible)

1. Audit existing `web/` screens, navigation, React components, styles and API state against screenshot references. Capture before screenshots at 320, 390, 768, 1440 and 1920.
2. Implement tokens, shell, sidebar, mobile top/bottom nav and shared components in a feature branch.
3. Deliver UX-01 + UX-02 + UX-03 first for founder visual approval; do not rewrite the remainder blindly.
4. Then UX-04 through UX-08, with accurate data-loading, errors, unknown/permission/no-connector/empty states and payment/repair gates.
5. Compare side-by-side against approved reference captures. Perform BrowserStack or equivalent **real-browser** testing on phone, tablet and desktop, including both touch and keyboard.
6. Run existing `scripts/screens.mjs`, `scripts/keyboard.mjs`, web tests and full CI; add screenshot/regression tests. Report deviations and test evidence in the PR. No production rollout without separate authorization.

## 8. Testable acceptance criteria

- **AC-UI-01** Desktop overview matches agreed screenshot composition (navigation, KPI ribbons, colored chart cards, issues and activity) while using Upfixy's actual features and data.
- **AC-UI-02** At 320px/390px/430px, every named screen remains fully usable with **zero unintended horizontal page scroll**, no clipped buttons/labels and no overlapping fixed navigation.
- **AC-UI-03** Native-app-style navigation works with browser Back, keyboard, screen reader and deep links; mobile bottom nav does not cover fixed action controls.
- **AC-UI-04** In mobile board view, lane/status selector and list alternative allow access to every authorized client/website/ticket without sideways board scrolling.
- **AC-UI-05** Long website, diagnostic and resolution pages use tabs/accordions. Critical issue and next recommended action remain discoverable within one initial viewport where feasible.
- **AC-UI-06** Healthy, warning, critical, unknown, stale, not-connected, unverified, loading and permission-denied are distinguishable via both text and icon, not just hue.
- **AC-UI-07** Charts show units, labels, reading interval, time period and historical/freshness semantics; uncollected metrics use placeholders instead of fake values.
- **AC-UI-08** Reduced motion is respected; animated icons/chart transitions do not claim real-time behavior when none exists.
- **AC-UI-09** WCAG 2.1 AA automated accessibility checks pass; all controls support keyboard and sufficient contrast; design is verified on real devices.
- **AC-UI-10** Existing cross-tenant, role, quote, approval, monitor and repair tests still pass; no new unauthorized action paths.
- **AC-UI-11** Founder reviews actual 1440px desktop and 390px mobile screenshots for UX-01 to UX-03 before the remaining screens are implemented.
- **AC-UI-12** Screens clearly indicate **demonstration data** when rendering a demo; no fictitious 'live', '92% AI confidence', 'backup completed', revenue, geolocation or payment method labels in production.

## 9. Developer handoff message

> Review every attached founder-approved Upfixy dashboard image before modifying the UI. The founder rejects the previous plain/boring dashboard concepts. Recreate the premium navy-sidebar + bright off-white canvas + vivid gradient KPI cards + elegant charts + status-tinted Kanban cards + refined icons and subtle motion. Match desktop closely, with improvements for clarity, responsiveness and accessibility. Build the mobile web experience as a **native-app-style interface**, using a compact top bar, persistent bottom navigation for priority features, thumb-friendly actions, **tabs and accordions** wherever desktop panels are too long, and never a squeezed desktop grid. Preserve Upfixy's FRD, separate technical health from workflow statuses, keep stale/unknown readings honest and never invent operational data. Work in a branch, show 1440px and 390px previews of the first three screens for approval, then implement the rest and pass all existing tests. Do not deploy or connect production as part of this design change.
