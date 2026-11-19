# Classified Listing Plugin Family — Analysis (what we are replacing)

Surveyed 2026-10-18. Three RadiusTheme plugins, all licensed and active:

| Plugin | Version | Namespace / bootstrap | Size |
|---|---|---|---|
| Classified Listing (core) | 6.1.5 | `Rtcl\…`, `rtcl()` singleton, `app/Rtcl.php` | 761 PHP files, 181 templates |
| Classified Listing Pro | 4.2.6 | `RtclPro\…`, `app/RtclPro.php` | 694 PHP files (incl. Stripe SDK, Guzzle, Pusher) |
| Classified Listing Store | 3.2.1 | `RtclStore\…`, `rtclStore()`, `app/RtclStore.php` | 92 PHP files, 32 templates |

No vendor plugin files appear hand-patched (only sample-data/language files are newer than the plugin folder date).
All South Poll customizations live in the child theme and mu-plugins (inventory below).

## 1. Data model

### Post types

| Post type | Meaning | Count (status) |
|---|---|---|
| `rtcl_listing` | Classified listing | 91 publish, 10 rtcl-expired |
| `store` | Farm page (one per member, owner via meta) | 250 publish |
| `rtcl_payment` | Order / payment record | 966 completed, 30 created, 2 failed |
| `rtcl_pricing` | Pricing product (membership plans) | 2 |
| `rtcl_cfg` / `rtcl_cf` | Legacy custom field groups/fields | 0 (form builder used instead) |

Custom post statuses: `rtcl-expired`, `rtcl-pending`, `rtcl-reviewed`, `rtcl-temp` (listings); `rtcl-created`, `rtcl-completed`,
`rtcl-failed`, `rtcl-cancelled`, `rtcl-refunded`, `rtcl-on-hold`, `rtcl-processing` (payments).

### Taxonomies

| Taxonomy | Values in use |
|---|---|
| `rtcl_category` (listing) | Bulls [6] 41, Cows [13] 25, Heifers [5] 17, Steers [14] 6, Semen [7] 2 (flat, no children) |
| `rtcl_location` (listing) | 50 US states, single level (location type `local`, labels State/City/Town) |
| `rtcl_tag` | 49 tags |
| `store_category` | one term "South" [4] on 107 stores (meaningless) |

### Custom tables (schema in `classified-listing-store/app/Helpers/Install.php`, core `Helpers/Installer.php`)

- `wp_rtcl_membership (id, user_id UNIQUE, ads, posted_ads, expiry_date, member_since, active)` — one row per user.
- `wp_rtcl_membership_meta` — keys `membership_categories` (allowed category ids, 5 per member) and `_rtcl_order_ids` (serialized order ids).
- `wp_rtcl_membership_posting_log (membership_id, action submission|promotion, listing_id, cat_id, promotion_type, …)`.
- `wp_rtcl_posting_log (post_id, user_id, cat_id, status new|renew, created_at)` — free-ad log.
- `wp_rtcl_subscriptions (user_id, name, sub_id, occurrence, gateway_id, status, product_id, quantity, price, meta, expiry_at, created_at, updated_at)` + `_meta` (`cc` → type/last4/expiry).
- `wp_rtcl_listing_stats (listing_id, stat_date, stat_key view|reveal|phone_click, stat_count)`.
- `wp_rtcl_forms` — form builder definitions (JSON `fields`, `sections`).
- `wp_rtcl_sessions` — cart/session storage.

### Listing meta (form builder form #1 "Sample Form", 4 sections)

| Field (label) | meta key / element | Required |
|---|---|---|
| Listing type | `ad_type` (`sell` 100, `exchange` 1; options Sell/Buy/Exchange) | yes |
| Category | taxonomy `rtcl_category` (single) | yes |
| Title | `post_title` | yes (2–255) |
| Pricing | `_rtcl_listing_pricing` (price 91 / range 6 / disabled 4), `price_type` (fixed 79 / negotiable 22), `price`, `_rtcl_max_price` | no |
| Description | `post_content` (wp_editor) | yes |
| Tag | `rtcl_tag` | no |
| Seller Name | `text_m4c7c84b` (custom text) | yes |
| Business Name | `text_m4c3zlip` (custom text; 82/101 filled) | no |
| Location | taxonomy `rtcl_location` (state) | yes |
| Phone | `phone` | yes |
| Email | `email` | yes |
| Images | attachments with `post_parent` = listing, stored under `uploads/classified-listing/`; `images` meta = count; max 5, 10 MB, jpg/jpeg/png/webp; `_thumbnail_id` | no |
| Video URLs | `_rtcl_video_urls` (never used) | no |

System meta: `expiry_date`, `deletion_date`, `_rtcl_mark_as_sold` (47 sold), `_views`, `_rtcl_reveal_phone_whatsapp`, `_rtcl_phone_click`,
`_rtcl_publish_count`, `_rtcl_form_id`=1, rating/review meta (unused), `_wp_old_slug` (`rtcl-auto-temp` → listings are created as temp posts then renamed).

### Store (farm page) meta

`store_owner_id` (user id; 250/250), `address` (free text, 206), `phone`, `email`, `whatsapp`, `website` (49 non-empty), `slogan`, `social_media`
(serialized map: facebook, youtube, instagram, …; 61 non-empty), `logo_id` (184), `banner_id` (136), `oh_type` / `oh_hours` (opening hours — irrelevant),
`_rtcl_manager_ids` (0 stores). `post_content` = farm description (68 non-empty, avg 364 chars). `post_title` = farm name, `post_name` = slug.
Image sizes: banner 992×300 crop, logo 200×150 crop.

### User meta (RTCL + South Poll)

`_rtcl_phone` (9,660), `southpoll_breeder_number` (6,495 users; 215 of 729 members), `_rtcl_address`, `_rtcl_location` (serialized term id),
`_rtcl_zipcode`, `_rtcl_website`, `_rtcl_social`, `_rtcl_whatsapp_number`, `_rtcl_telegram`, `_rtcl_display_*_public`, `_rtcl_user_type`,
`_rtcl_ads`, `_rtcl_pp_id`, `wp__stripe_customer_id` (546), `_billing_*` (first/last/company/email/country/state/city/address_1/postcode/phone),
`online_status`, `_rtcl_store_id` (store managers).

### Payment (`rtcl_payment`) meta

`customer_id`, `_pricing_id`, `amount`, `_subtotal`, `_tax_amount`, `_payment_method` (stripe|store), `_payment_method_title`, `_order_currency`,
`_order_key`, `payment_type` (=membership for all 998), `date_paid`, `_applied` (membership applied flag), `transaction_id` (`ch_…`), `_billing_*`,
`_stripe_customer_id`, `_stripe_intent_id`, `_stripe_charge_captured`, `_stripe_fee`, `_stripe_net`, `_stripe_currency`, `customer_ip_address`.

### Pricing products

| ID | Title | Type | Price | Term | Ads | Categories | Stripe |
|---|---|---|---|---|---|---|---|
| 847 | Online Membership | membership | $100 | 365 days (`visible`) | 100 regular ads | all 5 | `_stripe_product_id`, `_stripe_price_id` set |
| 639 | Membership (Pre-paid) | membership | $0 | 365 days | 100 | all 5 | — |

## 2. Settings in force (abridged; full dump in `db-inventory-raw.md`)

- **General/moderation**: listings per page 20, default view list, listing duration **90 days**, renew yes, `delete_expired_listings` **15 days**
  (plus 3-day reminder threshold → deleted ~18 days after expiry), new/edited listing status **publish** (no moderation, despite FAQ text),
  max images per listing 5, favourites off, report abuse off, **contact form off** (`has_contact_form` no), comment form on (but comments disabled by theme),
  review/rating off, map off, sidebar left, quick view on, compare off, mark-as-sold on, text editor wp_editor, title/description limits none.
- **Membership (`rtcl_membership_settings`)**: enabled; store enabled; `enable_store_only_membership` yes; `display_store_only_valid_membership` yes
  (expired member's farm page shows "This store is unavailable…"); store manager feature on; free ads enabled with unlimited free ads for members
  (`unlimited_free_ads_membership` yes, `number_of_free_ads` 0 for non-members); `expiry_republish_with_subscription` = independent
  (listing expiry independent of membership expiry); new store status publish; store rating off.
- **Account**: my-account registration enabled; registration/listing/checkout T&C checkboxes on but no T&C page set; user verification off;
  posting for unregistered users off; role subscriber.
- **Payments**: enabled, USD, Stripe enabled (live mode; test secret empty; capture yes; saved cards yes; receipt email yes; logging yes;
  webhook secret empty → webhook validated by User-Agent only; `subscription` setting empty/off). PayPal configured with api_username but not enabled; Authorize.Net empty; offline disabled.
- **Email**: HTML; notify admin on register_new_user, listing_submitted, order_created, order_completed; notify users on register_new_user,
  listing_submitted, listing_published, listing_renewal, listing_expired, remind_renewal, order_created, order_completed. Renewal thresholds 3 days.
- **Style**: primary `#8d183c`, link `#111111`, link hover `#8d183c`, button `#8d183c`/white.
- **Media**: gallery 760×462, thumb 320×240, gallery thumb 150×105; allowed png/jpg/jpeg, 2 MB. reCAPTCHA v3 on login/registration.
- **Filter widget** "Classified Filter": keyword, category (checkbox), location (checkbox) — used in `rtcl-archive-sidebar`.
- **Endpoints**: see `02-site-architecture.md`.

## 3. Current user flows (as built)

1. **Register** — RTCL registration form on `/my-account/` (name, email, phone optional, password, reCAPTCHA v3, T&C) + child-theme "Breeder Number (optional)".
   Emails: user welcome + admin "new registration".
2. **Dashboard** — `/my-account/` shows "Membership Report" widget (status, validity date, remaining/posted ads, free ads) and nav:
   Dashboard · My Listings · Farm (my-store) · Payments · Account Details · Privacy settings · Add Listing · Logout.
3. **Buy membership** — `/checkout/membership/` lists both pricing cards: "Online Membership $100" (Stripe card form) and "Membership (Pre-paid) $0".
   Choosing $0 completes instantly through the internal `store` gateway ("honor system"; FAQ says SPGCA may revoke). On completion:
   `Functions::apply_membership()` inserts/updates `wp_rtcl_membership` (new: expiry = now + 365d; renewal while active: expiry += 365d, ads += 100;
   renewal after expiry: expiry = now + 365d), stores order id in membership meta, fires `rtcl_membership_order_completed`; custom admin email with
   breeder number goes to info@ + registry@.
4. **Farm page** — `/my-account/my-store/`: name, slug, category, banner, logo, slogan, details, address, phone, whatsapp, email, website, opening hours,
   social links. Saved by the mu-plugin's native POST handler (plugin AJAX was 404ing). Public page `/store/{slug}/` shows banner/logo/name, slogan,
   description, "All ads from {farm}", sidebar info (hours, address, phone, website, social, email) — hidden entirely when membership expired.
5. **Directory** — `/members/` uses child-theme `[member_grid]`: WP_Query on `store` (20/page), search by farm name or owner first/last/display/email,
   state filter by REGEXP on the free-text `address` meta (abbreviation or full name), region filter (East/South/Midwest/West lists), 4/3/2/1-column card
   grid, `console.log` of query args in page source, placeholder image fallback. Does **not** check membership status (expired members still listed).
6. **Submit listing** — `/submit-a-listing/` (form builder): membership check (`verify_membership_before_category` notices), category, title, pricing,
   description, tag, seller name, business name, state, phone, email, up to 5 images. Publishes immediately; expiry = +90 days; posting log entry;
   emails: owner "received"/"published", admin "submitted".
7. **Manage listings** — `/my-account/listings/`: table with views/clicks, actions edit / mark sold / renew / delete (three-dot menu). Renew allowed for members.
8. **Expiry** — hourly cron: `expiry_date` passed → status `rtcl-expired`, `deletion_date` = +18d, owner+admin "expired" email; 3 days before expiry owner gets
   renewal email; expired listings are **permanently deleted** after `deletion_date` (10 currently expired, 13 have deletion dates).
9. **Public listing page** — `/rtcl_listing/{slug}/`: gallery, price, description, custom fields, seller box (name, phone with reveal + click tracking,
   email obfuscated with "click to reveal" from child theme), related listings. No contact form; no favourites; social share on.
10. **Membership expiry** — computed on the fly (`Membership::is_expired()`); no cron, no email, no status change; farm page becomes unavailable; new listings
    blocked; existing published listings keep running until their own 90-day expiry (6 published listings currently belong to expired members).

## 4. Stripe integration (Pro)

- Gateway class `RtclPro\Gateways\Stripe\GatewayStripe` (PaymentIntents, saved cards, 3DS optional). Keys in `rtcl_payment_stripe` (masked).
- Webhook endpoint: `/?rtcl-api=rtcl_gateway_stripe`. **No webhook secret configured** → validation falls back to a User-Agent check
  (`validate_request_user_agent`). `rtcl_stripe_wh_last_error` = `validation_succeeded`, but last success/failure timestamps are 0, so it is unclear
  whether Stripe is actually configured to send events.
- **Subscriptions**: `wp_rtcl_subscriptions` holds 499 rows, all with real Stripe `sub_…` ids, 493 active / 6 canceled, created 2025-03 → 2026-06-12,
  `product_id` 847, price 100, `occurrence` 1, `expiry_at` zero. Pricing 847 carries `_stripe_product_id`/`_stripe_price_id`. The code creates a Stripe
  Subscription after a completed membership order when `_rtcl_stripe_subscription_pending` is set (`StripePaymentGateway.php:680`,
  `GatewayStripe::create_membership_pending`). Yet **no WordPress order has `_rtcl_recurring`**, every completed Stripe payment has a PaymentIntent and a
  customer IP (user-initiated checkout), and only 2 of 50 repeat payers renewed at ~365 days. Conclusion: Stripe subscription objects were created but no
  renewal has been reflected in WordPress. Whether Stripe is silently charging (or failing to charge) those subscriptions annually must be checked in the
  Stripe Dashboard before migration (**Q-01**). Subscription creation stopped after 2026-06-12.
- Stripe logs (`uploads/rtcl-logs/`): mostly payment_method debug dumps; 50 `invalid_request_error` entries; `wp_mail_failed 401` errors.
- Revenue recorded in WP: 2025 $30,301 (303 × $100 + $1 test), 2026 $27,300 (273 × $100). 377 pre-paid $0 orders (257 in 2025, 120 in 2026).

## 5. Emails that exist today

Core: contact, listing contact (owner/admin), listing expired (owner/admin), moderation, published, renewal, renewal reminder, submitted (owner/admin),
updated (admin), order created/completed (customer/admin), report abuse, user import, new registration (user/admin), reset password.
Pro: subscription activated/cancelled, unread message, verify link. Store: store contact to owner, store manager invitation, store update to admin.
Custom: `Southpoll_Membership_Purchase_Email` → info@ + registry@ with breeder number ("please add them to the cattle registry for this fiscal year").
Missing vs proposal: membership renewal reminder, membership expired (member + admin), membership renewed (admin), payment failure (admin), buyer inquiry.

## 6. South Poll customization inventory (everything that must be re-implemented or retired)

| Where | What | Replace with |
|---|---|---|
| child `functions.php` | `[member_grid]` directory | Plugin directory (REQ-M2-03) with structured state field |
| child `functions.php` | `[classifieds_search]` | Plugin listing search (REQ-M3-04) |
| child `functions.php` | gettext Store→Farm | Native "Farm" labels |
| child `functions.php` | breeder number on register/edit + admin email | Plugin member field + REQ-M5-08 email (keep breeder number) |
| child `functions.php` | seller email reveal on listing page | REQ-M3-08 contact relay (hide email) |
| child `functions.php` | hide admin bar for subscribers; disable comments | Plugin/role setting |
| child templates | listing/store template overrides | Plugin templates |
| mu `southpoll-store-save.php` | farm save fallback | Native plugin save |
| mu `zzz-southpoll-debug.php` | 404 logger | Delete |
| 301 Redirects | malformed add-listing URL rule | Delete after launch |
| Customizer CSS | `.rtcl-listing-sidebar-info-wrap` margin | Delete |
| Cornerstone pages 71, 49 | shortcodes embedded in builder pages | Swap to plugin shortcodes/blocks |
| Cornerstone archive layout 659 | archive for `rtcl_listing` | Not needed (plugin renders its own) or re-assign |

## 7. Known defects and pain points (evidence-based)

1. Farm "Update Store" AJAX save returned 404 on production (2026-07) → mu-plugin workaround.
2. Dashboard "Add Listing" link once rendered `/my-account/https://southpoll.com/submit-a-listing/` (927 redirect hits) — fixed upstream 2026-07-07.
3. FAQ page links to `my-account/...` relatively → hundreds of 404s under `/website-faq/my-account/...`.
4. FAQ promises a 1–3 day review, but listings publish instantly.
5. Expired listings are hard-deleted ~18 days after expiry (data loss; no "sold/archived" retention).
6. Directory filters parse free-text addresses with REGEXP; no structured state; expired members remain listed; debug `console.log` in page.
7. Pre-paid $0 membership is self-service (honor system) — anyone can activate a membership without paying.
8. No membership expiry/renewal emails; members discover expiry only by failing to post.
9. Seller email and phone exposed on listing pages (phone reveal counted, email revealed via JS).
10. Email template colors are RTCL default blue, not brand.
11. 499 Stripe subscription objects of unknown behavior (see §4).
12. ~9,200 of 9,967 users were bulk-imported (March–November 2025) and most have never logged in (2,542 users have session tokens).
13. Breed-irrelevant features exposed: opening hours, WhatsApp, Telegram, store categories ("South"), store managers, listing types Buy/Exchange, tags.
14. `rtcl_manager` role has `manage_rtcl_options` (full plugin admin) — two staff users.
15. 5,906 users have a breeder number but no membership row; 316 active members have no farm page.

## 8. Feature parity: proposal vs current

| Proposal | Current RTCL state | Gap |
|---|---|---|
| M1 accounts | RTCL forms; breeder number; roles subscriber/rtcl_manager | Rebuild forms; add pending/expiring statuses; site-manager role |
| M1 term/price config | pricing CPT (365d/$100) | Settings screen |
| M1 auto-expire + prompts | expiry computed; no prompts | Cron + emails |
| M2 farm pages | `store` CPT with meta | New CPT/meta; drop hours/whatsapp; add herd description, multiple photos |
| M2 directory | child shortcode, address regex | Structured state; membership-aware |
| M2 listings on farm page | yes (`ad-listing.php`) | Keep |
| M3 submission | form builder, publish immediately | Rebuild; categories per discovery |
| M3 lifecycle | publish/expired/sold/deleted | Add draft/removed; stop hard delete |
| M3 lapse → unpublish/restore | independent expiry | New sync logic |
| M3 buyer contact relay | none (reveal) | New |
| M4 Stripe | PaymentIntents + stray subscriptions | New Checkout/PaymentIntents + signed webhooks; decide on subscriptions |
| M4 offline payment | $0 self-service pricing | Admin-only action |
| M5 emails | partial | Full inventory |
| M6 admin | WP list tables + custom tables | Member screen, bulk actions, CSV |
| M7 reporting | listing stats table only | Dashboard + events |
| Migration | — | Map users/memberships/stores/listings (see `04`) |
