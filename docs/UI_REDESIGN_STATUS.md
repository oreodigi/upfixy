# UI redesign: status (phase 1)

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

- UX-04…08 (website detail, diagnostics, work queue, quote and resolution, settings, alerts): these still use the earlier styles inside the new shell.
- Checks at 768 px and 1920 px, and on real devices with a screen reader (AC-UI-09).
- A visible "demo data" banner for seeded workspaces (AC-UI-12): the live app only ever shows real data from the API. The `?demo` build keeps its existing label.
