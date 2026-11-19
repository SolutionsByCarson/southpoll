# Site Architecture — southpoll.com

Surveyed 2026-10-18 from the Local copy at
`C:\Users\Carson\Local Sites\south-poll-grass-cattle-association\app\public`. Raw data: `db-inventory-raw.md`.

## Environments

| | Local (this machine) | Production |
|---|---|---|
| Host | Local by Flywheel, site domain `south-poll-grass-cattle-association.local` (https, self-signed) | WP Engine (`.wpe-pull-ignore`/`.wpe-push-ignore` markers; WPE Smart Plugin Manager + WPE Site Migration plugins; debug logger tries `/home/wpe-user/`) |
| Live URL | — | https://southpoll.com |
| Local run dir | `%APPDATA%\Local\run\v-sOBIKnr\` (nginx, php-fpm, mysql configs) | — |
| PHP | 8.2.29 (`%APPDATA%\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe`), php.ini at `run\v-sOBIKnr\conf\php\php.ini` | (assume 8.2) |
| MySQL | 8.4.0, TCP port **10011**, db `local`, prefix `wp_` | — |
| WP-CLI | not installed; use `temp/scripts/wp-run.sh` | — |
| pdftotext | available via Git Bash mingw64 | — |

Local copy was pulled from WP Engine on 2026-10-18 (file timestamps 13:57–14:06). The debug log and Stripe logs are
production artifacts copied down with it.

## WordPress core

- Version 7.1.3; `WP_DEBUG` false; `WP_ENVIRONMENT_TYPE` local.
- Site title "South Poll Grass Cattle Association"; admin email `info@southpoll.com`; timezone America/Chicago; date format `F j, Y`.
- Permalinks `/%postname%/`; front page = page 2 (Home); no posts page.
- `users_can_register` = 0 (WP registration off) but Classified Listing's own registration form is enabled; default role `subscriber`.
- Comments disabled site-wide by child theme code.

## Theme

- Parent: **Pro 6.9.7** by Themeco (X framework) with bundled **Cornerstone 7.9.7** page builder. Stack = Integrity (light).
  Path `wp-content/themes/pro/`. Compiled CSS comes from `pro/framework/dist/` plus Cornerstone-generated inline CSS.
- Child: **pro-child** (`wp-content/themes/pro-child/`) — all South Poll customizations live here:

| File | Purpose |
|---|---|
| `style.css` | Small RTCL layout overrides (left-align listings, unstyle `.entry-wrap`, banner flexbox, hide admin bar for subscribers). |
| `functions.php` | Enqueues parent/child CSS; `[member_grid]` directory shortcode; `[classifieds_search]` search box; gettext relabel Store→Farm; `role-subscriber` body class; breeder-number field on register/edit-account; custom admin email on membership purchase; seller email reveal on listing page; disables comments. |
| `functions.php.bak-20260707` | Backup before removing a band-aid redirect (2026-07-07). |
| `inc/membership-purchase-email.php` | `SouthpollMembershipPurchaseEmail` (RtclEmail subclass) → sends to info@ and registry@southpoll.com with breeder number. |
| `classified-listing/content-listing.php` | Listing loop item template override (near-stock). |
| `classified-listing/content-single-rtcl_listing.php` | Single listing content override (near-stock). |
| `classified-listing/content-single-store.php`, `single-store.php` | Farm page overrides (near-stock). |
| `classified-listing/emails/membership-purchased-email-to-admin.php` | Email body for the custom admin email. |
| `single-rtcl_listing.php` | Full single-listing page template wrapped in Pro `x-section/x-row` markup; related listings moved under sidebar; inline CSS. |
| `no-logo.jpg` | Fallback farm logo. |

- Cornerstone assets stored as `tco-data` posts: header #161 "South Poll Header" (singular pages), footer #177 "SouthPollFooter" (entire site),
  archive layout #659 "Classified Listings" assigned to `archive:post-type = rtcl_listing`, #660 unused. 31 `cs_template` snippets
  (e.g. RedButton, GoldButton, Header-Members, Join SPGCA).
- Customizer "Additional CSS" (post 566): mobile heading sizes + `.rtcl-listing-sidebar-info-wrap` margin.
- Design tokens: see `../branding/brand-style-guide.md`.

## Plugins (all active on production; the three classified-listing plugins were deactivated on Local 2026-10-18 — see build log)

| Plugin | Version | Role on the site | Build impact |
|---|---|---|---|
| classified-listing | 6.1.5 | Core listings/accounts/checkout | **Replace** |
| classified-listing-pro | 4.2.6 | Stripe/Authorize/Woo gateways, subscriptions, chat, compare, REST | **Replace** |
| classified-listing-store | 3.2.1 | Stores (farm pages) + memberships | **Replace** |
| gravityforms (+recaptcha, +signature) | 3.0.1 / 2.2.2 / 4.9.0 | Contact, newsletter, scholarship, junior membership, crisis fund forms | Keep; not part of build |
| popup-maker | 1.25.0 | "Sign Up for SPGCA Updates" popup (#778) | Keep |
| wordpress-seo (Yoast) | 28.7 | SEO | Keep; new CPTs should register cleanly with Yoast |
| wp-mail-smtp | 4.10.2 | Mail delivery | Keep; note 401 Unauthorized `wp_mail_failed` errors in Stripe logs around 2026-03-13 |
| anti-spam (Titan) | 7.5.3 | Spam protection | Keep; verify it does not block plugin forms |
| resize-image-after-upload | 1.8.6 | Downsizes uploads | Keep; affects listing/farm photo uploads |
| envira-gallery | 1.17.2 | Photos page | Keep |
| revslider | 7.2.2 | Sliders | Keep |
| eps-301-redirects | 2.85 | 1 rule: `my-account/https:/southpoll.com/submit-a-listing/` → page 646 (927 hits) | Keep; rule can be retired after launch |
| users-customers-import-export-for-wp-woocommerce (WebToffee) | 2.7.6 | Used in 2025 to bulk-import ~9,400 users | Keep for now; CSVs in `wp-content/webtoffee_import/` contain PII |
| classic-editor | 1.7.0 | Classic editor | Keep |
| worker (ManageWP) | 4.9.38 | Remote management (mu loader `0-worker.php`) | Keep |
| autoupdater (WPE Smart Plugin Manager) | 6.1.8 | Auto-updates on WPE | **Risk**: can auto-update plugins; exclude the new plugin or pin |
| wpe-site-migration | 1.8.5 | WPE migrations | Keep |

### mu-plugins (`wp-content/mu-plugins/`)

| File | Purpose | Build impact |
|---|---|---|
| `0-worker.php` | ManageWP loader | Keep |
| `southpoll-store-save.php` v1.1 | Native-POST fallback for the "Update Store" (farm) form because the plugin's AJAX save 404'd; mirrors plugin save logic; adds nonce + success notice | Retire with RTCL |
| `zzz-southpoll-debug.php` | Temporary 404 logger for `/my-account` and `/store` URIs → `wp-content/southpoll-debug.log` (1,069 lines, 2026-07-07 → 2026-10-18) | Retire |

## Pages and URL map

Key pages (37 published pages total; most are Cornerstone-built with `template-blank-4.php`):

| ID | Slug | Role | Content |
|---|---|---|---|
| 2 | `/` (home) | Front page | Cornerstone |
| 337 | `/my-account/` | RTCL account hub (`rtcl-canvas_template`) | `[rtcl_my_account]` |
| 646 | `/submit-a-listing/` | Listing form | `[rtcl_listing_form]` |
| 336 | `/checkout/` | Checkout | `[rtcl_checkout]` |
| 71 | `/classifieds/` | Public listings | Cornerstone + `[rtcl_listings view='list' orderby='data' order='DESC']` + `[classifieds_search]` |
| 49 | `/members/` | Member directory | Cornerstone + `[member_grid]` (child theme) |
| 51 | `/join-spgca/` | Join page | Cornerstone; buttons: Join Today Via Online → `/website-faq/`; Junior PDF; New Member PDF |
| 880 | `/website-faq/` | Member how-to FAQ (7 accordion items) | Cornerstone |
| 895 | `/junior-membership/` | GF form 6 | — |
| 63, 751 | cattle-registrations, dna-testing | Registry info (out of scope) | — |

RTCL endpoints in force (`rtcl_advanced_settings`):

| URL | Purpose |
|---|---|
| `/my-account/listings/` | My Listings |
| `/my-account/my-store/` | Farm page editor (endpoint renamed from `store` to `my-store`) |
| `/my-account/payments/` | Payment history |
| `/my-account/edit-account/` | Account details |
| `/my-account/privacy-settings/` | Profile/privacy |
| `/my-account/lost-password/`, `/my-account/logout/` | Auth |
| `/checkout/membership/` | Buy membership |
| `/checkout/submission/`, `/checkout/promote/`, `/checkout/payment-receipt/`, `/checkout/payment-failure/` | Checkout endpoints |
| `/rtcl_listing/{slug}/` | Single listing (permalink base is literally `rtcl_listing`) |
| `/listing-category/{slug}/`, `/listing-location/{slug}/` | Taxonomy archives |
| `/store/{slug}/` | Farm page (post type `store`) |
| `/store-category/{slug}/` | Store category archive |
| `/?rtcl-api=rtcl_gateway_stripe` (or `/rtcl-api/rtcl_gateway_stripe/`) | Stripe webhook endpoint |

## Menus

- **SouthPoll-Menu1** (id 3, location `primary`): Home · About (About SPGCA, History, Board) · Members (Member Directory, Join SPGCA, Rules & Regulations,
  SPGCA Scholarship, Regional Associations, Member Forums, Advertising, Crisis Fund) · Cattle (Cattle Registrations, Classifieds, DNA Testing) ·
  Events (Field Day, Upcoming Events & Sales) · Education (Magazine, Newsletters, Articles, Videos, Photos, Podcast, Tutorials) ·
  Store (external itemorder.com) · Contact.
- **SouthPoll-Mobile** (id 2, unassigned) mirrors it and adds Junior Membership and My Account.
- "Account Login" button lives in the Cornerstone header (per FAQ copy), not in the menu.

## Gravity Forms

| ID | Title | Entries |
|---|---|---|
| 1 | SPGCA Registry Info | 299 |
| 2 | Join SPGCA (Farm/Ranch Name, Name, Email, Herd Letters ×3) | 1 |
| 3 | Sign Up for the SPGCA Newsletter | 1,372 |
| 4 | SPGCA Scholarship Application | 2 |
| 5 | Contact SPGCA Office | 158 |
| 6 | SPGCA Junior Membership Form | 0 |
| 7 | SPGCA Crisis Fund Application | 5 |

## Email

- Delivery via WP Mail SMTP. RTCL from-name "South Poll Grass Cattle Association", from `info@southpoll.com`, admin notice address `registry@southpoll.com`.
- RTCL email template colors are the plugin defaults (base `#0071bd` blue) — off-brand.

## Cron (WP-Cron)

`rtcl_hourly_scheduled_events` (listing expiry/renewal/deletion), `rtcl_daily_scheduled_events`, `rtcl_cleanup_sessions` and
`rtcl_cleanup_temp_listings` (twice daily). No membership-expiry job exists.

## Uploads (`wp-content/uploads/`)

- `2024/ 2025/ 2026/` — normal media (farm logos/banners included; 992 attachments total).
- `classified-listing/YYYY/MM/` — listing gallery images (RTCL custom upload dir; 3,357 files incl. all sizes).
- `rtcl-logs/` — RTCL/Stripe debug logs 2026-01-19 → 2026-10-14 plus `mail.log` (production).
- `gravity_forms/`, `pum/`, `revslider/`, `rtcl/` (block CSS), `wp-migrate-db/`.
- `wp-content/webtoffee_import/*.csv`, `webtoffee_export/*.csv` — 2025 member import/export files (PII; 3,269-row user export).

## Database (98 tables)

Standard WP tables plus:

| Table | Rows | Owner |
|---|---|---|
| `wp_rtcl_membership` / `_meta` | 729 / 4,414 | Store add-on (memberships) |
| `wp_rtcl_membership_posting_log` | 137 | Store add-on |
| `wp_rtcl_posting_log` | 25 | Store add-on (free-ad log) |
| `wp_rtcl_subscriptions` / `_meta` | 499 / 497 | Pro (Stripe subscriptions) |
| `wp_rtcl_listing_stats` | 8,827 | Core (view/reveal/phone_click per listing per day since 2026-06-23) |
| `wp_rtcl_sessions` | 15,276 | Core |
| `wp_rtcl_forms` | 1 | Core form builder |
| `wp_rtcl_conversations`, `_messages`, `wp_rtcl_push_notifications`, `wp_rtcl_import_*`, `wp_rtcl_tax_rates` | 0 | Unused |
| `wp_gf_*` | — | Gravity Forms |
| `wp_redirects` | 1 | 301 Redirects |

Leftovers from earlier experiments: `hp_*` post types (HivePress) and `theme_mods_listinghive`, `theme_mods_x`, `theme_mods_twentyseventeen`.

## Production debug log highlights (`wp-content/southpoll-debug.log`)

- 2026-07-07: `/my-account/store/` and `/my-account/my-store/` POST 404s (the AJAX/store-save defect that led to the mu-plugin).
- Hundreds of 404s for `/website-faq/my-account/...` — the FAQ page links to `my-account/...` relatively.
- Repeated probes to `/store/.env`; repeated 404s for `store.js.map`; `tel:` links resolving under `/store/{slug}/tel:...`.
- `/store/membership`, `/store/product/...` — stale URLs from the old site.
