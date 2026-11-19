# SouthPoll Membership Plugin — Architecture Roadmap

Derived from `plugin architecture.md` (Carson Bennett / Ranch House Designs). That document is the specification; this roadmap turns it into a
concrete technical design and build order. Where this roadmap proposes something the spec left open, it says **Proposed** and the matching
question lives in `07-open-questions.md` (D-xx ids). Nothing here overrides the spec.

Related: `03-build-todo.md` (step-by-step checklist), `branding/brand-style-guide.md` (tokens), `research/` (current site + migration data).

---

## 1. Plugin identity and conventions

| Item | Proposed value |
|---|---|
| Folder / slug | `wp-content/plugins/southpoll-membership/` (`southpoll-membership`) — D-01 |
| Namespace | `SouthPoll\Membership\…` with PSR-4 autoloader (Composer optional; a lightweight in-plugin autoloader avoids a build step for PHP) |
| Prefixes | PHP `sp_`, DB tables `{$wpdb->prefix}sp_*`, options `sp_*`, hooks `sp/…`, REST namespace `sp/v1`, CSS classes `.sp-…`, JS global `SP` |
| Text domain | `southpoll-membership` |
| Minimums | WP 6.5+, PHP 8.1+ (site runs WP 7.1.3 / PHP 8.2) |
| Code style | WordPress Coding Standards, strict types where practical, no inline SQL without `$wpdb->prepare` |
| Versioning | Semantic version constant + `sp_db_version` option driving idempotent migrations on `plugins_loaded` |

Top-level layout:

```
southpoll-membership/
  southpoll-membership.php        bootstrap, constants, autoloader, activation/deactivation
  src/
    Core/        Plugin, Container, Activator, Migrator, Settings, Capabilities, Cron, Logger, Assets
    Routing/     Router (rewrite rules + template_include), Endpoints, Redirects
    Auth/        Login, Registration, PasswordReset, Recaptcha, Honeypot, Guards
    Members/     Member (profile), FarmUsers (owner/manager), Invitations
    Farms/       Farm, FarmRepository, FarmMedia, FarmMeta, Geocoder, Offerings taxonomy, Directory query
    Memberships/ Membership, MembershipRepository, StatusEngine, OfflineClaims, Reminders
    Payments/    Stripe (client, checkout, portal, webhooks, events), Payment, Invoices
    Listings/    CPT registration, ListingRepository, Lifecycle (expiry/renew/sold), MetaFields, Search
    Engagement/  Favorites, SavedSearches, Share, Notifications (dispatcher + queue)
    Contact/     Inquiry forms (farm + listing), InquiryRepository
    Email/       Mailer, Template, Messages (one class per email), Preferences
    Analytics/   Events, Rollups, Reports
    Admin/       Menu, Pages (Settings, Users, Farms, Listings, Memberships, Taxonomies, MetaFields, Integrations, Analytics), Tables, ImportExport
    Rest/        Controllers per resource (directory, listings, favorites, searches, contact, admin tables)
    Frontend/    Template loader, Dashboard shell, Blocks/shortcodes
    Migration/   RTCL importers (users, farms, listings, memberships, payments), redirect map
  templates/     PHP views: dashboard/*, directory/*, farm/*, classifieds/*, listing/*, emails/*, admin/*, partials/*
  assets/
    css/         sp-tokens.css, sp-ui.css (components), sp-dashboard.css, sp-admin.css
    js/          sp-core.js (toast, modal, share, countup, table, fetch), sp-filters.js, sp-maps.js, sp-dashboard.js, sp-admin.js
    img/         southpoll-horizontal-logo.png (bundled per spec), icons (inline SVG sprite)
    vendor/      alpine.min.js (bundled, no CDN) — D-02
  languages/
  uninstall.php
```

---

## 2. Data architecture

### 2.1 Principle: WordPress owns authentication, the plugin owns everything else

The spec asks for user data in plugin tables "separate and distinct from the WordPress user table" with an encrypted password column.
**Decided 2026-10-18 (D-03):** keep `wp_users` as the authentication record (login, hashed password, email, sessions, nonces, password reset,
capabilities all come from core and stay secure and auditable) and give the plugin a 1:1 profile table `sp_members` keyed by `user_id` that holds
every other user field the spec lists. All plugin screens read/write `sp_members` and only touch `wp_users` for email/password/login. This
satisfies the "flat, exportable, separate" goal without re-implementing authentication.

### 2.2 Tables (all `{$wpdb->prefix}sp_…`, InnoDB, utf8mb4, created with `dbDelta`)

| Table | Purpose | Key columns |
|---|---|---|
| `sp_members` | Profile for every account (buyer, owner, manager) | `id`, `user_id` UNIQUE, `first_name`, `last_name`, `phone`, `address1`, `city`, `state`, `zip`, `notify_all` (bool), `default_notify_favorites`, `default_notify_searches`, `favorites_share_token`, `last_seen_at`, timestamps |
| `sp_farms` | One row per farm | `id`, `owner_user_id`, `billing_user_id` (nullable), `name`, `slug` UNIQUE, `slogan`, `about` (longtext), `logo_id`, `banner_id`, `address1`, `city`, `state` (2), `zip`, `lat`, `lng`, `geocoded_at`, `phone`, `email`, `website`, `owner_name`, `social` (json), `hours` (json), `breeder_number`, `contact_form_enabled`, `status` (`active`,`inactive`), `region` (auto-calculated from `state` on every save; schema 0.3.0), `active_listing_count` (denormalised counter Phase 3 maintains; drives the directory light, "has listings" filter and "most active" sort), `last_listing_at`, timestamps |
| `sp_farm_users` | Users attached to a farm (owner + account managers), many-to-one user→farm | `id`, `farm_id`, `user_id` (nullable until invite accepted), `role` (`owner`,`manager`), `invite_email`, `invite_token`, `invited_at`, `accepted_at`, `notify_listings`, `notify_membership`; UNIQUE(`user_id`) |
| `sp_farm_media` | Farm gallery | `id`, `farm_id`, `type` (`image`,`video`), `attachment_id`, `video_url`, `sort_order` |
| `sp_farm_meta` | Values for admin-configured farm fields | `farm_id`, `field_key`, `value` (text); PK(`farm_id`,`field_key`) |
| `sp_meta_fields` | Admin-configured fields for farms and listings | `id`, `scope` (`farm`,`listing`), `group_id`, `field_key`, `label`, `type` (`text`,`textarea`,`select`,`bool`), `options` (json), `default_value`, `required`, `sort_order`, `active` |
| `sp_meta_field_groups` | Listing field groups by category | `id`, `scope`, `title`, `category_term_ids` (json, empty = all), `sort_order` |
| `sp_membership_tiers` | Membership products. **One tier for now** ("Annual Membership", $100, 365 days, default) seeded at install; schema and admin are multi-tier ready | `id`, `name`, `slug`, `description`, `price_cents`, `currency`, `duration_days` (365), `billing_interval` (`year`,`month`) + `interval_count` for recurring, `listing_limit` (nullable → global default), `stripe_product_id`, `stripe_price_id` (one-time), `stripe_recurring_price_id`, `is_default`, `active`, `sort_order`, timestamps |
| `sp_memberships` | Membership terms (apply to farms) | `id`, `farm_id`, `tier_id`, `status` (`pending`,`active`,`expired`,`cancelled`), `starts_at`, `ends_at`, `auto_renew` (bool), `stripe_customer_id`, `stripe_subscription_id`, `source` (`stripe`,`offline`,`migrated`,`comp`), `price_cents_paid`, `created_by`, `notes`, timestamps. Derived, not stored: `expiring` (ends within 30 days), days remaining |
| `sp_payments` | Every payment / claim / invoice | `id`, `membership_id`, `farm_id`, `user_id`, `amount_cents`, `currency`, `method` (`stripe`,`check`,`cash`,`other`,`migrated`), `status` (`pending`,`succeeded`,`failed`,`refunded`,`rejected`), `stripe_payment_intent_id`, `stripe_invoice_id`, `stripe_charge_id`, `receipt_url`, `term_start`, `term_end`, `paid_at`, `notes`, `created_at` |
| `sp_stripe_events` | Webhook idempotency log | `event_id` UNIQUE, `type`, `processed_at`, `payload_hash` |
| `sp_favorites` | Farm and listing favorites | `id`, `user_id`, `object_type` (`farm`,`listing`), `object_id`, `notify` (bool), `created_at`; UNIQUE(`user_id`,`object_type`,`object_id`) |
| `sp_saved_searches` | Saved classifieds searches | `id`, `user_id`, `name`, `params` (json), `is_default`, `notify`, `share_token`, `last_viewed_at`, `last_notified_at`, `created_at` |
| `sp_inquiries` | Contact-form submissions (farm or listing) | `id`, `target_type`, `farm_id`, `listing_id`, `sender_user_id` (nullable), `sender_name`, `sender_email`, `sender_phone`, `message`, `ip_hash`, `recaptcha_score`, `delivered_to`, `created_at` |
| `sp_notifications_queue` | Outbound email queue (saved-search alerts, favorites alerts, digests) | `id`, `email_key`, `user_id`, `payload` (json), `send_after`, `attempts`, `sent_at`, `error` |
| `sp_events` | Raw analytics events | `id`, `event` (`farm_view`,`listing_view`,`search`,`contact`,`favorite`,`share`,`login`,`signup`), `object_type`, `object_id`, `user_id`, `visitor_hash`, `meta` (json), `created_at` |
| `sp_stats_daily` | Rollups for the analytics page | `day`, `metric`, `object_type`, `object_id`, `value`; PK(`day`,`metric`,`object_type`,`object_id`) |

### 2.3 Listings as a custom post type

- CPT `sp_listing` (public, `has_archive` false — the `/classifieds/` page is plugin-rendered; `rewrite` false — the Router owns URLs).
- Taxonomy `sp_listing_category` (hierarchical false, configurable in admin; defaults: Females, Bulls, Finishers, Feeders, Calves, Semen).
  Term meta: `seo_title`, `seo_description`, `sort_order`, `color`.
- Post fields: `post_title` = ad title, `post_content` = ad copy, `post_excerpt` = optional excerpt, `post_author` = creating user.
- Post meta: `_sp_farm_id`, `_sp_price` (cents, nullable), `_sp_price_label` (e.g. "Call for price"), `_sp_status` (`available`,`coming_soon`,`pending`,`sold`,`inactive`), `_sp_published_at`, `_sp_expires_at`, `_sp_sold_at`, `_sp_renewed_count`, `_sp_gallery` (json of attachment ids + video urls), `_sp_view_count`, `_sp_meta_{field_key}` for configured fields, `_sp_state`/`_sp_region` (copied from farm at save for filtering).
- WordPress post status: `publish` while visible (any of available/coming_soon/pending/sold-within-14-days), `draft` for inactive/withdrawn, custom status `sp_expired` for expired. Visibility is decided by `_sp_status` + `_sp_expires_at`, never by deleting rows (favorites keep history).
- Offerings taxonomy for farms: `sp_offering` registered for object type `sp_farm` and attached with `wp_set_object_terms( $farm_id, …, 'sp_offering' )` (term relationships are object-type agnostic; use `_update_generic_term_count`). Defaults: Females, Bulls, Finishers, Feeders, Calves, Semen.

### 2.4 Options

- `sp_settings` (array): listing limits & durations (global default; a tier may override the limit), sold visibility days, reminder schedule, directory/classifieds page overrides, membership admin name/phone/email, from-email override, default notification toggles, region map (state → East/South/Midwest/West, seeded from the child theme), pagination defaults. Membership price and term live on the tier rows, not in settings.
- `sp_integrations` (array): Stripe mode + keys + webhook secret, Google Maps browser key + server geocoding key, reCAPTCHA site/secret (seed from existing RTCL keys at install), SMTP from-email. Secrets rendered masked in admin; stored via `update_option` with `autoload=no`.

---

## 3. Users, roles, capabilities

| Spec user type | Implementation |
|---|---|
| Non-account visitor | No WP user. Sees directory/classifieds without contact info; may use contact forms (reCAPTCHA v3 + honeypot + rate limit). |
| Free / buyer | WP role `sp_buyer` (custom role, minimal caps) + `sp_members` row. Sees contact info, favorites, saved searches. |
| Member (owner) | Same WP role + `sp_farm_users.role = owner` on a farm whose membership is `active`. Capabilities are **computed** by `Capabilities::can( $user, 'manage_farm' )` from farm/membership state, not stored as WP caps, so expiry takes effect instantly. |
| Account manager | Same WP role + `sp_farm_users.role = manager`. Loses farm rights when removed or when the farm's membership lapses (falls back to buyer). |
| Site staff | WP `editor` and `administrator` get `sp_manage` caps (Users/Farms/Listings/Taxonomies/Meta/Analytics). `sp_manage_settings` + `sp_manage_integrations` (Stripe keys, API keys) **Proposed** administrator-only — D-04. |

### 3.1 Ownership, managers, and lapse rules (D-19 / D-20 — **decided by Carson 2026-10-18**: rules 1–5 and the lapse table below are final; rule 2 clarified as "an email already attached to a farm can only be moved to another farm by a site admin"; rule 3 is site-admin only with no member-area transfer at all)

**The three relationships**

- A **farm** has exactly one **owner**: the user who created it. The owner is the human the association deals with. They control who else is
  attached, they are the default billing contact, and only they (or staff) can renew, cancel, invite/remove managers, or request a transfer.
- A farm can have any number of **account managers**: separate logins the owner invites by email. A manager gets farm rights (edit the farm page,
  create/edit/publish listings, receive the notifications the owner ticks for them) *only because* they are attached to a farm whose membership
  is active. A manager can never invite or remove users, touch billing, cancel, or transfer the farm.
- A farm may name a **billing user** (owner or a manager) who receives invoices and receipts. It conveys no rights.
- The **membership** attaches to the farm, not to a person. Changing the owner or managers never resets the term.

**Ownership rules (confirmed)**

1. *One farm per user.* A user is either the owner of one farm, a manager of one farm, or a buyer. `sp_farm_users.user_id` is unique. Someone
   running two operations uses two accounts. The dashboard is built around a "current farm" context so this can be relaxed later without a redesign.
2. *Invites and existing roles.* A user who already owns or manages a farm cannot accept an invite to another farm; the invite page explains why.
   An email address already attached to a farm can only be moved to another farm by a site administrator (Users admin → reassign).
3. *Ownership transfer is site-administrator only* (Farms admin → "Transfer ownership" to a manager or any buyer account). There is no
   member-area transfer. The previous owner becomes a manager (default) or a buyer. Stripe customer email and billing user are updated.
4. *Owner account deletion is blocked* while the farm has an active or pending membership until ownership is transferred (hook on `delete_user`).
   Farms are never hard-deleted; staff can set them `inactive`.
5. *Removing a manager* takes effect immediately. The listings they created stay with the farm (listings belong to the farm; the author field is
   just history). Their account falls back to buyer.

**Lapse state machine (D-20, confirmed)**

| Membership state | Directory / farm page | Listings | Owner can… | Managers can… | Alerts & emails |
|---|---|---|---|---|---|
| **No membership** (chose "member" at signup but never completed checkout) | Hidden; URL shows "not yet active" | None allowed | Edit farm draft, go to checkout; dashboard banner "Finish your membership" | n/a (invites disabled) | None |
| **Pending** (offline claim awaiting verification) | Hidden | Can draft, cannot publish | Edit farm, draft listings, see "awaiting verification" | n/a (invites disabled until active) | Owner: claim received; admin: verify request |
| **Active** | Visible; map pin; contact info to logged-in users; contact form to guests | Publish up to the limit | Everything | Edit farm, manage/publish listings | Normal |
| **Expiring** (≤ 30 days, derived) | Unchanged | Unchanged | Everything + renew CTA | Unchanged | 30 / 7 / 1-day reminders (non-recurring) or "renews in X days" (recurring) |
| **Expired** (`ends_at` passed) | Hidden from directory and map. Direct URL shows a minimal "membership not active" page with no contact info or listings so links do not 404; the owner sees the full page with a renew banner | All public listings are hidden (unpublished with reason `membership`); data kept; favorites show them as inactive; their remaining-days clock is **frozen** so renewal restores whatever time was left | Edit farm and listing drafts, renew; cannot publish | Lose farm rights (My Farm / My Listings show "membership lapsed, contact {owner}"); keep buyer features; stay attached so restoration is automatic | Owner: expired, +7, +30 day prompts; admin: expired. No saved-search / favorite alerts fire for hidden listings |
| **Cancelled** (staff action or refund) | Same as expired, immediately | Same as expired | Same as expired | Same as expired | Owner + admin: cancelled |
| **Renewed / reactivated** | Visible again | Listings restored with frozen time re-applied (already sold/expired ones stay as they were) | Everything | Rights restored automatically | Owner: renewed + receipt; admin: renewal |

Additional rules in the same spirit:

- *Grace period:* none for visibility (hide at `ends_at`), configurable in settings (`grace_days`, default 0) if the association wants slack. Data
  is retained indefinitely either way.
- *Recurring payment failure:* Stripe retries automatically for up to ~3 weeks. The membership stays active until `ends_at`; if still unpaid at
  `ends_at` it expires normally. Owner and membership admin get "payment failed" emails on the first failure and a final one at expiry.
- *Renewal claim while active:* an offline claim submitted before expiry does not change the status; approval extends `ends_at` from the current end date.
- *Listing-level expiry is independent:* a listing's own 60-day clock and 14-day sold window keep running while the membership is active; the
  freeze only applies while the farm is hidden for membership reasons.

Existing WP users (9,967 subscribers) are migrated by adding `sp_members` rows; their role becomes `sp_buyer` (or stays `subscriber` with equivalent caps — D-05). Subscribers are kept out of `wp-admin` and the admin bar.

Login/registration/reset live at `/members/login/` (custom forms over `wp_signon`, `wp_insert_user`, `retrieve_password`/`reset_password`).
`wp-login.php` keeps working for staff; non-staff hitting it are redirected to `/members/login/`.

---

## 4. Routing and pages

The Router registers rewrite rules on `init`, maps them to handlers, and uses `template_include` to render plugin templates. Pages do not need
to exist in WordPress, but if an admin assigns a page in settings the plugin uses that page's slug (spec: "allow a url/page override").

| URL | Renders | Chrome |
|---|---|---|
| `/members/` | Member directory (also `[member-directory]` shortcode for the existing Cornerstone page) | Theme header/footer |
| `/members/login/` | Login / register / reset (tabs) with membership comparison | Theme header/footer (**Proposed**; spec says the dashboard is standalone, login unspecified — D-06) |
| `/members/dashboard/` | Dashboard home | Standalone dashboard shell (sidebar, logo, "back to site") |
| `/members/my-favorites/`, `/members/saved-searches/`, `/members/my-farm/`, `/members/my-listings/`, `/members/edit-listing/?id=`, `/members/account-details/`, `/members/manage-membership/`, `/members/help/` (spec: "Website How To", name TBD — D-07), `/members/logout/` | Dashboard pages | Standalone shell; unauthenticated → redirect to login with notice |
| `/farms/{slug}/` | Public farm page (decided 2026-10-18, D-08; `/members/{slug}/` would collide with the reserved endpoints above) | Theme header/footer |
| `/classifieds/` | Classifieds archive (page-override or `[classifieds]` shortcode) | Theme |
| `/classifieds/{category-slug}/` | Category archive with configurable SEO title/description. The slug `listing` is reserved and rejected for categories. | Theme |
| `/classifieds/listing/{listing-slug}/` | Single listing (decided 2026-10-18, D-09). Listing slugs are unique site-wide; renamed listings keep old slugs in `_sp_old_slugs` for 301s. | Theme |
| `/favorites/{token}/` | Public shared favorites list (decided 2026-10-18, D-10: random per-user token, regenerable) | Theme |
| `/search/{token}/` | Public shared saved search (loads the classifieds page with that filter) | Theme |
| `/wp-json/sp/v1/…` | REST API for all live search/filter/table/favorite/search/contact actions | — |
| `/sp-webhook/stripe/` (or `/wp-json/sp/v1/stripe/webhook`) | Stripe webhook receiver (signature-verified) | — |

Filters are reflected in the URL query string (`?q=&cat=&state=&region=&farm=&since=&view=map`) with `history.replaceState` so every search is
shareable and savable (spec sentence "URLs should…" was cut off — D-11).

Old RTCL URLs (`/rtcl_listing/…`, `/store/…`, `/my-account/…`, `/checkout/…`, `/submit-a-listing/…`) get 301s from a redirect map seeded from
`research/rtcl-urls.csv` at migration time.

Caching note (WP Engine): all `/members/*` dashboard pages send `Cache-Control: no-store` and set the WPE no-cache cookie behavior for logged-in
users; public pages stay cacheable because filtering runs through REST.

---

## 5. Front-end stack

- **Rendering:** PHP templates, progressively enhanced. No external CDN assets (brand fonts already come from the theme; Google Maps loads from
  Google with the configured key).
- **Reactivity:** **Proposed** Alpine.js (bundled, ~16 KB) for live tables, filters, modals, toasts, dashboard widgets — small enough for RHD to
  maintain without a build pipeline — D-02. Plain ES modules otherwise; optional esbuild bundling later.
- **Shared UI kit (one implementation each, reused everywhere, front-end and wp-admin):**
  `SP.toast` slide-out notifications (with action links), `SP.modal`, `SP.share` (email with prefilled subject/body, SMS link, Facebook/X/
  copy link), `SP.table` (live search across all fields, sortable columns, page sizes 10/25/50/100/all, server-side for large sets),
  `SP.countUp` (animated numbers, respects `prefers-reduced-motion`), `SP.filters` (debounced live filtering, URL sync, reset),
  `SP.map` (Google Maps clusters for directory and classifieds, "Get Directions" links), `SP.gallery` (featured + thumbnails, full-screen,
  video modal), `SP.uploader` (drag-drop images via `media_handle_upload` REST endpoint with resize).
- **Styling:** `sp-tokens.css` from `branding/brand-style-guide.md`; mobile-first; dashboard shell independent of theme CSS.
- **Accessibility:** keyboard-operable modals/toasts, ARIA live regions for toasts, focus management, color contrast per brand guide notes.

---

## 6. Memberships and payments

- Membership belongs to a farm and references a **tier**. Per Carson (2026-10-18): one tier for now — the seeded default is **$100 for 1 year,
  running 365 days from the date the membership is created/activated** — with the tier table, checkout, admin, and Stripe product/price wiring
  built so additional tiers can be added later without schema changes. Checkout shows a tier picker only when more than one tier is active;
  with a single tier it is preselected. Statuses: `pending` (offline claim awaiting verification), `active`, `expired`, `cancelled`; `expiring`
  is derived (≤ 30 days). Dashboard banner states: normal, 30-day, 7-day countdown, expired, "renews in X days" when auto-renew.
- Term math (decided 2026-10-18, D-27): new membership `starts_at = activation time`, `ends_at = starts_at + tier.duration_days`. Renewal while
  still active extends from the current `ends_at` (members who renew early keep their remaining days); renewal after expiry starts a fresh
  term from the renewal date. Offline claims use the admin's verification date as the activation time unless the admin overrides it.
- **Stripe (decided 2026-10-18 — D-12):**
  - Stripe Customer per farm (`stripe_customer_id`), created on first checkout; email = billing user or owner.
  - Non-recurring: Stripe Checkout Session `mode=payment` → `checkout.session.completed` webhook creates `sp_payments` row and activates/extends
    the membership (`ends_at = max(now, ends_at) + term`).
  - Each tier owns its Stripe Product and two Prices (one-time and recurring), created lazily by the plugin and stored on the tier row; editing a
    tier's price creates a new Stripe Price and archives the old one.
  - Recurring: Checkout Session `mode=subscription` with the tier's recurring Price;
    `invoice.paid` extends the term; `invoice.payment_failed` notifies owner + membership admin; `customer.subscription.deleted` sets
    `auto_renew = 0`.
  - Payment method changes and invoice history via the Stripe **Customer Portal** (one button in `/members/manage-membership/`), so no card
    data ever touches WordPress. Card brand/last4 cached for display only.
  - Webhook endpoint verified with the signing secret; events deduplicated in `sp_stripe_events`; all handlers idempotent.
  - Receipts: Stripe receipt email enabled + plugin confirmation email (always on).
- **Offline payment claim:** member selects "I paid by check/cash" → membership `pending`, payment `pending`, email to membership admin with
  approve/reject links (nonce) and an admin queue; approval sets term dates and activates.
- **Admin manual actions:** record offline payment, extend/adjust term, comp a membership, cancel, change owner/billing user; all logged in
  `sp_payments`/notes.
- **Migration of the 499 legacy Stripe subscriptions** is decided by Q-01 (cancel vs adopt).

---

## 7. Lifecycle automation (WP-Cron, hourly `sp_hourly` + daily `sp_daily`)

| Job | Rule (defaults are admin-configurable) |
|---|---|
| Listing expiry | Listing visible for 60 days from publish/renew; email owner (+ managers with `notify_listings`) 7 days before with renew link; at expiry set `sp_expired`, keep data, show "no longer available" page with farm link |
| Sold visibility | Sold listings stay public 14 days after `_sp_sold_at`, then hidden (still in favorites) |
| Membership reminders | 30 / 7 / 1 days before `ends_at` (non-recurring) ; "renews in 30 days" (recurring); day-of expiry; 7 and 30 days after expiry |
| Membership expiry | Set `expired` at `ends_at`; farm hidden from directory; listings hidden (unpublish to draft with `_sp_hidden_reason=membership`); managers lose rights; restore everything on renewal |
| Saved-search alerts | On listing publish: enqueue matches for searches with `notify=1`; queue worker sends in batches (immediate per listing, with per-user throttle to avoid floods) |
| Favorite alerts | On listing publish by a favorited farm; on status change of a favorited listing |
| Analytics rollup | Daily aggregation of `sp_events` into `sp_stats_daily`; purge raw events older than N days |
| Housekeeping | Expire invite tokens, clean queue, geocode farms missing coordinates |

---

## 8. Email system

- `Mailer` wraps `wp_mail`, applies the HTML template (bundled logo, maroon header, Montserrat/Arial, footer with membership admin contact),
  sets From name/email override from settings, logs failures.
- Each email is a class with `key`, `subject`, `template`, recipients, and a `configurable` flag. Membership and invoice emails are always sent;
  favorites/saved-search/listing-alert emails default **off** per user and per global default.
- Inventory (member): welcome, email verification (optional), password reset, offline claim received, membership activated/renewed, receipt,
  reminders (30/7/1/expired/+7/+30), recurring renewal notice, payment failed, listing published, listing expiring (7 d), listing expired,
  listing renewed, inquiry received (farm/listing), account-manager invitation, manager removed, favorite-farm new listing, favorite-listing
  status change, saved-search new listings, favorites/search share (mailto only).
- Inventory (membership admin): new account, new paid membership, renewal, offline claim to verify, payment failed, membership expired,
  new listing published, inquiry copy (optional), weekly digest (optional).

---

## 9. Admin area (wp-admin, capability-gated)

Menu "SouthPoll" with: Dashboard (counts with count-up), Users, Farms, Listings, Memberships & Payments (incl. offline-claim queue), Taxonomies
(categories, offerings with SEO fields), Meta Fields (farm + listing groups), Settings, Integrations, Analytics (last). Every table uses the shared
`SP.table` component with server-side search/sort/pagination over REST. Bulk export/import CSV with downloadable templates for Users, Farms,
Listings, Memberships; imports are dry-run first and report row-level errors. Bulk actions: extend terms, set status, assign owner, send email.

---

## 10. Analytics

Events captured client/server-side: farm views, listing views, searches (params), contacts, favorites, shares, logins, signups, listing
publishes, payments. Rollups power: membership counts over time, new vs renewals, revenue by month, listing volume, posting frequency,
top farms/listings, active vs dormant members (no login/listing in N days), funnel (visits → signups → memberships). Exportable to CSV. Built last.

---

## 11. Migration from the RTCL plugins (see `research/04-data-inventory-and-migration.md`)

Order: users → `sp_members`; stores → `sp_farms` (+ owners, address parsing, geocoding, logos/banners); RTCL memberships/payments →
`sp_memberships`/`sp_payments` (status by expiry date; source `migrated`); listings → `sp_listing` (category map, price, seller name, images,
state); listing stats → `sp_stats_daily`; redirect map from `research/rtcl-urls.csv`. Scripts under `temp/scripts/migrate-*.php` with
`--dry-run`, run against Local first, review reports, then production during cutover.

---

## 12. Build phases (detail in `03-build-todo.md`)

| Phase | Outcome |
|---|---|
| 0. Foundation | Plugin skeleton, migrations, settings, roles, router, assets, UI kit, mailer, cron, REST base, logging. Nothing member-facing yet but everything testable. |
| 1. Accounts & memberships | Login/register/reset, dashboard shell, account details, farm users/managers, memberships, Stripe, offline claims, manage-membership page, reminder emails. |
| 2. Farms & directory | Farm editor, media, meta fields, offerings, geocoding, public farm page, directory with live filters/map/shortcode, contact form, favorites (farms). |
| 3. Classifieds | CPT/taxonomy/meta groups, listing editor with limits, lifecycle cron, archive + category pages, single page, contact seller, favorites (listings), saved searches + alerts, share. |
| 4. Admin | All admin screens, tables, bulk actions, CSV import/export, integrations page, taxonomy/meta management. |
| 5. Migration & cutover | Importers, redirects, parity checks, retire RTCL + child-theme code, staging rehearsal, production cutover. |
| 6. Analytics, help, launch | Analytics page, help/FAQ content, rollout plan, training, QA sweep, launch. |

Each phase ends with a demo-able state on Local and a log entry in `06-build-log.md`.
