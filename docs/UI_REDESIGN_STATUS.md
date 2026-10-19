# UI redesign: status (phase 2)

**Phase 2 (19 Oct 2026)** finishes UX-04…08 on the approved direction. Every screen uses real API data; where an API doesn't exist the screen says so.

| Screen | What it does now | Endpoints |
| --- | --- | --- |
| UX-04 Website detail | Header with health, ownership and last check; six KPIs (uptime, response, SSL, domain, security "Not assessed", backups "Not connected"); tabs Overview / Domain / SSL / Errors / Hosting / Security / Backups / Activity; problems with Help me understand; response chart; incidents; area status; requests; past diagnoses | `websites/{id}`, `websites/{id}/history`, `boards/metrics`, `websites/{id}/diagnostics`, `websites/{id}/dns-connections`, `websites/{id}/providers`, `POST websites/{id}/recheck`, `POST websites/{id}/diagnose` |
| UX-05 Diagnosis | The seven customer questions in order. Measured facts ("Measured"), rule-based causes (confidence badge) and AI suggestions (dashed violet "AI suggestion · not verified") look different. "Can Upfixy fix it?" is decided from repair controls + a live DNS connection + the issue type; otherwise "A technician needs to do this". Cost from the price list, likely tier highlighted, "₹0 still needs your approval". **No repair button**: "Request a fix" opens a request linked to this diagnosis | `diagnostics/{id}`, `repair-controls`, `actions`, `POST tickets` |
| UX-06 Work queue | Workflow lanes (never health colours), KPIs, search, assignee and stage filters, saved views, board/list. Desktop: drag or "Move to"; phones: one stage at a time with a stage picker | `tickets`, `POST tickets/{id}/transition`, `views` |
| UX-07 Support request | Full-height sheet (full screen on phones): stepper Request → Analysis → Quote → Approval → Payment → Fix → Verification computed from server facts; problem with link to its diagnosis; quote with price, scope, exact change and expiry; approval (payer only, ₹0 included); payment (Razorpay test mode); repair preview / progress; assignment; stage moves from `allowed_next` (moves needing approval or payment are disabled with the reason); timeline from `events` | `tickets/{id}`, `tickets/{id}/assignable`, `PATCH tickets/{id}/assignee`, `tickets/{id}/quotes`, `tickets/{id}/approvals`, `tickets/{id}/payments`, `approvals/{id}/checkout`, `tickets/{id}/repairs`, `repair-drafts`, `approvals/{id}/repairs`, `repair-attempts/{id}` |
| UX-08 Onboarding, dialogs, settings | "Add website" shows what happens next (first checks, verify ownership, connect DNS/hosting). Alerts, Integrations, Billing, Team, Settings, client detail and all dialogs are mapped onto the design tokens (cards, inputs, buttons, notices, bottom sheet on phones) without changing their behaviour | unchanged |

**Backend change in this phase:** migration `0018_ticket_diagnosis_link.sql` adds `tickets.diagnostic_run_id` (composite FK in the same workspace, plus a trigger that refuses a diagnosis from another website). `POST tickets` accepts it (422 if it isn't visible or is for another website); `GET tickets/{id}` and `GET diagnostics/{id}` return the link. Tests: `DiagnosticsTest::test_a_fix_request_can_link_its_diagnosis_on_the_same_website_only`, `…_from_another_workspace_cannot_be_linked`.

**Safety rules kept:** no repair can start from the diagnosis page; the automated-fix button appears only when the server reports an approved, paid (or ₹0) quote in "Ready for work", and the server still checks repair controls (currently off platform-wide). Approval is explicit even at ₹0.

# Phase 1, revision 2

**Revision 2 (19 Oct 2026)** moves UX-01…03 closer to the founder references:
- KPI cards: gradient icon discs, larger values, and trends drawn only from real hourly series (Healthy = availability per hour; Critical = failed checks per hour).
- Dashboard rows follow R2: monitoring overview + attention list, then top affected websites + health breakdown, then requests, renewals and activity. Smooth (monotone) chart curves that never overshoot 100%.
- Client board: urgent issues, workload and quick actions sit beside the lanes from 1280 px (R3). Area chips use a status shape (✓ ! ? ○) plus the area name, never colour alone.
- Website board: secondary actions move to a "⋮" menu; Help me understand and Analyze & find solution are the card buttons (R4). On phones, cards show three key numbers and a "Technical details" disclosure.
- AC-UI-12: workspaces created by the dev seed carry `is_demo` (migration 0017; only the schema owner can set it) and show a "Demonstration data" banner.
- Search: Ctrl/⌘ K or "/" focuses it. The tagline is "Monitor · Diagnose · Resolve".
- Demo history now differs per website, so sparklines aren't identical copies.


Spec: [UI_REDESIGN_DIRECTION.md](UI_REDESIGN_DIRECTION.md). References: [design/references](design/references/README.md).

**Phase 1** covers UX-01 Dashboard, UX-02 Client board and UX-03 Website board, plus the shared shell and design system. Per AC-UI-11, UX-04…08 wait for founder approval of the 1440 px and 390 px screenshots.

## What changed

| Area | Change |
| --- | --- |
| Design tokens | `web/src/ui/theme.css`. Inter font; off-white canvas; navy sidebar. Accent gradients belong to a metric **type**. Status colours are separate and always shown with an icon and a word |
| Shell | Desktop: navy sidebar, global search, alerts bell, user menu. Phones: compact top bar, bottom navigation (up to 4 destinations + More sheet). Same routes, role gating and closed-workspace handling as before |
| Components | `ui/kit.tsx`: StatusBadge, MetricCard, Sparkline, TimeSeriesChart (tooltip and table alternative), Donut, Panel, Accordion, ResponsiveTabs, EmptyState, Monogram. `ui/blocks.tsx`: AttentionQueue, ActivityTimeline. `ui/Shell.tsx` |
| Icons | `lucide-react` 1.48.0 (pinned) |
| UX-01 Dashboard | 6 KPIs, 24 h availability and response chart, attention queue (incidents with Acknowledge), websites by health, problems by area, websites needing attention, support requests by stage, activity. Phones: KPIs, attention first, then tabs (Trends / Websites / Activity / Requests) |
| UX-02 Client board | KPIs (paid this month only for owner, admin and billing; labelled test mode), health lanes, client cards (Web / DNS / SSL / Backup chips), urgent issues, technician workload, quick actions. Phones: lane selector, one lane at a time, list mode, accordions |
| UX-03 Website board | KPIs, website cards (24 h availability, SSL days, DNS, response, backups "Not connected", response sparkline, Help me understand, Analyze), alerts, certificate renewals, activity. One-site workspaces get a spotlight with a response chart |

## Honesty rules applied

- Health lanes are computed and never draggable. Support requests appear only as separate violet request badges and a workflow panel.
- Unknown and Needs setup are slate, never green. On desktop they share one "No fresh readings" column; each card still names its exact state.
- Charts use real hourly buckets from `boards/metrics`. An hour with no readings is a gap, never zero. Every chart states its period and interval, and has a table alternative.
- Sparklines appear only on real series: availability and per-site response.
- Backups show "Not connected", because there is no backup connector.
- No live badges, AI confidence, revenue, maps or payment logos.
- "Analyze" only starts a diagnosis (the same `POST websites/{id}/diagnose` as the detail page). Nothing is repaired automatically.

## Server change (the only one)

`GET /api/v1/boards/metrics` is a read-only aggregation under the caller's RLS context. It returns:
- 24 hourly HTTP buckets;
- per-website availability, response and TLS days left;
- the last 12 incident and request events.

It is covered by `MetricsTest` (values, null gaps, workspace isolation, client-owner scoping). It takes no resource id, so like the other list endpoints it is not part of the per-resource cross-tenant sweep; isolation comes from RLS and is asserted in `MetricsTest`.

## Dev seed

`scripts/seed-dev.mjs` now writes 24 h of **synthetic** HTTP history for screenshots, and adds a "Demo Solo, several sites" workspace. This is **demo data**. Production has none of it.

## Not yet done

- Alerts, Integrations, Billing, Team and Settings keep their earlier layouts (restyled, not rebuilt).
- Checks at 1920 px, and on real devices with a screen reader (AC-UI-09). 768 px is now in the automated audit.
- Security and backup areas show "Not assessed" / "Not connected" until those connectors exist.
