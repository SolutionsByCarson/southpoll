# Live Data Inventory & Migration Notes

Counts from the production copy as of 2026-10-18 (regenerate with `scripts/report-inventory.php`,
`report-forms-subs.php`, `report-payments-detail.php`, `report-renewals.php`).

## Headline numbers

| Entity | Count | Notes |
|---|---|---|
| WordPress users | 9,967 | 5 administrators, 2 `rtcl_manager`, 9,959 subscribers |
| Users with a membership row | 729 | 482 unexpired, 247 expired (by `expiry_date`) |
| Users with breeder number | 6,121 (non-empty) | only 215 of the 729 members have one |
| Users with breeder number but no membership | 5,906 | imported registry contacts |
| Users with neither | 3,332 | |
| Users who have ever logged in | 2,542 | session tokens present |
| Farm pages (`store`) | 250 | 166 owned by active members, 84 by expired members |
| Active members without a farm page | 316 | |
| Listings | 101 | 91 published, 10 expired; 59 distinct owners; 47 marked sold |
| Listing images | 315 attachments across 99 listings (0–5 each) | files under `uploads/classified-listing/` |
| Orders | 998 | 966 completed (589 Stripe, 377 pre-paid $0), 30 abandoned, 2 failed |
| Stripe subscriptions (plugin table) | 499 | 493 active, 6 canceled; all `sub_…` ids |
| Stripe customers on users | 546 | `wp__stripe_customer_id` |
| Listing stats rows | 8,827 | views 95,676 total; 1,161 phone/WhatsApp reveals; 237 phone clicks (2026-06-23 → 2026-10-18) |

## Users

- Registration timeline: 2024 (5 admin/test), 2025 (9,475: 484 in Mar, 2,492 in Apr, then ~600–1,200/month through Nov), 2026 (487).
  The 2025 wave is the WebToffee import of the registry list (`2025-Membership-SPGCA-Import-List.csv`, 508 rows, columns: roles, first_name,
  last_name, display_name, store_name, store_address_line_1/city/state/zip, store_phone, user_email, user_login) plus later "New Members" CSVs,
  and a 3,269-row user export dated 2025-04-28.
- `user_login` is name-style for most; 660 logins are email addresses. 1 duplicate email, 2 placeholder emails.
- Member state coverage: `_billing_state` on all 729 members (top: CA 198?, MO 75, AL 63, TX 49, TN 47, VA 38, OK 33, GA 32, AR 31, FL 27) — the CA
  count is suspicious (likely a default/first-option artifact) and must be validated against farm addresses before using it as the directory state.
- Member contact coverage: `_rtcl_phone` 604/729, `_rtcl_location` 429 (serialized term id), `_rtcl_zipcode` 385, `_rtcl_address` 325, `_billing_city`/`address_1`/`postcode` 553, `_billing_company` 344.

## Memberships

- Term math (`Membership.php`): new = now + `visible` days (365); renew while active = expiry + 365 (ads accumulate); renew after expiry = now + 365.
  Term lengths observed: 557 ≈ 12 months, 102 ≈ 24 months, 70 longer (multiple renewals / stacked pre-paid orders; one to 2032).
- Expiry spread: 2026-04 (83) through 2027-10 with a long tail; 50 expire in the next 60 days.
- 175 memberships have more than one order; 227 members who joined in 2025 are still unexpired (renewed).
- `active` flag is 1 for all rows (never used); status is purely `expiry_date` vs now.
- `membership_categories` meta = all 5 categories for everyone (no per-member category restriction in practice).

## Farm pages

- 250 stores, one per owner, all published, all owners have a membership row.
- Field fill rates: address 206, phone 275*, email 282*, logo 184, banner 136, website 49, description 68, social 61, slogan 206 (*meta rows incl. empty).
- Address is free text in every imaginable format ("Prescott, AR", "5199 County Rd 351 Fulton, MO 65251", "Gadsden, Alabama Mountain Grove, Missouri").
  Migration must parse state (and city/zip where possible) into structured fields and flag the unparseable ones for manual review.
- `store_category` "South" is noise. Opening hours (`oh_*`) and WhatsApp are not needed.

## Listings

- Created 2025-05 (1), 2026-04/05 (3), then 27/17/30/23 per month Jul–Oct 2026 (the data before 2026-07 was lost to the 18-day auto-delete).
- Categories: Bulls 41, Cows 25, Heifers 17, Steers 6, Semen 2. Locations: 20 states used (MO 18, VA 13, OK 12, AL 9, TX 7, TN 6 …).
- Price: fixed 79 / negotiable 22; range pricing on 6; min $3.75, avg $5,054, max $13,000. Ad type all "sell" but one.
- Custom text fields: Seller Name (101/101), Business Name (82/101). No videos.
- Expiry dates fall 2026-10 → 2027-01; 6 published listings belong to expired members.

## Payments & Stripe

- Every completed Stripe payment is a one-time PaymentIntent charge (`ch_…` transaction, `_stripe_intent_id` present, customer IP present) — no
  webhook-created recurring orders exist (`_rtcl_recurring` never set).
- Repeat Stripe payers: 50 users; gaps 0–441 days (median 263) — manual renewals, not annual auto-charges.
- Stripe monthly volume: 2025-04 62, then 22–37/month, spikes 2025-12 (61), 2026-01 (73), 2026-03 (71), last recorded 2026-08-02. Pre-paid $0 orders continue
  through 2026-10 (19/month) — i.e. since August 2026 members have been joining/renewing via the honor-system option, not Stripe (**check with SPGCA: Q-02**).
- 499 Stripe `sub_` objects exist for members (created through 2026-06-12). Unknown whether they are live, cancelled, or failing in Stripe. **Q-01.**
- Fees recorded per order (`_stripe_fee`, `_stripe_net`).

## Data-quality flags for migration

1. Decide the membership source of truth for the ~5,906 "breeder number but no membership" users (likely paid by mail and never activated online).
2. Validate `_billing_state` vs. farm address vs. `_rtcl_location` before deriving a canonical member state.
3. Parse free-text farm addresses; expect a manual review list.
4. 316 active members have no farm page: the new system should auto-create a minimal farm page from name/state so the directory is complete (confirm).
5. 84 expired members' farm pages are hidden today; decide retention/visibility rules.
6. Duplicate/placeholder emails (3 users).
7. Subscriber role on 9,959 users includes thousands who are not members; the plugin's member status must come from its own table, not the WP role.
8. Breeder number format: alphanumeric, 9–15 chars; keep as a free-text member field.
9. Listing images: keep attachment ids; files already in `uploads/classified-listing/` can stay in place (no file moves needed).
10. Listing stats since 2026-06-23 can seed the new reporting tables if useful.

## Proposed mapping into the new plugin (draft; finalize after action plan)

| Current | New |
|---|---|
| `wp_users` + role subscriber | Keep users; add plugin role/capabilities; member status from plugin table |
| `wp_rtcl_membership` row | `members`/`memberships` table: user_id, status (active/expiring/expired/pending), term_start, term_end, source (stripe/offline/migrated), notes |
| `_rtcl_order_ids` + `rtcl_payment` posts | `payments` table: member, amount, method, stripe ids, paid_at, term_granted |
| `southpoll_breeder_number`, `_rtcl_phone`, `_billing_*` | member profile fields |
| `store` post + meta | `farm` CPT (or table): name, slug, description, state, city, zip, address, phone, public email flag, website, socials, logo, gallery |
| `rtcl_listing` + form-builder meta | `listing` CPT: category, title, description, price/price_type, seller name, state, images, status (draft/published/sold/removed/expired), expires_at |
| `rtcl_category` terms | listing category taxonomy (map Bulls/Cows/Heifers/Steers; decide Semen/Pairs/Cattle Wanted) |
| `rtcl_location` terms | state taxonomy or fixed list |
| `wp_rtcl_listing_stats` | events table (view/contact/search) |
| `wp_rtcl_subscriptions` | not migrated; resolve in Stripe |

## Migration plan (outline)

1. Freeze: announce cutover window; export full DB backup; export RTCL CSV (core exporter) as a secondary snapshot.
2. Dry-run migration on Local/staging with the scripts in `scripts/` (to be written): users → members → payments → farms → listings → images → stats.
3. Produce review reports: unparseable addresses, members without farm, expired-with-listings, duplicate emails, Stripe subscription reconciliation.
4. SPGCA review pass (proposal REQ-X-01).
5. Cutover on production; deactivate the three RTCL plugins and mu-plugins; swap shortcodes on pages 71/49; add 301 rules from `/rtcl_listing/…`, `/store/…`, `/my-account/my-store/` to new URLs; remove child-theme RTCL code.
6. Post-launch verification queries (counts must match the review report).
