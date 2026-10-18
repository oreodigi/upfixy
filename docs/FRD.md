# Upfixy — MVP Functional Requirements (FRD) v0.3

Oct 16, 2026 · @pradeep

## 1. Purpose and scope

This FRD defines what the Upfixy MVP must do before the Larihost, Peak99 and SKClouds pilot, including support, payments and technician-assisted resolution. Public growth is out of scope; §22 gives the single delivery sequence (M0–M7). It is a planning baseline only: no server purchase, deployment, production connection or charging is approved by this document.

**Status:** v0.3 founder-approved scope amendment (18 October 2026): AI-assisted evidence-grounded diagnostics and narrowly scoped automated fixes promoted to P0. This is a requirements decision, not implementation, deployment, provider authorization, or payment approval. D-04/D-07 remain approved; D-01/D-02 remain deferred.

**Source of truth:** Website Diagnostics & Resolution SaaS Master Report v1.0 (15 Oct 2026). Section numbers below cite it as MR §n.

**Provenance labels** used on every requirement:

| Label | Meaning |
| --- | --- |
| FD | Founder decision — stated by the founder; change only with founder sign-off |
| REC | Recommendation — proposed design; validate before treating as commitment |
| EXT | External verified fact — from provider/competitor public docs (MR App. C) |
| OPEN | Open decision — blocked until confirmed (see §11) |

**Priority:** P0 = must ship before pilot · P1 = pilot-period follow-on · P2 = post-pilot.

**Feature ID scheme:** `MOD-nnn`, where MOD is the module code in §3. User stories are `US-nnn`; acceptance criteria are `AC-MOD-nnn.x`.

## 2. Personas and roles

Solo and agency users run on the same tenant engine; only defaults and navigation differ (FD, MR §2).

| Persona | Workspace type | Default board | Core need |
| --- | --- | --- | --- |
| Solo owner | Solo | Websites | Add a URL, understand problems, buy a fix if needed |
| Agency owner | Agency | Clients | See every client's health, assign staff, sell fixes |
| Hosting reseller | Agency (provider flag) | Clients | Import only its own WHM/reseller accounts; cover provider-side faults free |
| Agency technician | Agency member | Work queue | Diagnose and fulfil assigned tickets |
| Client owner | Invited into agency workspace | Websites (assigned only) | See own sites, approve and pay quotes |
| Client staff | Invited into agency workspace | Websites (assigned only) | Read-only visibility, raise tickets |
| Platform admin | Platform | Admin console | Operate tenants, catalogue, payments, abuse; just-in-time data access only |

**Workspace rules (FD/REC):**

- A user can belong to many workspaces, each with its own role.
- The workspace is the security and billing boundary.
- Agency → client links are explicit records, never implied privileges.
- Seeing a website never grants power to change its registrar, DNS or hosting.
- The same domain in two workspaces shares nothing unless both sides authorise it.

## 3. MVP feature list

Expanded MVP feature inventory; original feature IDs remain stable and new AI/repair IDs are defined in §25. Earlier feature counts are historical. Modules follow the build order: tenancy → boards → monitoring → integrations → diagnostics → service and payments → fixes.

| ID | Feature | Pri | Label |
| --- | --- | --- | --- |
| **ACC — Accounts and tenancy** |  |  |  |
| ACC-001 | Signup (email/password), email verification, choose "For myself" or "For my clients" (editable later) | P0 | FD |
| ACC-002 | MFA; mandatory for provider connection, token rotation/revocation, privileged approval and payment-method change | P0 | REC |
| ACC-003 | Workspace create/rename; one tenant engine for solo and agency | P0 | FD |
| ACC-004 | Multi-workspace membership and workspace switcher | P0 | REC |
| ACC-005 | Invitations with role assignment; suspend/remove member | P0 | FD |
| ACC-006 | Client profiles inside agency workspace; assign websites and staff | P0 | FD |
| ACC-007 | Immutable audit log of privilege, connection, approval and payment events | P0 | REC |
| ACC-008 | Social/SSO login | P2 | REC |
| **AST — Websites and assets** |  |  |  |
| AST-001 | Add website by URL with no credentials; validate public DNS; apex and www kept as distinct targets (no silent merge) | P0 | FD |
| AST-002 | Domain control (DNS TXT/HTML file) or verified delegated authorization required before privileged association, private data or deep checks | P0 | REC |
| AST-003 | Duplicate-claim handling: second workspace sees no private data; delegation request flow | P0 | REC |
| AST-004 | Asset graph per website: registrar, DNS zone, hosting account, server links | P0 | FD |
| **BRD — Boards and views** |  |  |  |
| BRD-001 | Client Kanban (agency default) with health lanes computed by monitoring, not draggable | P0 | FD |
| BRD-002 | Website Kanban (solo default); single-site users get a card view without empty lanes | P0 | FD |
| BRD-003 | Work Kanban (tickets) with draggable workflow lanes and guarded transitions | P0 | FD |
| BRD-004 | List/table alternative for every board; search, filter, saved views | P0 | FD |
| BRD-005 | Status icons for domain/hosting/SSL/security/backup/errors with known/warning/critical/unknown/not-connected states | P0 | FD |
| BRD-006 | Mobile swipeable lanes and keyboard navigation | P0 | FD |
| BRD-007 | Permission-aware CSV export | P1 | REC |
| **MON — Public monitoring** |  |  |  |
| MON-001 | HTTP(S) check: status, redirects (max 10), response time, keyword optional | P0 | FD |
| MON-002 | TLS check: validity, hostname match, chain, days to expiry | P0 | FD |
| MON-003 | DNS check: A/AAAA/CNAME/MX/NS/SOA/CAA, DNSSEC indicator, multi-resolver | P0 | FD |
| MON-004 | TCP connect check on allowed ports | P1 | REC |
| MON-005 | Scheduler with plan intervals (5 min default, 10 min free); retries, debounce, recovery confirmation | P0 | REC |
| MON-006 | SSRF/rebinding protection, egress allowlist, per-tenant rate limits | P0 | REC |
| MON-007 | Freshness model: fresh / stale / unknown on every reading | P0 | FD |
| MON-008 | Incident lifecycle: open, acknowledge, auto-resolve on verified recovery, dedupe by root event | P0 | REC |
| MON-009 | Second probe region | P1 | REC |
| **ALR — Alerts** |  |  |  |
| ALR-001 | Email alerts and in-app inbox; per-client and per-user preferences | P0 | FD |
| ALR-002 | Maintenance windows and mute | P1 | REC |
| ALR-003 | Outbound webhooks | P1 | REC |
| **KB — Plain-language help** |  |  |  |
| KB-001 | Help me understand: meaning, causes, checks, next step, copyable support message | P0 | FD |
| KB-002 | Versioned issue library, ≥ 40 issue codes at launch, recreated (not copied from Gods Eye) | P0 | FD |
| KB-003 | Refresh readings: states whether it shows stored result or runs a recheck | P0 | FD |
| **INT — Connectors (read-only by default; narrowly scoped DNS-write adapter under FIX-003)** |  |  |  |
| INT-001 | Connector framework: capability declaration, encrypted secrets, test/sync/revoke/rotate | P0 | REC |
| INT-002 | WHM reseller-scoped connector: list accounts, domains, suspension, SSL/AutoSSL status | P0 | FD |
| INT-003 | cPanel account connector (UAPI token) | P0 | FD |
| INT-004 | Cloudflare zone-scoped read-only DNS connector | P0 | FD |
| INT-005 | First registrar connector (provider TBD) | P1 | OPEN |
| INT-006 | Import preview and selective import; never auto-claim by domain name | P0 | FD |
| **DX — Diagnostics** |  |  |  |
| DX-001 | Analyze & Find Solution: eligibility gate, evidence run, skipped-check reasons | P0 | FD |
| DX-002 | Deterministic rule engine producing cause + confidence (confirmed/likely/possible/unknown) | P0 | FD |
| DX-003 | Diagnostic report screen and shareable copy | P0 | FD |
| DX-004 | Responsibility classification: provider-covered vs customer-side vs needs quote | P0 | FD |
| DX-005 | AI-assisted evidence-based explanation, hypotheses and solution recommendations; structured, cited outputs | P0 | FD (scope approved), REC (design) |
| DX-006 | AI safety gateway, model audit, evidence redaction, fallback and cost budget | P0 | REC |
| **SVC — Tickets, quotes, payments** |  |  |  |
| SVC-001 | Ticket with diagnosis attached; assign technician; status history | P0 | FD |
| SVC-002 | Service catalogue ₹0/₹100/₹200/₹300 with scope, exclusions, SLA; custom quote route | P0 | FD |
| SVC-003 | Quote → approval binding to exact resource and action, with expiry | P0 | FD |
| SVC-004 | Razorpay checkout; server-side webhook verification; idempotent order creation | P0 | REC |
| SVC-005 | Invoices with GST fields; refund records | P0 | OPEN |
| SVC-006 | SaaS subscriptions and plan limits | P1 | REC |
| **FIX — Controlled fixes** |  |  |  |
| FIX-001 | Technician-performed fix with checklist, before/after evidence capture | P0 | FD |
| FIX-002 | Post-fix verification rerun; unresolved keeps ticket open | P0 | FD |
| FIX-003 | Automated allowlisted Cloudflare DNS record change with preview, scoped approval, idempotency, rollback and recheck | P0 | FD (scope approved), REC (operation design) |
| FIX-004 | Repair policy engine and execution orchestration: approval, payment/coverage, backup/rollback, expiry, lock and verification gates | P0 | FD (scope approved), REC (design) |
| FIX-005 | Kill switch, emergency stop, retry safety, reconciliation and automatic escalation for ambiguous outcomes | P0 | REC |

## 4. User stories

The master report's 23 stories are kept with their IDs; four P0 stories are added (US-024 to US-027) to cover gaps.

| Story | As a… I want… | Features | Pri |
| --- | --- | --- | --- |
| US-001 | Solo user: add one domain by URL and see monitoring without credentials | AST-001, MON-001–003, MON-007 | P0 |
| US-002 | Agency owner: create clients and assign websites | ACC-006 | P0 |
| US-003 | Any user: see health Kanban cards plus a searchable list | BRD-001, BRD-002, BRD-004, BRD-005 | P0 |
| US-004 | Client owner: see only my assigned assets and incidents | ACC-005, ACC-006 | P0 |
| US-005 | Agency owner: connect WHM reseller with scoped access and preview imports | INT-001, INT-002, INT-006 | P0 |
| US-006 | Any user: connect Cloudflare read-only and see real DNS evidence | INT-004 | P0 |
| US-007 | Any user: receive readable alerts and a specific issue explanation | ALR-001, KB-001, KB-002 | P0 |
| US-008 | Any user: run an authorised diagnosis separating confirmed and uncertain findings | DX-001–003 | P0 |
| US-009 | Technician: open/assign a ticket with diagnosis attached | SVC-001, BRD-003 | P0 |
| US-010 | Any user: see a clear quote and accept or decline a standard fix | SVC-002, SVC-003, DX-004 | P0 |
| US-011 | Paying customer: get payment confirmation and full support history | SVC-004, SVC-005 | P0 |
| US-012 | Security owner: revoke provider tokens and see private checks stop | INT-001, ACC-007 | P0 |
| US-024 | Any user: prove I own a domain before deep checks run | AST-002, AST-003 | P0 |
| US-025 | Agency owner: connect a single cPanel account for a client not on my WHM | INT-003 | P0 |
| US-026 | Technician: record a fix with before/after evidence and have it auto-verified | FIX-001, FIX-002 | P0 |
| US-027 | Platform admin: view tenants, orders and job health; open customer data only with logged just-in-time access | ACC-007 | P0 |
| US-013 | Multi-location checks, alert sensitivity, maintenance windows | MON-009, ALR-002 | P1 |
| US-014 | Registrar API for expiry and owned domains | INT-005 | P1 |
| US-015 | Fine-grained customer/team roles and staff assignment | ACC-005 | P1 |
| US-016 | Approved reversible DNS repair with before/after and verification | FIX-003–005 | P0 |
| US-017 | Agency billing/revenue summary and fulfilment analytics | SVC-006 | P1 |
| US-018 | Account-level SSL/hosting status and backup metadata indicators | INT-002, BRD-005 | P1 |
| US-019 – US-023 | White label, more connectors, crawler, advanced AI guidance, public API/SSO | — | P2 |

## 5. Screen inventory

18 screens for MVP; responsive web/PWA, no native apps (FD, MR §10).

| ID | Screen | Who sees it | Key actions | Features |
| --- | --- | --- | --- | --- |
| SCR-01 | Signup and onboarding wizard | All | Choose type, verify email, add site or connect account | ACC-001, AST-001 |
| SCR-02 | Overview | All | Top incidents, acknowledge, jump to board | MON-008 |
| SCR-03 | Client board / list | Agency | Search, filter, open client, assign staff | BRD-001, BRD-004 |
| SCR-04 | Website board / list | All | Open, analyse, export | BRD-002, BRD-004 |
| SCR-05 | Work board (tickets) | Agency staff | Drag workflow stage, assign | BRD-003, SVC-001 |
| SCR-06 | Client detail | Agency, client owner | Assets, contacts, coverage, invite client | ACC-006 |
| SCR-07 | Website detail (tabs: Overview, Domain, Hosting, SSL, Security, Backups, Errors, Activity) | Assigned users | Help me understand, Analyze, request fix | AST-004, KB-001, DX-001 |
| SCR-08 | Add website / ownership verification | All | Enter URL, verify by TXT/file or delegated provider authorization (D-07) | AST-001, AST-002 |
| SCR-09 | Integrations list | Owner/admin | Connect, test, sync, revoke, rotate | INT-001 |
| SCR-10 | Connect wizard + import preview | Owner/admin | Scope explanation, test, select assets | INT-002–004, INT-006 |
| SCR-11 | Diagnostic report | Assigned users | View checks, confidence, create request | DX-002, DX-003 |
| SCR-12 | Quote and approval | Client owner, solo owner | Review scope and price, approve/decline, pay | SVC-002, SVC-003, SVC-004 |
| SCR-13 | Ticket detail | Staff, requester | Notes, evidence, run checklist, verify | SVC-001, FIX-001, FIX-002 |
| SCR-14 | Team and permissions | Owner/admin | Invite, change role, suspend | ACC-005 |
| SCR-15 | Billing and invoices | Owner, billing role | Pay, download invoice, refund status | SVC-004, SVC-005 |
| SCR-16 | Notifications and settings | All | Alert channels, preferences, MFA | ALR-001, ACC-002 |
| SCR-17 | Audit log | Owner/admin | Filter events, export | ACC-007 |
| SCR-18 | Platform admin console | Platform admin | Tenants, catalogue, orders, jobs, JIT access | US-027 |

**UX rules (FD):** Help me understand sits directly under every issue. Analyze shows only when eligible; otherwise "Connect hosting/DNS to unlock deeper checks". No "Fix now" button on an unverified diagnosis. Colour is never the only status signal.

## 6. Role and permission matrix

Seven workspace roles; every check is enforced server-side per workspace and, for client roles, per assigned client (REC). A = all in workspace · C = assigned clients/sites only · — = denied.

| Action | Owner | Admin | Technician | Billing | Client owner | Client staff | Solo owner |
| --- | --- | --- | --- | --- | --- | --- | --- |
| View boards and website detail | A | A | C | — | C | C | A |
| Add website | A | A | — | — | C\* | — | A |
| Verify ownership | A | A | — | — | C | — | A |
| Create/edit clients | A | A | — | — | — | — | — |
| Invite / change roles | A | A (not Owner) | — | — | C (client staff only) | — | A |
| Connect / revoke integration | A (MFA) | A (MFA) | — | — | C\* (MFA) | — | A (MFA) |
| Run public recheck | A | A | C | — | C | C | A |
| Run Analyze (private evidence) | A | A | C | — | C | — | A |
| View raw logs / advanced details | A | A | C | — | — | — | A |
| Create ticket | A | A | C | — | C | C | A |
| Move ticket workflow stage | A | A | C | — | — | — | — |
| Issue quote | A | A | C | — | — | — | — |
| Approve quote / pay | Contract only† | — | — | A | C | — | A |
| Perform fix / record evidence | A | A | C | — | — | — | — |
| View invoices | A | — | — | A | C | — | A |
| Change plan / payment method | A (MFA) | — | — | A (MFA) | — | — | A (MFA) |
| View audit log | A | A | — | — | — | — | A |
| Delete workspace | A (MFA, 7-day delay) | — | — | — | — | — | A (MFA, 7-day delay) |

\* Only if the agency enables client self-service for that client (OPEN, see D-09).

**Hard rules:** for client work, the payer is the client owner or client billing contact — an agency owner approves or pays only where the agency–client contract delegates that authority (†); a technician never sees payment methods; a client role never sees other clients; platform admins have no standing data access — each access is time-boxed, reasoned and logged.

## 7. Connector capability assumptions

MVP WHM, cPanel and ordinary Cloudflare integrations are read-only; a separate restricted Cloudflare DNS-write adapter is P0 solely for FIX-003–005 after the §25 authorization, testing and rollback gates (FD/REC). Read-only describes what Upfixy calls, not the token's privileges — a broader token is a higher-risk exception, not proof of read-only enforcement. Every assumption below must be proven against a pilot test account before integration code is merged.

| Connector | Credential | Read capabilities assumed | Write in MVP | Isolation risk | Label |
| --- | --- | --- | --- | --- | --- |
| Public probes | None | HTTP, TLS, DNS, redirects, timing | None | SSRF, abuse of third-party sites | REC |
| WHM reseller | Reseller API token with limited ACLs | Owned accounts, domains, suspension state, SSL/AutoSSL status, disk/bandwidth usage | None | Root credentials prohibited; reject root-like breadth and test actual reseller isolation | EXT (verify, S12) |
| WHM provider-mediated | Not permitted in MVP under D-04; possible separate future decision | None in MVP | None | Root credential exposure | Deferred/out of scope (FD) |
| cPanel account | cPanel API token (UAPI) | Domains, SSL status, email/DNS zone if hosted locally, error log tail | None | Token may allow writes; app must enforce read-only | EXT (verify, S13) |
| Cloudflare | API token scoped to selected zones, Zone:Read + DNS:Read | Zones, DNS records, DNSSEC, proxy status | None (DNS:Edit is P1, separate token) | Account-wide token — warn and recommend zone scoping | EXT (verify, S14) |
| Registrar #1 | Provider-specific API key | Domain list, expiry, status, registrar nameservers | None | Some registrars need IP allowlisting | OPEN (D-03) |
| Backup metadata | Via WHM/cPanel where exposed | Last reported run, age, status — never "healthy" without restore proof | None | Over-claiming safety | REC |
| Server agent | — | Not in MVP | — | — | P2 |

**Rules for every connector (REC):** declare capabilities at connect time; store secrets encrypted outside the main DB key; never log tokens; revoke stops private checks within one sync cycle; connector failure marks only its own data stale.

## 8. Acceptance criteria

Each P0 feature passes only when its criteria pass in staging with synthetic fixtures. Times assume the pilot VPS sizing in MR §14.2.

| AC ID | Given / When / Then | Test type |
| --- | --- | --- |
| AC-ACC-003.1 | Given tenants A and B, when a user of A requests any B resource ID via API, then the response is non-disclosing (404), no data returns, and a security event is logged without leaking existence | Automated security |
| AC-ACC-002.1 | Given a user without MFA, when they open Connect integration, then they are forced through MFA setup first | E2E |
| AC-ACC-005.1 | Given a client owner of Client X, when they list websites, then only Client X sites return, including via search and export | E2E + API |
| AC-ACC-007.1 | Given any role change, connection, approval or payment, then an audit row records actor, target, action, time and IP; rows cannot be edited | Automated |
| AC-AST-001.1 | Given a valid permitted public URL, when a solo user adds it, then checks queue immediately and first HTTP/TLS/DNS results target ≤ 2 minutes under normal pilot load; timeouts show as such, never as healthy | E2E |
| AC-AST-001.2 | Given URL `http://169.254.169.254` or a domain resolving to 10.0.0.0/8, then it is rejected with a plain reason | Security |
| AC-AST-003.1 | Given a domain verified in workspace A, when B adds it, then B gets policy-limited public checks only; no A data, metadata or existence signal is disclosed | API |
| AC-BRD-001.1 | Given a client with one critical and two healthy sites, then the client card sits in Critical and shows the critical site first | E2E |
| AC-BRD-001.2 | When a user tries to drag a health card, then nothing moves; health lanes have no drag handles | E2E |
| AC-BRD-003.1 | When a ticket is moved to Resolved without a passed verification, then the move is blocked with a reason prompt | E2E |
| AC-BRD-005.1 | Given no backup connector, then backup icon reads "Not connected", never green | Visual + unit |
| AC-MON-005.1 | Given a single failed cycle followed by success, then no incident opens; given 3 consecutive failed cycles, an incident opens | Fixture |
| AC-MON-005.2 | Given 1,000 sites at 5-minute intervals, then 99% of checks run within 60 s of schedule over 24 h | Load |
| AC-MON-006.1 | Given a domain that rebinds from public to private IP between resolve and connect, then the connection is refused | Security |
| AC-MON-007.1 | Given no result for 3× the check interval, then status becomes Stale and the card lane becomes Unknown | Fixture |
| AC-KB-001.1 | Given each of DNS SERVFAIL, NXDOMAIN, TLS expired, TLS mismatch, HTTP 403, 503, timeout, stale data, then a distinct guide renders with all four sections and a copy button | Fixture |
| AC-INT-002.1 | Given a WHM token with root-equivalent account visibility, when validated by safe capability testing, then the tenant integration is rejected even if labelled reseller | Security |
| AC-INT-002.2 | Given reseller R1 on a server shared with R2, then imports show only R1 accounts | Integration |
| AC-INT-001.1 | When a token is revoked, then private checks stop within one sync cycle, data is marked stale, public checks continue | Integration |
| AC-INT-006.1 | Given a WHM import, then nothing is created until the user selects assets in the preview | E2E |
| AC-DX-001.1 | Given no hosting connector, when Analyze runs on HTTP 503, then hosting checks show "skipped — not connected"; 503 is a confirmed observation and any hosting root cause stays Unknown | Fixture |
| AC-DX-002.1 | Given a repeatable DNSSEC failure with correlated authoritative and validating-resolver evidence, then DNSSEC validation failure is Confirmed; an isolated SERVFAIL alone is insufficient | Fixture |
| AC-SVC-003.1 | When the target or action of an approved quote changes, then approval is void and re-approval is required | API |
| AC-SVC-004.1 | Given the same Razorpay webhook delivered 3 times, then exactly one order and one ticket exist | Integration |
| AC-SVC-004.2 | Given a client-side "payment success" without verified webhook, then order stays pending | Security |
| AC-FIX-002.1 | Given a fix whose post-check still fails, then the ticket returns to Investigating and the customer is told it is unresolved | E2E |

## 9. Non-functional requirements

Pilot targets are deliberately modest; they are commitments only after load testing (REC).

| ID | Area | Requirement |
| --- | --- | --- |
| NFR-01 | Capacity | Goal: 1,000 sites × 288 cycles/day = 288,000 check cycles/day; individual HTTP/TLS/DNS probe jobs exceed this; prove by load test |
| NFR-02 | Latency | Board loads in < 2 s at 200 cards on 4G mobile |
| NFR-03 | Availability | App 99.5% monthly during pilot; monitored by an independent external service |
| NFR-04 | Durability | Durable, idempotent persistence of approvals and verified payment events; daily DB backup, offsite copy, restore tested before pilot; RPO/RTO to be ratified |
| NFR-05 | Security | OWASP ASVS L2 baseline; secrets envelope-encrypted; no secrets in repo or logs |
| NFR-06 | Tenancy | Workspace scoping enforced in app and PostgreSQL row-level security |
| NFR-07 | Accessibility | WCAG 2.1 AA; 44 px touch targets; icon + text for every status |
| NFR-08 | Time | Store UTC; display in user's zone (default Asia/Kolkata); currency INR |
| NFR-09 | Retention | Raw check results 30 days (Free) / 90 days (paid); audit log 1 year (OPEN, D-12) |
| NFR-10 | Isolation | Own domain, VPS, DB, repo, credentials; no dependency on Gods Eye |
| NFR-11 | Localisation | English at launch; strings externalised for Hindi later |

## 10. Out of scope for MVP

- Automated writes to WHM, cPanel, registrars or servers remain excluded; only P0 allowlisted Cloudflare DNS edits under FIX-003–005 may write
- SSH access of any kind; server agent
- Full-site crawler, JS-error and broken-link scans
- Public status pages, white label, custom agency domains
- Plesk, DirectAdmin, WordPress connectors
- Public API, SSO, WhatsApp/SMS alerts
- Unconstrained AI diagnosis or ungrounded root-cause claims; P0 AI may summarize evidence and propose hypotheses, but cannot override measured facts or deterministic guardrails
- Backup storage or restore operations
- Native mobile apps

## 11. Founder decisions required

14 tracked decisions. D-04 and D-07 are approved; D-01 and D-02 remain deferred with temporary development arrangements. Procurement and production/pilot connections remain unauthorized.

| ID | Decision | Recommended default | Blocks |
| --- | --- | --- | --- |
| D-01 | **OPEN / DEFERRED (FD):** Use `agent25.in` as temporary development/test domain; keep **Upfixy** as working product name. Decide on and purchase Upfixy domain only after development and testing, subject to trademark and availability checks. No domain purchase authorized. | Earlier recommendation: .com + .in | Public branding/canonical production URL |
| D-02 | **OPEN / DEFERRED (FD):** Develop and test on an existing server, but only following a separate environment/isolation and capacity review and explicit connection/deployment authorization. Buy a dedicated Upfixy server after development/testing. Existing server identity/fitness not yet validated. | Earlier target: India, 4 vCPU / 8 GB / 160 GB NVMe (not yet validated) | Production infrastructure procurement and cutover |
| D-03 | First registrar connector | Pick from pilot partners' actual domain inventory (most domains wins) | INT-005 |
| D-04 | **APPROVED (FD):** Reseller-scoped API tokens only. Reject root, root-equivalent or unproven-breadth WHM tokens; no root-token broker in MVP. Verify server-side cross-reseller isolation prior to enabling import. | Reseller-only connector | INT-002 implementation and scope testing |
| D-05 | Who fulfils paid fixes, who gets paid | Partner fulfils own clients; platform takes a % commission; written partner agreement | SVC-002–004 |
| D-06 | Merchant of record for partner repairs | Partner bills and collects for its own repairs; Upfixy bills only its subscriptions. Do not assume Razorpay Route fits every partner until legal, KYC and API checks are done | SVC-004, GST |
| D-07 | **APPROVED (FD):** Non-invasive, throttled public monitoring without proof; private checks/linking require scoped DNS TXT or HTML proof **or** verified, revocable, expiring provider/customer delegation for the exact assets. Connection alone is never ownership; any repair additionally requires exact-action approval. | Layered proof + delegation | AST-002, AST-003, INT and diagnostics |
| D-08 | Exact scope of ₹100 / ₹200 / ₹300 tasks | Fixed list of \~10 tasks per tier, 15-min cap, else custom quote | SVC-002 |
| D-09 | Can client owners add sites and connect integrations? | Off by default; agency enables per client | Permissions |
| D-10 | Free-tier limits | 1 site, 10-min checks, email alerts, 30-day history | Billing |
| D-11 | Pilot pricing | Pilot free for 3 months for partners; fixes priced as catalogue | Billing |
| D-12 | Data retention periods | As NFR-09 | Storage sizing |
| D-13 | Refund policy for failed fixes | Full refund if post-fix verification fails and no partial work accepted | SVC-005 |
| D-14 | GST registration and invoicing entity | Invoice from Oreodigi entity or new company — needs CA advice | SVC-005, launch |

D-04 and D-07 are approved as product policies, **not** as permission to create tokens or connect to WHM. D-01 and D-02 remain deferred for production; `agent25.in` and an existing server are temporary development intentions only. No purchase, deployment, connection, credential handling, production change or charging is authorized by this FRD. Existing-server access needs separate explicit approval, isolation review and capacity checks; do not reuse Gods Eye infrastructure or private data. Charging or paid pilot repairs also require D-05, D-06 and D-14.

## 12. Traceability and next steps

Every module traces to a master-report section, stories, acceptance criteria and a build milestone.

| Module | MR § | Stories | Acceptance | Milestone |
| --- | --- | --- | --- | --- |
| ACC | 2, 11, 13 | US-002, 004, 012, 027 | AC-ACC-\* | M1 Tenant/auth |
| AST | 4.3, 5 | US-001, 024 | AC-AST-\* | M1 |
| BRD | 3 | US-003, 009 | AC-BRD-\* | M2 Boards |
| MON / ALR | 6 | US-001, 007 | AC-MON-\* | M3 Public monitoring |
| KB | 7 | US-007 | AC-KB-\* | M3 |
| INT | 4.2, 12 | US-005, 006, 012, 025 | AC-INT-\* | M4 Read-only integrations |
| DX | 8, 25 | US-008, US-028 | AC-DX-* | M5 Diagnostics + AI |
| SVC | 9, 16 | US-009, 010, 011 | AC-SVC-\* | M6 Support and payments |
| FIX | 9.4, 25 | US-016, US-026, US-029 | AC-FIX-* | M7 Controlled repairs + pilot |

**Next steps:**

- [x] D-04 and D-07 product policy selected; [ ] finalize D-01/D-02 after development/tests; [ ] resolve other open founder decisions
- [ ] Request one reseller test account each from Larihost, Peak99, SKClouds
- [ ] Draft FDD: wireframes for SCR-03, SCR-04, SCR-07, SCR-11, SCR-12
- [ ] Draft threat model and provider capability test plan
- [ ] Scaffold repo (Laravel API + React/TS + PostgreSQL) locally — no deployment

## 13. Revision register

v0.2 closes 14 specification gaps from v0.1 and 6 internal conflicts found in the v0.2 draft. "Gap" means specification completeness, not code status.

| Gap | Severity | v0.1 issue | v0.2 resolution |
| --- | --- | --- | --- |
| GAP-01 | Critical | Observation, ownership, account linking and change authority conflated | Distinct claim and authorization states (§14) |
| GAP-02 | Critical | Read-only cPanel wording confused with read-only credentials | Allowlisted calls; threat-test effective scopes (§17) |
| GAP-03 | Critical | Conflicting partner-payment approaches | Merchant-of-record decision and payment invariants (§19) |
| GAP-04 | High | Board health lacks unknown/stale aggregation | Deterministic status reduction (§15) |
| GAP-05 | High | Ticket workflow not a state machine | Transition gates and concurrency (§18) |
| GAP-06 | High | "Confirmed cause" can overstate evidence | Observation vs hypothesis vs cause (§18) |
| GAP-07 | High | No resource-linking and delegation model | Typed links with proof lineage (§14) |
| GAP-08 | High | Too few acceptance criteria per P0 feature | Added scenario catalogue (§21) |
| GAP-09 | High | Privacy, retention, offboarding thin | Data-rights NFRs (§20) |
| GAP-10 | High | Quotes lack scope and aftercare rules | Catalogue boundaries, reapproval, refunds (§19) |
| GAP-11 | Medium | No design for outages and job backlog | Degraded states and resilience (§17, §20) |
| GAP-12 | Medium | Phases vs milestones inconsistent | Single M0–M7 plan (§22) |
| GAP-13 | Medium | Platform JIT access undefined | Approval, expiry, scope (§16) |
| GAP-14 | Medium | Security/backup badges can overpromise | Coverage and restore semantics (§15) |
| FIX-A | High | Investigating → Ready for work skipped approval | Every change, including ₹0, passes Quote pending with recorded approval (§18.2) |
| FIX-B | High | Agency owner could pay for client vs "actual payer" rule | Client pays unless contract delegates (§6, §16) |
| FIX-C | Medium | AST-001 said normalise www/apex | Kept distinct (§3, §14.2) |
| FIX-D | Medium | SCR-08 ignored delegated proof | SCR-08 aligned with D-07 (§5) |
| FIX-E | Low | Work-lane names inconsistent | One 9-lane set used everywhere (§15.1) |
| FIX-F | Low | "Implementation-ready" title vs proposed status | Title neutral; status stated in §1 |

## 14. Domain identity, site claims and authorization

A monitor, a domain, a registered domain, a DNS zone and a hosting account are separate resources joined by typed links; none implies another (REC).

### 14.1 States

| Object | States | Rule |
| --- | --- | --- |
| Monitor | draft → pending\_validation → active / rejected / suspended | Public monitoring may run without proof, under anti-abuse limits and consent attestation |
| Claim | unverified → challenge\_issued → verified / expired / revoked | Binds workspace + domain + method + time + TTL; re-verify stale proof before sensitive operations |
| Delegation | requested → granted → revoked / expired | Grantor, grantee, exact resource IDs, permissions, expiry, audit trail |

A private diagnosis or repair is allowed only when **all** pass: authenticated actor; workspace role; assigned client/site; ownership or delegation; connector scope; operation allowlist; current approval; payment/coverage gate where applicable.

### 14.2 Adding and linking a website

1. Accept a URL; show the normalised display form but keep the exact hostname and path. Never collapse www and apex.
2. Reject bad schemes, embedded credentials, control characters, excessive length and private/loopback/link-local targets; revalidate at every redirect and connection.
3. Create the monitor in pending\_validation and show Checking…, Not checked or Unknown honestly.
4. Offer DNS TXT / HTML challenge or exact-asset delegation; private linking is limited to the proven scope. A connector alone is insufficient; hosting delegation never establishes registration ownership (D-07 approved).
5. Preview candidate account/zone/domain links with provider identity; ambiguity shows Needs review — never a silent attach.
6. Deleting a site stops probes, revokes its delegations and applies retention; other workspaces' copies are untouched.

### 14.3 Minimum data model

| Entity | Minimum fields / invariants |
| --- | --- |
| users, workspaces, memberships | Unique workspace + user; roles scoped per workspace |
| clients, client\_assignments | Client belongs to one workspace; assignees authorised separately |
| websites, monitor\_targets | Unique workspace + exact target; many probes per site |
| asset\_links, external\_assets | Typed link, provider, remote ID, owner/client, proof, last reconciliation |
| ownership\_claims, delegations | Status, timestamps, expiry, actor, exact resources |
| connectors, connector\_scopes | Secret reference; declared vs verified capabilities |
| check\_runs, observations | Run ID, region, source, result code, start/end, freshness |
| incidents, incident\_events | Dedup key, lifecycle, severity, evidence, acknowledger |
| diagnostic\_runs, findings | Immutable snapshot, rule version, evidence refs, classification |
| tickets, ticket\_events | State, optimistic-lock version, assignments |
| service\_offers, quotes, approvals | Immutable snapshot, tax, action fingerprint, expiry |
| orders, payment\_events, invoices | Provider IDs, verified signatures, idempotency keys |
| fix\_attempts, verification\_runs | Preconditions, operator, rollback refs, resulting evidence |
| audit\_events | Tenant, actor, resource, action, outcome, time, trace ID; tamper-evident |

Use opaque IDs, composite tenant constraints and row-level security. Workers and imports follow the same tenant checks as API calls.

## 15. Dashboard and health aggregation

Health is computed from fresh observations only; missing data is Unknown, never Healthy (REC).

### 15.1 Navigation and boards

- **Solo nav:** Overview, Websites, Issues, Connections, Billing, Settings.
- **Agency nav:** Overview, Clients, Websites, Work Queue, Connections, Team, Billing, Settings. Hidden by permission and rejected by the API.
- **Health lanes (client and website boards):** Critical, Warning, Healthy, Needs setup, Unknown. Not draggable.
- **Work lanes (single canonical set):** New, Investigating, Waiting for customer, Quote pending, Ready for work, In progress, Verifying, Resolved, Closed. A drag is a request for a server-validated transition (§18.2).
- Cards show status text + icon, domain, last verified check and the top actionable issue first; each reading shows timestamp, source and whether it is public, connector-derived or user-entered.
- One-site workspaces show an overview card and timeline, not empty lanes. Search never reveals names the user cannot access.

### 15.2 Status rules

Each check has two axes: severity (ok, warning, critical, unknown, not\_applicable) and freshness (fresh, stale, never\_checked). "Not connected" is coverage, not severity.

| Situation | Output |
| --- | --- |
| Fresh corroborated critical | Critical, with component and timestamp |
| No critical, any fresh warning | Warning |
| All required probes fresh and OK | Healthy — for monitored coverage only |
| No valid observations or required readings stale | Unknown / Awaiting checks |
| Setup incomplete, no validated site | Needs setup |
| No backup integration | Backup: Not connected |
| Backup job reported, no restore proof | Backup job reported complete; restore not verified |
| Only TLS/header checks run | Basic web checks passed — never "Secure" or "Malware-free" |

**Client roll-up:** any fresh critical → Critical; else any fresh warning → Warning; else any stale/unknown → Unknown; only all in-scope sites fresh and OK → Healthy. Show monitored-site count and coverage beside it.

### 15.3 Screen states

| Screens | Required behaviour beyond §5 |
| --- | --- |
| SCR-01, 08 | Fast URL entry; optional connection; verification help and retry |
| SCR-02, 03, 04 | Skeleton, zero-sites, no-results, connector-offline banner, empty lanes collapse on mobile |
| SCR-05, 13 | Allowed transitions only, activity feed, quoted vs unquoted boundary, resolution proof |
| SCR-06, 07 | Chronological evidence, sticky status bar, help per issue, component Unknown states |
| SCR-09, 10 | Effective privileges, last sync, revoke, import dry run, partial-import feedback |
| SCR-11 | Observations, hypotheses, missing access, confidence per finding, redacted share |
| SCR-12, 15 | Tax breakdown, merchant identity, expiry, pending-payment and duplicate-callback recovery |
| SCR-14, 17, 18 | Active sessions, audit trail, JIT request/expiry/denial states |

Accessibility: keyboard paths, live regions for async checks, focus-trapped dialogs, and a non-drag way to move tickets.

## 16. Permissions and administration hardening

Every API route and background job evaluates (actor, workspace, resource, action, scope, consent); one passing check never overrides a failing one (REC).

- **Policies:** sites.read\_public, sites.read\_private, sites.create, claims.verify, connector.create, connector.rotate, connector.revoke, diagnostics.run, tickets.create, tickets.assign, tickets.transition, quotes.issue, quotes.approve, payments.initiate, fixes.perform\_manual, audit.read, admin.jit\_request.
- **Workspace switch:** no cached authorization or responses carry across tenants.
- **Step-up MFA:** fresh MFA for connector create/rotate/revoke, payout changes and high-impact approvals; approval links are single-use.
- **Payer rule:** quotes.approve and payments.initiate for client work belong to the client owner or client billing contact; an agency owner holds them only where the agency–client contract delegates payment authority. Technicians never hold them.
- **Platform JIT:** ticket or emergency reason, authorised role, scope, duration, second approver for sensitive content, visible audit event, auto-expiry. No standing impersonation; support sees masked metadata first.
- **Provider isolation:** reseller connectors call only endpoints backed by proven reseller-owned account IDs; filtering is server-side. Unclear scope disables imports with "Integration scope could not be verified".

## 17. Monitoring jobs, connectors and resilience

A failed check is an observation, not a verdict; probe faults must never mark a customer site down (REC).

### 17.1 Monitoring contract

- Jittered scheduling; record scheduled\_at, started\_at, finished\_at, worker version, region and result code.
- Per HTTP target record resolution, IP class, status, redirect chain, TLS and timing. Never send cookies or connector tokens with public probes.
- Incident opens after 3 failed **cycles** (retries inside one cycle count once); auto-resolve after 2 independent successful cycles.
- Distinguish target unavailable, probe infrastructure error, probe blocked, DNS inconclusive and connector offline.
- Check interval is not a detection SLA; label expected alert latency. One region cannot assert global availability (multi-region is P1).

### 17.2 SSRF and egress

HTTP(S) only; block localhost, RFC1918, link-local, metadata, IPv6 local/reserved and odd literal encodings. Validate the resolved address at the actual connection and at each redirect. Enforce port, byte, time, redirect, decompression and concurrency budgets plus an egress firewall; log denials safely.

### 17.3 Adapter contract

Each adapter reports provider, credential\_type, declared\_capabilities, verified\_capabilities, allowed\_operations, resource\_scope, last\_success, error\_class, rate\_limit, secret\_version. Effective capability = app allowlist ∩ proven provider scope ∩ tenant permission; capability\_unknown is never granted.

| Connector | Test before enabling | Required negative case |
| --- | --- | --- |
| WHM reseller | List only reseller-owned accounts; read domain/SSL metadata | Cross-reseller account ID, root-like token, pagination leakage |
| cPanel | Specific allowlisted read UAPI calls per panel version | Write-capable token still cannot dispatch writes; user A cannot see account B |
| Cloudflare | Zone list and DNS read for selected zones | Wrong zone, revoked token, 403 or rate limit never reads as DNS healthy |
| Registrar (P1) | Ownership, expiry, renewal status | API disabled, IP-bound key, non-owner account |
| Backup metadata | Last result only where an endpoint exists | No endpoint, old snapshot or no restore proof → Unknown |

Outages degrade only the affected component: capped exponential backoff, circuit breakers, idempotent reconciliation, partial-sync reporting. Revoked secrets cannot be revived by queued jobs.

## 18. Diagnosis and ticket state machines

Confidence attaches to each finding, not the website; a confirmed observation never implies a confirmed cause (REC).

### 18.1 Diagnosis model

Run states: requested → eligibility\_check → collecting → evaluating → completed / partial / failed / cancelled. Each run stores actor, sites, allowed checks, connector versions/scopes, timestamps, evidence refs and rule-engine version.

| Finding field | Meaning |
| --- | --- |
| Observed | Directly measured, e.g. HTTP 503 at time T |
| Hypothesis | Candidate explanation with supporting or contradicting observations |
| Confidence | confirmed / likely / possible / unknown — per finding |
| Missing evidence | Unavailable connector or check, reason, safe next step |
| Responsibility | provider-covered / customer-side / needs quote / undetermined, with contract evidence |

A 503 without hosting access is never "confirmed server misconfiguration". AI summaries, if added later, cannot change facts, confidence or approvals.

### 18.2 Ticket transitions

Every change to a customer resource — including ₹0 provider-covered work — passes Quote pending and needs a recorded customer approval.

| Current | Allowed next | Gate |
| --- | --- | --- |
| New | Investigating, Waiting for customer | Assigned workspace/resource |
| Investigating | Waiting for customer, Quote pending, Closed (no action needed) | Evidence linked or triage reason |
| Waiting for customer | Investigating, Quote pending | Response or timeout recorded |
| Quote pending | Ready for work, Waiting for customer, Closed | Unexpired recorded approval by the authorised payer (₹0 included); verified payment when chargeable; decline/expiry never becomes approval |
| Ready for work | In progress | Authorised technician, exact-scope checklist, rollback suitability |
| In progress | Verifying, Investigating | Before/after evidence and notes |
| Verifying | Resolved, Investigating | Verification complete; failure returns to Investigating |
| Resolved | Closed, Investigating | Customer acceptance or recurrence |
| Closed | Investigating (new linked attempt) | Reopen reason; prior invoice/fix history immutable |

Transitions are server-side, versioned (optimistic lock) and audited with actor, time, reason and event ID. Any change to quote, resource, operation or provider scope voids consent.

### 18.3 Manual technician work in MVP

Upfixy issues tasks for technicians to do in their own already-authorised tools; a ticket grants no production access. Each attempt records resource, approval reference, intended operation, preconditions, impact, backup status, time, result and post-check. Irreversible actions need explicit customer acceptance or a custom quote. No arbitrary commands, auto-SSH or bulk cross-account repair.

## 19. Service catalogue, payments and outcomes

₹100 / ₹200 / ₹300 are founder-proposed starting prices (FD), each bounded by scope, handling window, exclusions and a route to custom quote (REC).

### 19.1 Standard services (scope needs D-08)

| Service | Indicative scope | Exclusions / escalation |
| --- | --- | --- |
| ₹0 provider-covered | Confirmed provider responsibility under an existing contract | Unrelated client code, third-party registrar; coverage must be proven |
| ₹100 domain/DNS | One bounded record correction or basic advisory in an approved zone | Transfers, ownership disputes, DNS migration, propagation guarantees |
| ₹200 cPanel | One bounded account setting or SSL/AutoSSL correction | Malware cleanup, mail recovery, database repair, server-wide changes |
| ₹300 code | One small reproducible issue, agreed files, reviewed with rollback | Open-ended bugs, redesign, dependency upgrades, incident response |
| Custom quote | High-risk, multi-resource, uncertain or long tasks | Written scope and approval |

**Quote invariants:** merchant legal identity, customer, resource and action fingerprints, coverage reason, tax breakdown, price, expiry, terms, impact and verification plan. Approval is immutable; any change voids it. No partner money is collected until the merchant-of-record model (D-06, D-18) is legally and technically verified.

### 19.2 Payment lifecycle

quote\_draft → issued → accepted / declined / expired → payment\_pending → paid\_verified / failed / refunded / partially\_refunded. Payment and service states are separate; an accepted ₹0 quote skips payment but not approval.

- Browser success redirects are never proof of payment.
- Verify signature, event ID, amount, currency, merchant and quote before fulfilment; use an idempotent webhook inbox plus server reconciliation.
- Duplicate, delayed, out-of-order or disputed events never create duplicate tickets or charges.
- GST place of supply, invoicing entity, fees and refunds agreed with a CA before real charges.

### 19.3 Fix outcome

awaiting\_authorization → ready → executing\_manually → verifying → resolved / unresolved / escalated. A failed post-check means unresolved, which triggers the D-13 refund review — not an automatic refund. A technician's statement or an API success alone never marks resolved.

## 20. Security, privacy and operations NFRs

These extend §9; each is testable before pilot (REC).

| ID | Requirement |
| --- | --- |
| NFR-12 | Authorization: every privileged route and job runs the same tenant + resource policy; automated IDOR tests across all roles |
| NFR-13 | Secrets: encrypted with separated keys; never in logs, analytics, error reports or exports; revocation invalidates queued work |
| NFR-14 | Data rights: workspace export and closure; retention, legal hold and recovery window defined before launch; no cross-tenant cascade |
| NFR-15 | Freshness: every check shows source, timestamp and freshness; missing provider data never shows as success |
| NFR-16 | Jobs: idempotent, bounded retry/backoff, dead-letter queue, failed-job counts and alerts |
| NFR-17 | Backups: encrypted offsite; isolated restore drill before pilot; RPO/RTO set after load tests |
| NFR-18 | Money: amount/merchant/tax validated server-side; duplicate and out-of-order webhook tests; reconciliation and refund audit |
| NFR-19 | Scope proof: each adapter tested against allowed and forbidden cross-account assets before real accounts |
| NFR-20 | Separation: dedicated repo, server, secrets, DB, monitoring, backups, staging and CI; no Gods Eye reuse |
| NFR-21 | Observability: request, job and connector IDs with tenant-safe tracing; sensitive URLs and bodies redacted |
| NFR-22 | Performance: seeded 200-card board over simulated 4G; report median and p95; release fails if target missed |
| NFR-23 | Access: WCAG 2.1 AA on key flows; every critical action works without drag; readable at 320 px |

**Threat-model focus:** SSRF/DNS rebinding, over-privileged tokens, IDOR, reseller leakage, invite takeover, stale delegations, webhook replay, forged approvals, data export, secret logging, admin misuse, queue races, manipulated evidence, irreversible manual repairs.

## 21. Additional acceptance criteria

The v0.2 expansion originally added 37 criteria (63 through §21); §24 and §25 add further acceptance cases. Fixtures must include two tenants, two resellers on one simulated shared WHM, distinct Cloudflare zones, one solo owner and one client with two sites.

| AC ID | Given / When / Then | Test |
| --- | --- | --- |
| AC-ACC-001.2 | Given an unverified email, then sensitive actions are blocked and a resend flow exists | E2E |
| AC-ACC-004.1 | Given membership in A and B, when switching A→B, then A records and cached search results disappear and all requests use B authorization | API/E2E |
| AC-ACC-006.2 | Given a technician assigned to Client A, when requesting Client B tickets by guessed ID, then no B data or metadata returns | Security |
| AC-AST-001.3 | Given example.com and www.example.com serve different content, when both are added, then they stay distinct targets | Unit/E2E |
| AC-AST-002.2 | Given a revoked claim, when private Analyze is requested, then it is denied with a re-verify instruction | API |
| AC-AST-002.3 | Given verified delegated hosting authorization for site S only, when Analyze runs on site T in the same account, then private checks on T are denied | Security |
| AC-AST-004.1 | Given registrar NS and Cloudflare authoritative DNS, then both provider relationships display separately | Fixture |
| AC-BRD-002.1 | Given one website, when opening the Website board on mobile, then a single-site overview shows with no empty lanes | UI |
| AC-BRD-003.2 | Given a ticket in Investigating, when moved to Ready for work, then the move is rejected; only Quote pending can lead there | API |
| AC-BRD-004.1 | Given a restricted client user, when searching, filtering or exporting, then no other client's domain, tag or count leaks | Security |
| AC-BRD-006.1 | Given keyboard-only use, then every valid ticket transition is possible without drag | Accessibility |
| AC-MON-001.3 | Given a 302 to a private endpoint, then the redirect is blocked before connecting and logged without body leakage | Security |
| AC-MON-002.1 | Given an expired certificate, then SSL is critical and the certificate and timestamp are recorded | Unit |
| AC-MON-003.1 | Given one resolver times out and another answers, then the result records disagreement, not a definitive cause | Integration |
| AC-MON-005.3 | Given 3 retries in one cycle, then they count as one cycle for debounce | Unit |
| AC-MON-008.1 | Given an active incident and 2 verified recovery cycles, then it resolves with last-failure and recovery timestamps | Fixture |
| AC-ALR-001.1 | Given an incident within preferences, then one deduped email/in-app alert is sent and no other client's address is included | Integration |
| AC-KB-002.1 | Given ≥ 40 issue codes, then each has meaning, causes, non-technical checks, next step and support message | Content QA |
| AC-KB-003.1 | Given a 2-hour-old stored snapshot, when reopened, then the UI shows its timestamp and does not imply a live probe | E2E |
| AC-INT-001.2 | Given queued private work and a token revocation, when the job runs, then it checks the revocation version and refuses | Security |
| AC-INT-003.1 | Given a cPanel token that allows writes, then the connector exposes only approved read calls and tampered input cannot invoke a write | Security |
| AC-INT-004.1 | Given a token limited to zone Z1, when Z2 is selected, then it is rejected without revealing Z2 metadata | Integration |
| AC-INT-006.2 | Given an ambiguous domain-to-client mapping, then the asset is flagged Needs review, not linked | E2E |
| AC-DX-002.2 | Given HTTP 503 with no private telemetry, then "503 observed" is confirmed and "server fault" is not | Fixture |
| AC-DX-003.1 | Given a report shared with a client, then other clients' notes, secrets, account IDs and raw private details are redacted | Security |
| AC-DX-004.1 | Given an issue claimed as provider-covered, then ₹0 applies only if a linked contract and responsibility rules establish coverage | Unit |
| AC-SVC-001.1 | Given two technicians update the same ticket version, then one succeeds, the other gets a conflict, and both intents are audited | Concurrency |
| AC-SVC-002.1 | Given a ₹300 job that exceeds scope, then the technician cannot raise the price or continue without a new custom quote | E2E |
| AC-SVC-003.2 | Given an expired approval, then fulfilment is blocked and new authorization is required | API |
| AC-SVC-003.3 | Given a ₹0 provider-covered fix, when no customer approval is recorded, then the ticket cannot leave Quote pending | API |
| AC-SVC-003.4 | Given an agency owner without contract-delegated payment authority, when approving a client's quote, then it is rejected | Security |
| AC-SVC-004.3 | Given a signed paid event with wrong amount, currency or merchant, then the order stays unfulfilled and an alert fires | Security |
| AC-SVC-005.1 | Given a verified paid order, then the invoice shows the actual merchant, taxes and quote consistently | Integration |
| AC-FIX-001.1 | Given no approval or a wrong resource ID, when staff record a paid fix, then it is blocked and audited | Security |
| AC-FIX-002.2 | Given a fix marked done but recheck fails, then health follows observed checks, the ticket returns to Investigating and the client sees unresolved | E2E |
| AC-ADMIN-001.1 | Given platform support without JIT grant, when opening a private report, then access is denied; a grant expires and records reason and scope | Security |
| AC-NFR-020.1 | Given CI, staging and prod config, then no credential, DB endpoint, queue or backup destination points to Gods Eye | Deployment audit |

**Exit threshold:** every P0 story passes functional, authorization and failure-mode tests with evidence; zero known critical/high cross-tenant, SSRF, credential or payment-integrity defects; provider capabilities verified on real pilot test accounts, not just fixtures.

## 22. Milestones and gates

One delivery sequence, M0–M7; it replaces the master report's phase labels. No milestone is complete.

| Stage | Scope | Exit gate | Deliverables |
| --- | --- | --- | --- |
| M0 Discovery lock | Founder product/security decisions; temporary development setup | D-04/D-07 approved; D-01/D-02 documented as deferred; staging isolation and access authorization checked before any use | Decision log, risk log, baseline FRD |
| M1 Tenant foundation | Auth, MFA, workspaces, clients, site add/claim | IDOR and claim-boundary tests pass | Schema/API, auth tests, audit baseline |
| M2 Boards and UX | Client/site/work boards and lists | Health vs work separated; mobile and keyboard verified | FDD screens, status reducer, UX QA |
| M3 Public monitoring | HTTP/TLS/DNS jobs, alerts, help library | SSRF, debounce and freshness tests pass | Probe worker, incidents, issue catalogue |
| M4 Read-only adapters | WHM reseller, cPanel, Cloudflare, import | Negative-scope tests pass on pilot test accounts | Capability matrix, connector audit |
| M5 Diagnostics + AI | Deterministic evidence engine, AI explanation/hypotheses, source attribution, redaction, cost controls | False-cause, evidence-injection, missing-evidence, hallucination and API-failure tests pass | Diagnostic FSD, model gateway and prompt/version registry |
| M6 Commercial support | Tickets, quotes, payments, invoices | Approval, payer, merchant, webhook and reconciliation tests pass; D-05/06/14 signed | Support SOP, gateway test evidence |
| M7 Controlled repair pilot | Technician tasks + one narrowly scoped reversible Cloudflare DNS fix; approved execution, rollback, post-fix verification | Operation allowlist, scoped approvals, replay/rollback/negative-scope tests, manual kill switch, pilot UAT and restore drill pass | Execution FSD, operation policy, UAT evidence, runbooks |

Automated Cloudflare edits (FIX-003–005, P0) require a dedicated security review and verified isolated test execution before M7 exit; no general production access is authorized.

## 23. Additional founder decisions

Nine refinements that prevent rework; D-04/D-07 approved, D-01/D-02 deferred, other decisions remain pending.

| ID | Decision | Recommended choice | Gate |
| --- | --- | --- | --- |
| D-15 | Does a public URL alone allow monitoring? | Yes, throttled non-invasive checks only | M1 |
| D-16 | Does provider-console access count as domain control? | Only as delegated capability for exact assets | M1/M4 |
| D-17 | Mixed unknown/healthy roll-up | Unknown wins over Healthy when no warning/critical; show coverage | M2 |
| D-18 | Fix fulfilment and merchant model | Partner fulfils and bills its own cases until platform payments are verified | M6 |
| D-19 | Which repairs are fixed-price? | Small confirmed allowlist; quote the rest | M6 |
| D-20 | Support without connected credentials? | Yes: evidence summary and ticket; no private findings or changes | M5/M6 |
| D-21 | Can a provider see a customer's other websites? | Only by explicit assignment or delegation | M1 |
| D-22 | Pilot capacity and alert promises | Targets only until staging load tests | M3 |
| D-23 | Verification and dispute window | Mandatory evidence-based verification; set window and refund SLA before paid launch | M6 |

**Approval order:** D-04/D-07 policy approved for design; D-01/02 must be finalized before new domain/server procurement or production cutover; connector tests require separately authorized synthetic/test environments; D-05/06/08/13/14/18/19/23 before any charge; D-03 when the registrar connector is prioritised.

**Next artifacts:**

- [ ] FDD — mobile-first flows for SCR-01 to SCR-18, all empty/error/partial states
- [ ] FSD — endpoint contracts, status reducer, transitions, diagnostic rules
- [ ] TDD — schema and RLS, queues, adapter interfaces, secrets, observability
- [ ] Integration matrix — sanitised responses and forbidden-account tests per provider
- [ ] QA/UAT tracker — every AC with not run / pass / fail / blocked and evidence
- [x] Record D-01/D-02 deferrals and D-04/D-07 founder approvals in §24; [ ] ADRs and remaining decisions


## 24. Founder decision log — 16 October 2026 (FD)

| ID | Status | Founder instruction | Operational boundary |
| --- | --- | --- | --- |
| D-01 | **Deferred / open** | Use `agent25.in` as temporary development/test domain. Buy final Upfixy domain after development and testing. | No purchase now; no assertion of ownership/control of `agent25.in` without proof and authorization; trademark/availability recheck before purchase. |
| D-02 | **Deferred / open** | Use an existing server during development; purchase a new server after development. | No server selected or accessed through this decision; perform capacity, tenant, backup and isolation assessment first. No reliance on Gods Eye server or credentials; separate staging/prod and separate DB/secrets/repo. |
| D-04 | **Approved** | WHM reseller-scoped tokens only, no root tokens. | Reject root-like reach even if token claims reseller role. Test negative cross-reseller requests and pagination. No existing production connection authorized. |
| D-07 | **Approved** | Public monitoring permitted without proof; DNS TXT / HTML control proof or scope-bound, revocable and expiring delegation required for private functionality. | Private diagnostic authorization limited to exact resources; no auto-link on domain matches; separate approval/payment/impact controls required before changes. |

### 24.1 Acceptance criteria added and strengthened

| AC ID | Given / When / Then | Test |
| --- | --- | --- |
| AC-D01-001 | Given development is configured for `agent25.in`, when generated links are shown, then they use the configurable development base URL; no final Upfixy domain purchase or permanent redirect is assumed. | Config/E2E |
| AC-D01-002 | Given a future switch to the final branded domain, when configuration is changed in staging, then authentication callbacks, TLS, cookies, email links and canonical URLs are revalidated before production cutover. | Release checklist/E2E |
| AC-D02-001 | Given a proposed existing development server, when setup is requested, then a documented isolation, capacity, ownership/authorization and backup check must pass before any service is installed or deployment occurs. | Security/release gate |
| AC-D02-002 | Given development is hosted temporarily, when a future server migration is tested, then an isolated backup/restore drill, env-specific secrets, monitoring baseline and rollback plan pass before cutover. | Migration rehearsal |
| AC-INT-002.3 | Given WHM token scope cannot be proven reseller-only, when connector enablement is attempted, then it is denied; the token and any returned cross-reseller metadata are not persisted or logged. | Integration/security |
| AC-INT-002.4 | Given a provider presents a root token or root-backed broker, when configuring an MVP connector, then it is rejected regardless of a UI role label. | Security |
| AC-AST-002.4 | Given an unverified public website added by URL, when monitoring starts, then only allowlisted non-invasive HTTP/TLS/DNS probes execute; private data, connector linkage and repairs remain inaccessible. | E2E/security |
| AC-AST-002.5 | Given a valid DNS TXT or HTML challenge, when verified, then the claim binds exact hostname/zone as appropriate, workspace, nonce, method and expiry; stale or reused proof fails privileged authorization. | Integration/security |
| AC-AST-002.6 | Given exact-account delegated authorization, when a user requests another account/site or the delegation expires/is revoked, then private operations are denied and prior private readings become appropriately stale. | API/security |
| AC-AST-002.7 | Given verified control or delegation but no specific fix approval, when a repair is requested, then execution remains blocked, including for a ₹0 provider-covered repair. | Security/E2E |

**Acceptance-test status:** specified, **not executed**. Earlier AC-INT-002.1/.2, AC-AST-002.2/.3 and AC-SVC-003.3 remain in force.


## 25. Founder scope amendment — AI-assisted diagnostics and narrowly scoped automated repairs (18 October 2026)

**Decision classification:** FD (approved product scope). **Engineering controls:** REC, to be verified in M5–M7. This supersedes previous P1/P2 placements for basic AI guidance and FIX-003 only. It does **not** approve actual credentials, test-account access, live changes, billings, provisioning, or production releases.

### 25.1 Product promise and boundaries

For an eligible site: **Detect → Gather actual evidence → Explain/diagnose → Propose a narrowly scoped solution → Preview impact → Obtain customer approval and verified payment or provider coverage → Execute only a supported allowlisted action → Verify → Resolve or escalate**.

Separate:
1. **Observation** — measured HTTP/DNS/TLS/connector facts with timestamps and provenance.
2. **Hypothesis** — inferred possible causes, with corroborating and contradictory evidence.
3. **Recommendation** — suggested checks or possible remedy, not authorization to act.
4. **Authorized execution** — a separately enforced state with fixed action fingerprint, exact resource and mandatory gates.

AI is advisory, not an independent permissions authority or source of truth. An LLM cannot mint an ownership claim, upgrade its own confidence to *Confirmed*, approve payments, select a more privileged connector, or execute arbitrary API calls.

### 25.2 New / updated stories and screens

| ID | User story | Feature IDs | P |
| --- | --- | --- | --- |
| US-028 | As an owner or assigned technician, I want evidence-cited AI explanations and candidate remedies so I can understand what is happening without fabricated conclusions. | DX-005, DX-006 | P0 |
| US-029 | As a verified and authorized customer, I want a single low-risk DNS change to be previewed, approved, safely applied and verified, with rollback or escalation if unsuccessful. | FIX-003–005 | P0 |

**SCR-07 Website detail:** distinguish *Observed problem*, *Possible cause* and *Suggested fix*; show evidence timestamp and connector coverage; display **AI-assisted analysis** only when an eligible evidence snapshot exists.

**SCR-11 Diagnostic report:** list deterministic observations, AI hypotheses, confidence, evidence IDs/source/time, missing evidence, model/prompt versions (staff/audit view), suggested manual steps, and a redacted customer view. Show “AI unavailable — measured findings only” on model failure.

**SCR-12 Quote / approval:** show exact zone and DNS record, before/after values, TTL, affected hostnames, possible downtime/propagation risks, rollback preconditions, price/tax, expiration, named merchant and explicit user confirmation, including ₹0 jobs. Change any proposed field → void approval.

**SCR-13 Ticket detail:** show *Proposed → Awaiting approval → Approved/paid → Preflight → Executing → Verifying → Resolved / Rolled back / Needs technician* with human-readable history. Ticket status lanes in §18 remain canonical; these are **attempt sub-states**, not replacement Kanban lanes.

**SCR-09/10 Integrations:** separate read-only DNS token from later short-lived/scoped DNS-edit capability. Show real effective permissions; a read-only connection alone does not authorize edits.

### 25.3 AI analysis contract (DX-005, DX-006)

- **Input:** a tenant/resource-scoped, immutable diagnostic snapshot assembled by Upfixy; sanitized observations, recognized error codes, timestamps, missing provider access and explicit consent. Never send API tokens, raw credentials, unnecessary customer content, personal data or cross-client records to the AI provider.
- **Output:** validated structured schema with `finding_id`, `evidence_ids`, `observed_fact`, `hypotheses[]`, `supporting_evidence_ids`, `contradicting_evidence_ids`, `confidence` (`confirmed/likely/possible/unknown`), `recommended_actions[]`, `unknowns[]`, and `human_review_required`. Reject invalid or uncited assertions.
- **Verification:** Upfixy checks cited evidence exists **in the same authorized snapshot**; AI cannot label a cause *Confirmed* unless deterministic rule criteria independently support it. Never transform a measured 503 into an asserted PHP/hosting cause without telemetry.
- **Isolation:** per-tenant request scoping, access policy, provider data-minimization, input/output redaction, retention policy, model vendor data-processing review, rate limits, spending caps and auditable model/prompt/rule versions.
- **Prompt-injection defense:** external website text and fetched logs are untrusted evidence, not instructions. They cannot specify tools, override privileges or alter approval conditions.
- **Resilience:** queue with idempotent analysis requests, bounded retries, timeout and cost ceiling; on model outage, deterministic findings and support routing continue; no repair gate is bypassed.

### 25.4 Initial automated repair allowlist (FIX-003–005)

**P0 autonomous-operation scope is only one low-risk, single-record Cloudflare DNS change per explicitly approved attempt**. Operation candidates: update an existing, explicitly selected `A` or `AAAA` record to a validated public address, or restore the preceding exact value. These are **candidate operations pending synthetic integration testing and independent safety review**, not blanket approval to edit production zones.

**Explicitly excluded from automated P0:** `MX`, `NS`, `SOA`, `CAA`, TXT verification records, wildcard records, proxied/CDN settings, DNSSEC, zone or domain transfers, deletions, bulk changes, cPanel/WHM writes, SSH commands, restarts, filesystem/code writes, database repairs, security incidents, and changes affecting shared accounts. A new operation requires a separate versioned approval and security test pack.

**Preflight and execution gate:**
1. Verify authenticated actor and recent step-up MFA; correct tenant, client/site assignment, current exact-domain control/delegation (D-07), selected zone/record and provider-verified edit capability.
2. Demand diagnosis/evidence snapshot and deterministic eligibility rule; do not allow AI to authorize the operation.
3. Compute immutable operation fingerprint from zone ID, record ID, record type, existing value, proposed value, TTL/proxy state, actor, workspace, verification plan and cost/coverage terms. Show it to the payer; acquire an explicit unexpired single-use customer approval **even for ₹0** (FIX-A).
4. Require verified charge or proven provider-side coverage. Check change window, conflict lock, circuit breaker, safety policy, resource-specific allowlist, threat checks and global/tenant kill switches.
5. Read fresh provider state immediately before writing. If it differs from the approved preview, **stop and re-quote/re-approve**.
6. Persist encrypted/redacted before-state (and a tested rollback plan) before the operation; use exactly one constrained provider API action, never shell or arbitrary commands; register idempotency key, attempts and provider request/response references.
7. Re-read provider state; run authoritative/public DNS verification after realistic propagation windows. Treat pending propagation, provider timeout, conflicting changes or partial visibility as **indeterminate**, not success.
8. If safely reversible and failure is attributable to this change, attempt the separately approved rollback workflow only after confirming the record still matches the tool's written state. If concurrent edits occurred, **stop and escalate** rather than overwrite them.
9. Keep the ticket unresolved until post-fix checks prove the target issue resolved. Record attempt evidence, costs, timestamps, notifications and escalation route.

**Execution model:** provider capability is restricted to the exact authorized zone; service holds no WHM root tokens (D-04), no free-form production shell or unrestricted model-to-provider API. The AI can propose an operation ID from a published allowlist; only a separate deterministic executor may invoke it.

### 25.5 Security controls / threat model additions

| Control | Requirement / threat addressed |
| --- | --- |
| AI-SEC-01 | Prompt injection through HTTP responses/logs/DNS text; treat source content as data, never instructions |
| AI-SEC-02 | Cross-tenant data leak; resource and evidence IDs verified before sending to external API |
| AI-SEC-03 | Secret/PII exfiltration; redact/minimize, encrypt, vendor retention review |
| AI-SEC-04 | Model hallucination; structured schema, evidence attribution and deterministic confidence gate |
| AI-SEC-05 | Tool confusion/mass assignment; strict operation allowlist and canonical server-generated parameters |
| AI-SEC-06 | Unauthorized write; current proof/delegation + resource ACL + MFA + exact single-use approval |
| AI-SEC-07 | Replay/concurrency; idempotency, resource locks, compare-before-write and failed/replayed event rejection |
| AI-SEC-08 | Bad rollback; encrypted before-image, compare-before-rollback and safe escalation |
| AI-SEC-09 | API availability and spending; budgets, quotas, bounded retries, circuit breakers and deterministic fallback |
| AI-SEC-10 | Audit integrity; independent event trail links diagnosis → evidence → authorization → execution → verification |

### 25.6 Acceptance criteria added in v0.3

| AC ID | Given / When / Then | Test type |
| --- | --- | --- |
| AC-DX-005.1 | Given HTTP 503 and no hosting telemetry, AI output may describe 503 and suggest possibilities but cannot assert an observed hosting root cause. | Contract/fixture |
| AC-DX-005.2 | Given an AI evidence ID not present in the same workspace snapshot, the response is rejected and no actionable fix is produced. | Security |
| AC-DX-005.3 | Given hostile instructions inside fetched page text or logs, analysis treats them as untrusted evidence and emits no privileged tool invocation. | Adversarial |
| AC-DX-005.4 | Given a model timeout/outage, measured findings remain available and the issue offers manual escalation, never a fabricated result. | Fault injection |
| AC-DX-005.5 | Given user-sensitive or connector data, outbound AI payload and audit logs contain no credentials, private keys, or other clients' information. | Security/redaction |
| AC-DX-006.1 | Given an invalid structured AI response, the system rejects it, records the model/prompt version and falls back safely. | Schema |
| AC-DX-006.2 | Given repeated runs, enforce per-workspace rate and spend caps and do not generate duplicate billable analyses from repeated clicks. | Load/billing |
| AC-FIX-003.1 | Given valid exact-zone approval and matching current DNS record, a synthetic Cloudflare `A` record update succeeds once, with before/after evidence and verified result. | Integration/test-zone |
| AC-FIX-003.2 | Given a DNS change in price, target, prior value, proposed value, proxy state or TTL after approval, execution is denied pending fresh authorization. | Security |
| AC-FIX-003.3 | Given a record or zone outside the authorized scope, execution is denied without leaking zone metadata. | Security |
| AC-FIX-003.4 | Given missing, expired or reused approval, or absent verified payment/coverage including for ₹0 work, execution is denied. | API/security |
| AC-FIX-003.5 | Given unsupported record classes, deletion, wildcard, DNSSEC or bulk edit, the P0 executor refuses even if the model proposes the action. | Negative/contract |
| AC-FIX-004.1 | Given a simulated provider error after mutation and a matching before-image, rollback returns the record to its previous value and records both requests. | Fault injection |
| AC-FIX-004.2 | Given a concurrent external record edit after our write, rollback does not overwrite it; ticket is escalated. | Concurrency |
| AC-FIX-004.3 | Given a provider timeout with unknown outcome, executor reconciles remote state before retry; no blind duplicate write. | Integration |
| AC-FIX-004.4 | Given a successful API response but failed external DNS/website post-check, ticket remains unresolved with customer-facing explanation. | E2E |
| AC-FIX-005.1 | Given a global or tenant kill switch, all queued/new repair writes are stopped even if previously approved; public monitoring continues. | Safety |
| AC-FIX-005.2 | Given a revoked/rotated credential or expired delegation after enqueueing, pending repair refuses to run. | Security |
| AC-FIX-005.3 | Given two simultaneous approved attempts against the same record, only one may execute and the other reconciles or requests renewed approval. | Concurrency |
| AC-FIX-005.4 | Given any completed/failed/rolled-back attempt, audit links evidence, approvals, payment/coverage, operator/service identity, provider request IDs, verification and result. | Audit |

**Release gate:** the above P0 acceptance tests must pass in an isolated owned/synthetic Cloudflare test zone and in staging. No live customer changes until the exact operation receives separate production authorization.

### 25.7 Milestone dependency adjustments

| Milestone | Addition | Exit criterion |
| --- | --- | --- |
| M1 | Preserve tenant/claim/delegation boundaries and protect trusted status writes before AI inputs or repair permissions exist. | Auth, role, IDOR, D-07 and audit gates verified |
| M2 | Show evidence/hypothesis/repair status separately in responsive UI. | Accessible empty, partial, AI unavailable, pending propagation and escalation states |
| M3 | Produce real probe evidence and provenance; prohibit executing against synthetic or stale readings. | SSRF/freshness/incident cycle tests pass |
| M4 | Add Cloudflare read-only scopes and an independently permissioned write adapter used only by the repair executor; WHM/cPanel stay read-only. | Cross-zone and read/write-scope negative tests pass |
| M5 | Add AI analysis and confidence validation after deterministic evidence capture. | AC-DX-005/006 tests pass; usable fallback without AI |
| M6 | Bind exact-action approval and verified payment/provider coverage to the immutable change plan. | Payment, payer, quote-expiry, free-work approval tests pass |
| M7 | Allowlisted Cloudflare test-zone edit, rollback, reconciliation, post-fix verification, kill switch, staged pilot. | AC-FIX-003/004/005 pass; restore drill and explicit pilot release sign-off |

### 25.8 Open engineering and commercial decisions

These do not reverse the founder's new P0 scope approval. They are engineering/commercial gates before enabling customer repair automation:
- Which OpenAI API model, deployment region/data processing terms, retention configuration, per-analysis budget and subscription quotas? **OPEN.**
- Exact permitted Cloudflare DNS operation schema and excluded high-impact zones/records, verified against test zones and API docs. **OPEN until security sign-off.**
- Backup/rollback evidence retention, propagation verification timeout, dispute window, and escalation SLA. **OPEN.**
- Who operates the executor, holds the separately scoped DNS-edit token, and approves production release for each agency? **OPEN.**
- D-01 final domain and D-02 dedicated production server remain **DEFERRED**. `agent25.in` and temporary isolated development infrastructure remain the plan only. D-04 no-root-WHM and D-07 layered proof remain **APPROVED**.

**No existing server, repository, DNS zone, connector, payment service or production system was accessed or modified in recording this amendment.**