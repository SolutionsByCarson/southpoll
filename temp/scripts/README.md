# Scripts

Repeatable tooling for the South Poll build. All scripts run against the **Local** copy of the site; never point them at production.

## Runner

- `wp-run.sh <script.php> [args]` — runs a PHP script with WordPress fully loaded (theme + plugins), using Local's PHP 8.2 and this site's
  `php.ini` (MySQL on port 10011). Run from Git Bash:

  ```bash
  cd "/c/Users/Carson/Local Sites/south-poll-grass-cattle-association/app/public/temp/scripts"
  ./wp-run.sh ping.php
  ```

- `wp-run.php` — the bootstrap used by `wp-run.sh` (sets `HTTP_HOST`, loads `wp-load.php`, includes the target script). Scripts get `$argv`,
  `$wpdb`, `rtcl()`, `rtclStore()`, and all WP functions. Define `SP_WP_RUN` is set so scripts can refuse to run under the web server.

If Local's PHP version or the site's run-dir id changes, update `PHP_BIN` and `PHP_INI` at the top of `wp-run.sh`
(find the run dir under `%APPDATA%\Local\run\*\conf\php\php.ini` by its `mysqli.default_port`).

## Reports (read-only; write Markdown to stdout)

| Script | Output file | Content |
|---|---|---|
| `ping.php` | — | Sanity check: WP version, home URL, theme, table count, RTCL loaded |
| `report-inventory.php` | `../research/db-inventory-raw.md` | Site options, users/roles, posts by type, RTCL tables, memberships, taxonomies, pricing, listing/store/payment meta, all `rtcl_*` options (secrets masked), pages + shortcodes, menus, GF forms, cron, theme/Cornerstone design options, widgets, popups, redirects |
| `report-forms-subs.php` | `../research/db-forms-subs-raw.md` | Form-builder listing form fields, `rtcl_subscriptions` schema/status, listing stats keys, roles/caps, user/member cross-checks, listing owner checks, store samples |
| `report-theme-gf.php` | `../research/db-theme-gf-raw.md` | `x_*` design option values, site identity, Cornerstone header/footer/templates, colors/fonts used in builder pages, GF field labels, Join/FAQ/Members/Classifieds page text |
| `report-payments-detail.php` | `../research/db-payments-detail-raw.md` | Subscription id types, payments by gateway×pricing×year, pre-paid order origins, renewal patterns, member profile meta coverage, import CSV headers |
| `report-renewals.php` | `../research/db-renewals-raw.md` | Whether Stripe recurring charges ever created WP orders; repeat-payer gaps; abandoned orders |
| `report-rtcl-urls.php` | `../research/08-rtcl-urls-and-endpoints.md` + `../research/rtcl-urls.csv` (written directly) | Every RTCL URL/endpoint: pages, account/checkout endpoints, archives, all listing + farm URLs with old slugs, API routes, cached rewrite rules, stale 404 paths, existing 301s — for the cutover redirect map |

Regenerate with e.g. `./wp-run.sh report-inventory.php > ../research/db-inventory-raw.md`.

## Plugin tooling

| Script | Usage | Purpose |
|---|---|---|
| `sp-plugin.php` | `./wp-run.sh sp-plugin.php lint\|activate\|deactivate\|status\|migrate\|context <uid>` | Lint every plugin file, toggle activation, show tables/cron/roles/settings/integrations readiness, force migrations, dump a user's permission matrix |
| `plugins-toggle.php` | `./wp-run.sh plugins-toggle.php activate\|deactivate\|status` | Toggle the legacy Classified Listing plugin family |
| `contrast-check.php` | `php contrast-check.php [--shipped]` (plain PHP, no WordPress) | WCAG contrast audit of the plugin's colour pairs; exits 1 if any shipped pair fails. Re-run after touching `sp-tokens.css`. |
| `sp-integrations.php` | `./wp-run.sh sp-integrations.php list \| set <key> <value> \| setting <key> <value>` | Read/write integration credentials (Stripe, Google, reCAPTCHA, email) and plugin settings from the CLI. Since Phase 4 the same values are editable on SouthPoll → Integrations / Settings in wp-admin. Values are masked on output. |
| `flush-rewrites.php` | `./wp-run.sh flush-rewrites.php` | Re-register the plugin's rewrite rules and flush WordPress's rule cache (run after adding a route; migrations request it automatically) |
| `report-listing-classes.php` | `./wp-run.sh report-listing-classes.php` | Read-only: listing categories + offerings with counts, migrated listings by new × old (RTCL) category, non-migrated listings by category, Females whose old category is unknown (titles) — the S5 planning / verification report |
| `recategorize-listings.php` | `./wp-run.sh recategorize-listings.php [--dry-run]` | S5 (2026-11-18): listing classes → Bulls, Cows, Heifers, Steers, Semen. Feeders renamed Steers (id kept), Cows / Heifers created, Females split by the old-site category then by title keywords (review list printed), Finishers → Steers, Calves by keyword; farm offerings converted alike (Females → Cows + Heifers, Calves → Steers + Heifers); empty old terms deleted; saved-search `cats` rewritten; exact 301s for `/classifieds/females|feeders|finishers|calves/`. Report in `temp/migration/reports/recategorize-*.md`. Take `backup-db.php` first; then run `migrate-redirects.php` to refresh the legacy category rows. Idempotent |
| `update-farm-fields.php` | `./wp-run.sh update-farm-fields.php [--dry-run]` | S4 (2026-11-18): farm page fields → Primary Forage Type, Cover Crops, Supplemental Feed, Hay Type (new), Grazing Style, Additional Info; keys and stored values kept, Cool Season / Warm Seasons set inactive. Source list `Core\Seeder::DEFAULT_FARM_FIELDS`. Idempotent; prints before / changes / after tables. Run once on each environment after the push |

> `php` is not on the Git Bash PATH. For the plain-PHP scripts (`contrast-check.php`, `js-balance-check.php`) call Local's binary
> directly: `"/c/Users/Carson/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" -n js-balance-check.php`.

## Test suites (Local only; create/update throwaway `@example.test` accounts)

| Script | Covers |
|---|---|
| `test-phase1-auth.php` | Registration (buyer/member/farm shell/breeder number), verification, validation, lockout, password reset, invitations, email change |
| `test-phase1-render.php` | Dashboard sections rendered in-process for owner/buyer, REST via `rest_do_request`, admin Memberships page |
| `test-phase1-payments.php` | Stripe checkout/fulfilment/webhooks/auto-renew/portal and offline claims against a fake transport (no keys needed) |
| `test-phase1-cron.php` | Membership expiry, before/after reminders, recurring notice, idempotency, restore on renewal; send window + queued notices + queue worker rebuild (T6.9); registry digest build / run / schedule / slot math (T6.8). Drains the notification queue after every cron run, parks other users' pending rows while it runs, and clears the send window for the duration (restored at the end) |
| `test-phase2-farms.php` | Schema 0.3.0 + offerings seed, farm editor validation/save, geocoder (fake transport) + backfill, logo/gallery uploads via REST, directory query filters/sort/pins, directory REST + guest pin precision, farm page render states (guest/buyer/owner/hidden/404), favorites REST + dashboard card, inquiries (honeypot, fill time, validation, disabled form, admin copy, rate limit), `[member-directory]`, My Farm editor render, admin Meta Fields, membership gating hooks |
| `test-phase3-listings.php` | Listing types + seeds, editor validation/publish, limits, sold window, renew, rename 301 data, duplicate/delete, archive query + filters + pins, directory integration, public REST + view counting, my-listings REST (featured/gallery media), page renders (archive, category, single, unavailable, shortcode, dashboard screens), saved searches (dedupe, default, share), listing favorites + shared list, alert queue + throttle + unsubscribe, listing cron, membership freeze/restore, field groups, listing inquiries |
| `test-phase4-admin.php` | Admin area: staff permissions (guest/buyer/editor/admin), every wp-admin screen rendered in-process, admin REST (`/sp/v1/admin/*`: stats, users incl. drawer + actions, farms incl. PATCH/bulk, listings bulk, memberships actions, payments, claims approve/reject, tiers, taxonomies, settings, integrations), the classic Settings / Integrations / Claims forms (redirects and `wp_die` are turned into exceptions), CSV export / template / parse / dry-run / import for users, farms, memberships, listings, the farm-owner delete guard. Creates and removes an editor account, a CSV buyer + farm, listings, a tier and taxonomy terms; restores every setting / integration it touches |
| `test-cleanup.php [--yes]` | Lists (or deletes) all test accounts, their plugin rows, offerings relationships, their farms' listings, queued alerts and plugin-uploaded attachments |
| `seed-sample-listings.php [--delete]` | Creates six "Sample:" listings (images, a video, varied prices/statuses/dates) on the newest test owner's farm so the classifieds can be browsed on Local; `--delete` removes them |
| `seed-demo-accounts.php [--password=…] [--days-left=10]` / `--delete` | Two ready-to-use member logins: `buyer@example.test` (login `demo-buyer`, verified buyer with favorite farms + listings and three saved searches, "Missouri bulls" as the default) and `owner@example.test` (login `demo-owner`, owner of "Rivera Family Farm" with logo / banner / 4-photo gallery and 7 listings incl. one draft; membership paid by check, **not** recurring and expiring in `--days-left` days so the countdown notice and the renewal workflow show). Prints the credentials once; the password is never written to disk. Shares `lib/demo-lib.php` with the farm seeder. |
| `lib/demo-lib.php` | (library, not run directly) Media pools from the library, downscaled image copies, listing templates, farm / membership / listing / delete helpers used by both demo seeders. |
| `seed-demo-data.php [--delete]` | Populates Local with 14 fictional farms (owner accounts `sp-demo-*@example.test`, addresses + map pins across the South Poll states, slogans, about text, hours, social links, offerings, farm meta) in every membership state the admin screens show (active, auto-renew, expiring in 12 days, expired, pending offline claim) with payment history, plus ~50 listings (all categories and statuses, staggered dates, view counts, one draft, some expired/sold). Logos, banners and photos are downscaled **copies** of the old store logos/banners and classified photos already in the media library, attached to the demo farms, so `--delete` removes only the copies. Refuses to run while demo accounts exist; `test-cleanup.php` also lists them. Takes about a minute. |
| `js-balance-check.php` | `php js-balance-check.php` (plain PHP) — bracket-balance guard for `assets/js/*.js` since Node is not installed locally |

Run order: auth → render/payments/cron → phase2 → phase3 → phase4 (they share the test owner; directory assertions are scoped to the test farm with `q` because Local also holds the 729 migrated farms; the Phase 1 render suite resets that farm's membership to "none", the Phase 2/3/4 suites re-activate it for a year so the farm can be browsed afterwards; the Phase 3 and 4 suites delete every listing on that farm, including the samples — run `seed-sample-listings.php` again for browsable content). Each suite exits 1 on failure.

## Migration (Phase 5) — Local rehearsal only until the cutover is scheduled

All scripts take `--dry-run` (report only, nothing written) and `--limit=N`; real runs write a Markdown report to `temp/migration/reports/`
and record what they created in the `sp_migration_log` option so a Local run can be undone. They never send mail and never delete RTCL data.
Order: users → farms → memberships → listings → redirects → verify (`./migrate-all.sh [--dry-run]` runs them in sequence).

| Script | Purpose |
|---|---|
| `lib/migrate-lib.php` | Shared: options, report writer, undo log (also flushed on shutdown, so a crash never loses it), free-text address parser (street / city / state / zip + confidence; handles "Vernon Al", "Munford Al.36268", leading 5-digit house numbers, "County Road 3440"), state + category maps, RTCL term lookups by SQL (the taxonomies are unregistered while the legacy plugins are inactive), attachment adoption (tags + rebuilds plugin image sizes in place) |
| `migrate-users.php` | Every user → `sp_members` profile (name, phone, address; state from `_rtcl_location` → `_billing_state`, with the bare "CA" default ignored; values cut to column length; an existing profile only gets its blanks filled); subscribers → `sp_buyer`, `rtcl_manager` → editor; verified = had a membership or ever logged in; staff untouched; flags `spam-name`, `no-name`, `duplicate-email`, `profile-failed` |
| `migrate-farms.php [--no-media]` | `store` posts → `sp_farms` (slug + legacy_store_id kept, slogan/about/phone/email/website/social, address parsed and flagged for review, logo/banner adopted) + a minimal farm for every member without a store |
| `migrate-memberships.php [--include-abandoned]` | `wp_rtcl_membership` → `sp_memberships` (active/expired by expiry date, source `migrated`, auto-renew off, Stripe customer id kept) and `rtcl_payment` orders → `sp_payments` (Stripe ids, `legacy_payment_id`, "store" gateway = pre-paid) |
| `migrate-listings.php [--no-media]` | `rtcl_listing` → `sp_listing` (category map Bulls→bulls, Cows/Heifers→females, Steers→feeders (flagged), Semen→semen; price + label; sold flag; expiry carried; photos adopted; old slug preserved; views + `rtcl_listing_stats` → `sp_stats_daily`; seller/business names kept as `_sp_legacy_*` meta) |
| `migrate-redirects.php [--test]` | Fills `new_url` for every row of `research/rtcl-urls.csv` → `temp/migration/redirect-map.csv`, loads exact rows into `sp_redirects` (paths normalised like the resolver; the first row for a path wins, so a farm beats a logged 404; RTCL `rtcl-auto-temp-*` placeholder slugs are skipped); `--test` resolves each old path through `Routing\Redirects` and checks the target exists. The front-end 301s only start once the `legacy_redirects_enabled` setting is on (SouthPoll → Settings → Advanced; cutover step) |
| `migrate-verify.php` | Parity checklist: old vs new counts (members, farms, memberships, payments + revenue for orders that belong to a membership — orphan test orders are listed separately, listings, images, stats, exact redirect paths), random spot checks, review lists (missing state/city, entities, geocoding backlog) |
| `migrate-reset.php --yes` | Undo a Local run from the log: migrated listings/farms/memberships/payments/profiles removed, adopted media untagged (never deleted), roles restored, `sp_migrated` flags cleared, redirects cleared. Note: a membership row that already existed for a migrated owner's farm is logged by `ensure()` and therefore removed too (only matters on Local) |
| `migrate-all.sh [--dry-run]` | Runs the five migrators in order, then verify; stops at the first failing step |
| `backup-db.php [--label=…]` | Dumps the database with Local's `mysqldump` to `app/backups/<stamp>-<label>.sql.gz` (outside the web root; password passed via `MYSQL_PWD`, never printed). Run before a cutover or reset |
| `cutover-local.php [--dry-run\|--undo]` | The database side of the cutover on this site: builder-page shortcodes swapped, Join button → registration, old My Account / Checkout / Submit a Listing pages drafted, menus (mobile My Account → login, "Member Login" added), `legacy_redirects_enabled` on, 301-plugin rule removed, customizer `.rtcl` rule dropped, Stripe mode test + stray live key cleared, rewrites flushed. Idempotent; writes an undo record (`sp_cutover_log`). Refuses to run while the Classified Listing plugins are active |
| `purge-spam-users.php [--delete] [--ids=…] [--quiet]` | Finds spam registrations and (with `--delete`) removes them through `wp_delete_user()`. Candidates = accounts registered after the 2025-03-11 member import with zero engagement (no membership, farm, store, real 3–6-digit breeder number, listing, order, favorite, saved search, inquiry or plugin login). Spam = machine-generated name (random case, capitals inside words, no vowels, 15+ letter tokens, spam words, non-Latin script) or junk in the breeder-number field; a random *login* alone never qualifies. Dry run prints SPAM / REVIEW / KEEP lists; `--delete` writes a CSV of every deleted row to `app/backups/` first. Classifier in `lib/spam-classify.php`. Take `backup-db.php` first |
| `sync-registry-terms.php [--file=…]… [--dry-run] [--create-missing] [--limit=N]` | S14 (2026-11-18): membership terms from the office's registry export (`MemAddressExp*.csv`, default = the two files in `app/public/`). Match e-mail → farm e-mail → breeder number (farm / user meta) → unique person name → unique farm name; minimal farm for farm-less accounts; `ends_at` = exp_date 23:59:59 site time, `starts_at` = Jan 1 of that year when empty, status by date, tier default when empty, source `registry`, note; breeder number written when missing; JR → farm meta `membership_class=junior`; memberships that flip to expired hide their listings. No digest events fired. Unmatched → `temp/migration/reports/registry-unmatched-<stamp>.csv`; `--create-missing` creates `sp_buyer` accounts (unverified, no e-mail) + farms for unmatched rows with an e-mail. Idempotent (second run = `unchanged`). Report `registry-sync-<stamp>[-dry].md`. Take `backup-db.php` first |
| `geocode-backfill.php [--batch=100] [--max=2000]` | Geocodes every active farm with an address but no coordinates (same code as Status → Geocode), loops until none are left, lists unresolved addresses |

Cutover: executed on **Local** on 2026-10-27 (`temp/migration/06-staging-push.md`); production plan `temp/migration/05-cutover-runbook.md`.

## Conventions for new scripts

- One purpose per file; name `report-*.php` for read-only, `migrate-*.php` for data changes (migrations must support `--dry-run` and print counts).
- Never echo secrets; reuse `mask_secrets()` pattern from `report-inventory.php`.
- Prefer `$wpdb->prepare`. Keep output Markdown so it can be pasted into the knowledge base.
