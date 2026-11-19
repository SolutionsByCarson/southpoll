# Proposal → Requirements

Source: `SouthPoll_Membership_Classifieds_Proposal.pdf` (Ranch House Designs, Wharton TX; prepared by Jessica Barker;
issued 2026-09-04; valid through 2026-10-04). Plain text in `proposal_extracted.txt`.

## Commercial summary

| Item | Value |
|---|---|
| Client | South Poll Grass Cattle Association (SPGCA), southpoll.com |
| Vendor | Ranch House Designs (RHD), jessica@ranchhousedesigns.com |
| Total | $6,800.00 |
| Deposit | $3,400.00 (50%) on acceptance; work and discovery call start after deposit |
| Balance | $3,400.00 before migrating the plugin to the live site |
| Warranty | Up to 5 development hours post-launch for defects against the discovery requirements summary |
| Beyond warranty | $200/hr, or fixed quotes / retainer |
| Expected duration | ~4–7 weeks from discovery call to launch |
| Client contact named | "Kathy" (SPGCA office; info@southpoll.com) — see current email customization in child theme |

## Project background (what the proposal says about the current system)

- Current site: custom Pro-theme build by RHD on top of Classified Listing Pro by RadiusTheme.
- Pain points: ongoing patching to stay operational; customizations on a foreign codebase that vendor updates can break;
  clunky submission/dashboard/directory UX with member drop-off; no reporting; no efficient bulk update/export of members.

## Objectives (verbatim intent)

1. Straightforward way for producers to create and maintain their own farm page.
2. Every active member discoverable in a fast, filterable member directory.
3. Members publish cattle classifieds for as long as membership is active.
4. Automate membership sales and renewals through Stripe on a configurable term.
5. Practical tools for Kathy/SPGCA staff to manage, update, and export member records.
6. Visibility into platform usage.
7. Retire Classified Listings Pro and its maintenance burden.

## Scope: one custom WordPress plugin, seven modules

Field-level detail (farm attributes, listing fields, categories, notification triggers) is to be confirmed in discovery.

### Module 01 — Membership & Accounts

| ID | Requirement |
|---|---|
| REQ-M1-01 | Front-end member registration, login, password reset, account management. |
| REQ-M1-02 | Membership records with status (active, expiring, expired, pending) and term dates. |
| REQ-M1-03 | Configurable membership duration (default 1 year) with admin-set price. |
| REQ-M1-04 | Automatic expiration; renewal prompts as the term ends and after it lapses. |
| REQ-M1-05 | Role-based permissions separating members, site managers, administrators. |
| REQ-M1-06 | Access gating: publishing rights and directory visibility follow membership status. |

### Module 02 — Farm Pages & Member Directory

| ID | Requirement |
|---|---|
| REQ-M2-01 | Member-managed farm profile: ranch name, location, contact details, herd description, logo and photos, website and social links. |
| REQ-M2-02 | Public farm page per active member, clean URLs, SEO-friendly markup. |
| REQ-M2-03 | Searchable directory: keyword search + filtering by state/region and other agreed criteria. |
| REQ-M2-04 | Optional map or state-based browse view (confirm in discovery). |
| REQ-M2-05 | Member's active listings surfaced on their farm page. |

### Module 03 — Classified Listings

| ID | Requirement |
|---|---|
| REQ-M3-01 | Front-end listing submission and editing, restricted to members in good standing. |
| REQ-M3-02 | Cattle-specific fields and categories: bulls, heifers, cows, steers, pairs, cattle wanted. |
| REQ-M3-03 | Photo galleries with upload handling, resizing, ordering. |
| REQ-M3-04 | Search and filtering by keyword, category, location, price. |
| REQ-M3-05 | Lifecycle: draft, published, sold, removed — with member-side controls. |
| REQ-M3-06 | Admin can edit, unpublish, or remove any listing. |
| REQ-M3-07 | Listings auto-unpublish when membership lapses; restored when reinstated. |
| REQ-M3-08 | Buyer contact routed through the site, protecting member contact information. |

### Module 04 — Stripe Payments & Renewals

| ID | Requirement |
|---|---|
| REQ-M4-01 | Stripe checkout for new memberships and renewals using SPGCA's own Stripe account. |
| REQ-M4-02 | Configurable price and term length from the admin settings screen. |
| REQ-M4-03 | Automated receipts and payment confirmation emails. |
| REQ-M4-04 | Renewal prompts on a configurable schedule before and after expiration. |
| REQ-M4-05 | Payment and membership history visible on each member record. |
| REQ-M4-06 | Admin can manually record an offline payment (check/cash) and set a term. |
| REQ-M4-07 | Secure webhook handling so membership status stays in sync with Stripe. |

### Module 05 — Email Notifications (built into the plugin, not admin-edited)

| ID | Recipient | Trigger |
|---|---|---|
| REQ-M5-01 | Member | Account created / welcome |
| REQ-M5-02 | Member | Payment receipt and membership confirmation |
| REQ-M5-03 | Member | Renewal reminder ahead of expiration |
| REQ-M5-04 | Member | Membership expired, with prompt to renew |
| REQ-M5-05 | Member | Listing published confirmation |
| REQ-M5-06 | Member | Listing expiring or expired |
| REQ-M5-07 | Member | Buyer inquiry received on a listing |
| REQ-M5-08 | Admin/site manager | New member registered |
| REQ-M5-09 | Admin/site manager | New payment received |
| REQ-M5-10 | Admin/site manager | Membership renewed |
| REQ-M5-11 | Admin/site manager | Membership expired |
| REQ-M5-12 | Admin/site manager | New listing published |
| REQ-M5-13 | Admin/site manager | Payment or Stripe processing failure |
| REQ-M5-14 | Member→member | Buyer inquiry routed to listing owner with association disclaimer; seller contact protected |

Final list, recipients, and wording confirmed during discovery.

### Module 06 — Member Management & Admin Tools

| ID | Requirement |
|---|---|
| REQ-M6-01 | Consolidated member management screen: search, filter, sort by status, term date, state, listing activity. |
| REQ-M6-02 | Bulk actions: extend/adjust terms, change status, update selected fields across many members. |
| REQ-M6-03 | Bulk CSV export for mailings, dues reconciliation, event planning, records. |
| REQ-M6-04 | Individual member record: profile, membership history, payments, listings in one place. |
| REQ-M6-05 | Plugin settings screen: pricing, term length, listing categories, listing duration. |

### Module 07 — Usage Tracking & Reporting

| ID | Requirement |
|---|---|
| REQ-M7-01 | Admin dashboard: membership counts, renewals, revenue, listing volume over time. |
| REQ-M7-02 | Engagement tracking: listing views, directory searches, farm page visits, contact requests. |
| REQ-M7-03 | Active vs dormant member identification for outreach/retention. |
| REQ-M7-04 | Reports exportable to CSV. |

## Also included

| ID | Deliverable |
|---|---|
| REQ-X-01 | Data migration: existing member records, farm information, active listings from Classified Listing Pro, with a review pass before go-live. |
| REQ-X-02 | FAQ pages: account setup, farm pages, posting a listing, payment, renewal. (A "Website FAQ" page already exists — see `research/02-site-architecture.md`.) |
| REQ-X-03 | Rollout plan: written plan for announcing/launching to membership (messaging + timing). |
| REQ-X-04 | Training: 45-minute live session for SPGCA staff. |

## Exclusions (quote separately)

- Cattle registry / animal records system changes or integration.
- Native mobile apps.
- Real-time chat/messaging beyond email-based contact.
- Event registration, forums, scholarship, crisis fund functionality (these exist as Gravity Forms pages today and stay as-is).
- Full theme redesign outside the plugin's own screens.
- Third-party integrations not identified in discovery (accounting, CRM, email marketing).
- Hosting, SSL, WP core/theme maintenance, Stripe account and fees.

## Assumptions

SPGCA provides timely access to site/hosting, Stripe credentials or connection, existing member and listing data, and a
single empowered point of contact. Delays extend the schedule.

## Process & timeline

1. Discovery — one 60–90 min call; confirm terms, pricing, fields, categories, notification triggers; review data for
   migration. Deliverable: written requirements summary.
2. Prototype build — 3–4 weeks on staging. Deliverable: working prototype.
3. Review & approval — 0–2 weeks, up to two 30–45 min calls. Deliverable: written sign-off.
4. Launch, documentation & training — migrate data, deploy, retire Classified Listings Pro, publish FAQ, deliver rollout
   plan, 45-min training.

## Implications already visible from the current site (cross-reference)

- Categories today: Bulls, Cows, Heifers, Semen, Steers. Proposal lists bulls, heifers, cows, steers, pairs, cattle wanted.
  Decide whether Semen stays and whether Pairs / Cattle Wanted are added (Q in `07-open-questions.md`).
- Price today: $100 / 365 days ("Online Membership"); there is also a $0 "Membership (Pre-paid)" option used on the honor
  system for check/cash payers. REQ-M4-06 replaces that with an admin-recorded offline payment.
- Buyer contact (REQ-M3-08 / M5-14) does not exist today: the listing page reveals the seller's email and phone.
- Membership expiry emails (REQ-M5-03/04/11) do not exist today.
- "Site manager" role: RTCL has an `rtcl_manager` role with two users; map this to REQ-M1-05.
- Reporting (M7): RTCL only tracks listing views/phone reveals in `wp_rtcl_listing_stats`.
