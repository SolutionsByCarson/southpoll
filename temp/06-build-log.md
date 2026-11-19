# Build Log (append-only, newest first)

## 2026-11-18 — Session 13: soft-launch Block A (S1–S10) built on Local; Block B walk-through + registry script

- Carson's brief: work `08-soft-launch-plan.md` in order, Block A without stopping, then the Block B walk-through and the
  credential list. Mid-session Carson added: this Local site is a Local (WP Engine) Connect site attached to staging **and**
  production; pulling users from live brings the spam accounts back; **the launch is a direct push from Local to production** once
  everything is ready (recorded in the plan §1 "Carson's additions"; S13/S15 re-scoped accordingly).
- Built (details, touchpoints and test counts per step in `08-soft-launch-plan.md` §3; todo ticked):
  - **S1** draft UX: never-published listings read "Draft"; non-live listings get a read-only state line instead of the status
    select and publish as Available; disabled Publish shows the reason (membership → Manage Membership, limit → My Listings);
    clearer flashes; `sp_membership_inactive` keeps the edits. Icons `info`/`alert`.
  - **S2** errors: 1rem error alerts with heading line, icon, red left border, full width; bold field errors with icon, red label,
    2px border; error toasts pale-red + 20 s; `SP.focusFirstError()` on load; six templates got heading lines; contrast +6 pairs.
  - **S3** farm hero: "← Member directory" ghost button beside Favorite / Share.
  - **S4** farm fields: `Seeder::DEFAULT_FARM_FIELDS` + `scripts/update-farm-fields.php` (ran on Local; hay_type #45 new;
    cool_season / warm_seasons inactive, 41 values kept).
  - **S5** listing classes: `scripts/recategorize-listings.php` (ran on Local after `backup-db.php`): Bulls · Cows · Heifers ·
    Steers (Feeders renamed, id kept) · Semen; Females split 31 cows / 28 heifers (old-site category first, title keywords
    second, review list empty), Finishers/Calves → Steers/Heifers, farm offerings converted alike, 2 saved searches rewritten,
    retired archives 301 (`retired-category-pattern` in `Routing\Redirects`), `migrate-redirects.php` rebuilt (580 rows, 584/0).
    Demo seeders, migration map, CSV example and suites re-keyed. Found: `legacy_redirects_enabled` was off on Local.
  - **S6** login notice, **S7** dashboard popup (`POST /sp/v1/account/dismiss`), **S8** feedback button (schema 0.5.0
    `sp_feedback`, `Feedback\*`, `POST /sp/v1/feedback[/upload]`, admin REST + **SouthPoll → Feedback** page + CSV export,
    `FeedbackEmail`), all driven by the new Settings group **Launch & feedback** (schema types `textarea`, `url`, `date`).
  - **S9** digest daily at 08:00, quiet days skipped (defaults + Local settings).
  - **S10** `/members/help/` Help Center (eight Q&A cards reading live settings) + `temp/migration/09-faq-copy.md` for page #880.
- Suite bug fixed: `test-phase4-admin.php` §8's classic Settings form post turned every unchecked toggle off and never restored
  them (that is what kept switching `legacy_redirects_enabled` / `registry_digest_enabled` off on Local). It now snapshots and
  restores `Settings::all()`.
- Regression after Block A: auth 34 / render 40 / payments 40 / cron 29 / farms 97 / listings 120 / admin 159, contrast green,
  JS balance OK, lint clean. Local toggles on: legacy redirects, digest, launch notice, popup, feedback.
- Scripts added: `update-farm-fields.php`, `recategorize-listings.php`, `report-listing-classes.php` (README rows).
- Block B started: **S14** `scripts/sync-registry-terms.php` built and rehearsed on Local (backup
  `app/backups/20261119-013222-before-registry-sync.sql.gz` first): 947 registry rows → 828 matched, 118 unmatched (CSV for the
  office), 188 minimal farms, 434 breeder numbers, 16 junior, 820 terms set, 398 lapsed memberships back to active (calendar-
  year registry terms), second run changes nothing. Decision: no digest events for the back-fill. **Walk-through**
  `temp/migration/08-launch-setup.md` written (credential table + S13 / S14 / S11 / S12 / S15 steps, Local → production push).
- Local data note: Local now holds the registry-synced snapshot (871 active / 72 expired memberships) on top of the demo + test
  data; S13 replaces all of it with the fresh production pull, after which the whole pipeline runs again.
- Carson answered: pull in progress (landed during the session), CSVs are current, create-missing = yes, walk-throughs wanted
  for SendGrid / Stripe / Geocoding / reCAPTCHA, explain recipients, thorough code review + keep temp/ off the frontend before
  the push. A mid-turn "ignore that last request, continue" was taken as "carry on".
- **S13 on the fresh production data** (full numbers in `08-soft-launch-plan.md` §3): backup → plugin activated → 8,859 spam
  deleted → migrate-all (parity 9 OK, 1 DIFF: 108 listing photo files missing locally — DB-only pull) → RTCL off → cutover →
  S14 live with `--create-missing` (72 accounts, 261 farms, 887 terms, 930 active / 69 expired, 37 unmatched without e-mail)
  → redirects 584/0 → Local settings re-applied (reCAPTCHA OFF until the imported keys are confirmed v3).
- **Web root**: `.wpe-push-ignore` now excludes `temp/`, `.claude/`, `*.csv`, `local-xdebuginfo.php`, `uploads/sp-logs*/`;
  registry CSVs moved to `temp/migration/registry/` (`sync-registry-terms.php` default paths updated); old `uploads/sp-logs/`
  deleted (logs now live in a token-named folder).
- **Code review** (`temp/10-code-review.md`): 15 findings, all fixed — admin-user guard, atomic checkout claim, auto-renew
  grace (3 days), token-named log dir + masked recipients, favorites limited to public listings, uninstall autoload, escaped
  e-mail pseudo-tags, mandatory fill time, CSV neutralisation, cancel ownership, tier guard, per-IP auth limits, verified-only
  screenshot upload, webhook record order. Suites after: 34 / 40 / 40 / 29 / 97 / 121 / 160; lint clean.
- Carson entered the launch credentials **on Local** (correct: the push carries Local's options): WP Mail SMTP = SendGrid with
  key, From wordpress@southpoll.com; Stripe mode live with pk_live + a secret; reCAPTCHA keys present (plugin captcha still off);
  Geocoding API enabled in Google Cloud. Checks: a real test e-mail through SendGrid to carson@ **SENT** (plugin log shows the
  masked recipient); Stripe live `GET /balance` **fails — the stored secret starts with `mk_` (27 chars), which Stripe says is the
  ID of a key, not the key itself; needs the `sk_live…` value**; Google key fields on Local are **empty** (the pull reset
  `sp_integrations`), so geocoding cannot run yet (999 farms without coordinates); live webhook secret empty.
- Safety: new mu-plugin `wp-content/mu-plugins/zz-local-mail-guard.php` — on a `.local` host, `pre_wp_mail` blocks every
  recipient outside `example.test` / `solutionsbycarson.com` (verified), so the dev copy cannot e-mail members; listed in
  `.wpe-push-ignore` and inert elsewhere anyway.
- Stripe fixed: Carson created a **restricted live key** (`rk_live…`, 107 chars) instead of rolling the standard secret (the
  old plugin on production still uses that one). Read-only checks from Local: balance OK, prices OK, customers OK, webhook
  endpoints OK — **`https://southpoll.com/sp-webhook/stripe/` already exists and is enabled** on the account (only endpoint
  listed), so the `whsec` signing secret can be pasted on Local before the push.
- Still waiting on Carson: webhook signing secret on Local, Google key on Local (Integrations → Google), reCAPTCHA type
  confirmation (v3), production uploads 2026/10–11, recipient confirmations, maintenance window, FAQ paste.

## 2026-11-18 — Session 12: soft-launch plan written (nothing built yet)

- Carson delivered the staging test feedback (Kathy / Paul / Nathan) plus launch tasks: SendGrid on SPGCA's own account, Stripe
  set-up, fresh live data, registry CSV membership dates, soft-launch feedback button + dashboard popup, FAQ.
- Findings: Local/staging = 2026-10-17 snapshot; mail = Mailgun via WP Mail SMTP; registry CSVs = 947 current members on
  calendar-year terms (854 expire 12/31/2026), 824 match Local accounts/farms, 123 don't; Paul's Stripe error = no test keys on
  staging; Paul's "inactive" listing = draft saved via "Save draft" (publish is a separate button, disabled without an active
  membership); listing categories/field labels inventoried with legacy mappings for the Cows/Heifers split.
- Plan: `temp/08-soft-launch-plan.md` (S1–S15, with touchpoints, scripts to write, decisions) mirrored in `03-build-todo.md`.
  Next session starts at S1 (listing draft UX) → S2 … S10 on Local, then the credential walk-throughs (S11–S12), fresh live data
  (S13), registry dates (S14), push + re-test (S15).

## 2026-10-27 — Session 11: cutover executed on Local; Local is the build to push to staging

- Carson: "get everything ready for pushing to staging, set up Stripe in sandbox mode for testing, and do the cut over." Done on
  Local; staging/production untouched. Checklist for the push + acceptance: `temp/migration/06-staging-push.md`.
- Pre-flight: `scripts/backup-db.php` (new; Local's mysqldump, password via `MYSQL_PWD`) → `app/backups/20261027-201951-before-cutover.sql.gz`.
- Database cutover: `scripts/cutover-local.php` (new; `--dry-run` / `--undo`, undo record in `sp_cutover_log`, idempotent, refuses
  while the RTCL plugins are active): pages 71/49 shortcodes → `[classifieds]` / `[member-directory]` (post_content + Cornerstone
  data; the plugin renders these host pages itself, verified no double output), Join page "Join Today Via Online" → `/members/login/?action=register`,
  old pages My Account #337 / Checkout #336 / Submit a Listing #646 → draft, mobile "My Account" item → `/members/login/`,
  "Member Login" added under Members in both menus, `legacy_redirects_enabled` on, the 301 Redirects plugin's malformed add-listing
  rule (927 hits) removed, customizer `.rtcl` rule dropped, Stripe mode test + the **live** publishable key that had been copied
  into the test slot cleared, rewrites flushed.
- Files decommissioned: child theme `functions.php` rewritten without the RTCL code (keeps parent styles, subscriber body class,
  comments lock-down, `[classifieds_search]`), `style.css` reduced to the admin-bar rule, `single-rtcl_listing.php` /
  `classified-listing/` / `inc/` / `functions.php.bak-20260707` and mu-plugins `southpoll-store-save.php` + `zzz-southpoll-debug.php`
  moved to `temp/migration/retired/` (old functions.php kept there too). RTCL plugin files stay on disk, inactive.
- Redirect map bugs found by the live curl pass and fixed in `migrate-redirects.php`: the `page` group sent *live* pages
  (/classifieds/, /members/, /website-faq/, /join-spgca/) to the dashboard → `page-kept` (no row), /submit-a-listing/ → edit listing;
  the generic `/my-account/` prefix shadowed the specific endpoints (my-store → dashboard) → longest prefix first; self-redirects
  skipped. Table rebuilt (580 exact paths), `--test` 584/0, every account endpoint lands where the runbook says.
- Geocoding: `scripts/geocode-backfill.php` (new) ran for 562 farms — **0 geocoded: the Google key's Cloud project does not have
  the Geocoding API enabled (REQUEST_DENIED)**. The plugin had stamped them as attempted, so: `Geocoder` now distinguishes service
  failures (REQUEST_DENIED / quota / transport → warning, no stamp, batch stops) from unresolvable addresses; Status page notice
  and Integrations → Test geocoding explain the denial; `--retry-failed` cleared the 683 stamps. Map pins appear as soon as Carson
  enables "Geocoding API" for the key (then run the script again, ~3 minutes).
- Stripe sandbox: nothing on this site ever held test keys (the old plugin ran live-only), so the sandbox needs Carson's `pk_test`/`sk_test`
  on SouthPoll → Integrations (mode already test) and, once staging has a URL, a sandbox webhook on `/sp-webhook/stripe/` with the
  five events listed on that screen. Steps are in `06-staging-push.md` §2.
- Verified: home, login, farm, listing, directory, classifieds and the Status page render without PHP notices; old URLs 301 → 200;
  live pages not redirected; plugin lint clean; suites 34 / 13 / 15 / 40 / **96** / 114 / 149 green. The Phase 2 geocoder check
  was re-scoped (direct `geocode_farm` + a REQUEST_DENIED backfill check) because `backfill()` had started on the migrated farms and
  gave 10 of them the fake transport's coordinates — cleared.
- Docs: `03-build-todo.md` T5.7 checked (Local) / T5.8 notes, runbook status header, `scripts/README.md` rows for the three new
  scripts. Next: Carson pushes Local → staging (files + DB), enters the Stripe sandbox keys + webhook, enables the Geocoding API;
  then the acceptance list in `06-staging-push.md` §4, then Phase 6.
- Carson's review list researched against the build → none of the five exist yet → T6.7–T6.11 added with findings: member-facing
  cancel (only staff can cancel today; Q-30 refund policy), twice-weekly registry digest (Mon/Thu 08:00, configurable address,
  add/remove lists from `sp_memberships` + `sp_events`), e-mail timing/volume (reminders go out synchronously at 05:00; migrated
  terms are rolling, no year-end cliff — Jan 2027: 51 expiries, busiest day 9 — queue them anyway), map info window closing
  (diagnosed: markers rebuilt on every `idle`, the auto-pan kills the anchor), classifieds map pins are farms grouped with a
  listing count by design — proposed listing-centric info window, Carson to confirm.
- Carson: "move forward with your recommendations" + cancellation = disable auto-renew only; disabled button + unmistakable note
  when it is already off. Built T6.7–T6.11 (`03-build-todo.md` has the per-item notes):
  - T6.7 cancel: `ManageMembership::cancel` (owner, nonce, `SP.confirm`) → `CheckoutService::set_auto_renew(false)` (Stripe
    cancel-at-period-end), membership note, `MembershipAutoRenewOffEmail` to the farm's membership contacts, admin notice; template
    card shows the confirm form when auto-renew is on, otherwise a disabled button + warning box "There is nothing to cancel …
    NOT set to renew automatically … expires on <date>". Q-30 closed.
  - T6.8 digest: `MembershipEvents` (sp_events writer for activated / expired / cancelled / auto-renew), `RegistryDigest` (self-
    scheduling single event `sp_registry_digest`, window since last successful send, ADD / REMOVE / RENEWED, CSV attachment,
    `RegistryDigestEmail`), settings group "Cattle registry digest" (enabled, recipients, days, time, send-when-empty; new schema
    types `time` and `days`), `Settings` now fires `sp/settings/updated` so the slot follows setting changes, Status page shows
    the job + "Send registry digest now".
  - T6.9 queue: `Message::deferrable()` + `data()`; `Notifier` queues deferrable messages (reminder, expired, recurring notice,
    listing expiring/expired) into `sp_notification_queue` with `send_after` inside the send window (settings `email_window_start`
    07:00 / `email_window_end` 20:00 site time), `AlertDispatcher::build` rebuilds them (`email_key = message`), `alerts_per_run`
    relabelled as the overall cap. Found while doing it: `MembershipCron` runs hourly, so reminders used to leave at any hour.
  - T6.10 map: markers rebuilt only on zoom change, `shouldFocus:false`, cluster click pans.
  - T6.11 classifieds map: `listings/pins` attaches `listing_items` (≤ 5 per farm, filter `sp/map/listings_per_pin`),
    `SP.map.listingsInfoHtml` renders the listing-centric window; CSS `.sp-map-listing*`; i18n `newBadge`, `more`.
  - Tests: cron suite §9 (window math, queued-not-sent, worker rebuild, digest build/run/schedule/slot math; suite drains the
    queue after every run and clears stale rows), render suite cancel-card checks (both states), listings suite drains the queue
    and ignores the owner's own queued notices. All suites green: 34 / 15 / 27 / 40 / 96 / 114 / 149; plugin lint clean; JS
    balance OK; `listings/pins?cats[]=bulls` verified live (8 pins with listing items).
  - Caveat for Local: the admin suite's Settings form post leaves `registry_digest_enabled` off; the run script turns it back on.
- Carson: "have we migrated user accounts? are the two test accounts still active? remove spam users." Accounts were migrated
  (Session 10); `demo-buyer` / `demo-owner` are intact (owner's membership extended to 2026-11-10 so the renewal notice shows during
  the staging test round). **Spam purge**: profiling showed the 10,027 accounts were ~88 % bots — two floods, Apr–May 2025 (random-
  case names like "yTapFvfnztQo tPRpcTgM", random logins) and Jul 2025 onwards (lowercase gibberish names, throwaway domains,
  random letters typed into the breeder-number field). The migration's "registry contact" bucket (5,906) was those bots: a real
  SPGCA breeder number is 3–6 digits, which only 242 accounts have. New `scripts/lib/spam-classify.php` + `purge-spam-users.php`
  (zero-engagement candidates after the 2025-03-11 import; spam = machine-generated name or junk breeder number; random login alone
  never counts; dry run with SPAM / REVIEW / KEEP lists; `--delete` writes a CSV first). Backup `app/backups/20261027-214128-before-
  spam-purge.sql.gz`, then two waves: 2,995 + 5,864 = **8,859 deleted** (CSV records in `app/backups/`), 0 failures, 0 orphaned
  profiles. Left: **1,168 users** (5 admins, 2 editors, 60 test/demo, 729 with a legacy membership row, 755 farm owners incl.
  minimal farms, 109 zero-engagement accounts with plausible names — kept). Runbook §2 now purges before migrating; staging push doc
  carries the outgoing-mail safeguard for staging and a reminder to re-run the dry run there.
- Email draft for Kathy + the team: `temp/migration/07-staging-test-email.md` (staging URL blank, demo password not stored).

## 2026-10-19 — Session 10: Phase 5 Local rehearsal run, verified and green (cutover still not run)

- Local site was back; `./migrate-all.sh --dry-run` → fixed what it exposed, then three real rehearsals (`migrate-reset.php --yes`
  between them). Final run: **`migrate-verify.php` 10/10 parity checks OK** — 10,011 users → 9,962 profiles (+44 existing filled),
  250 stores → 250 farms + 479 minimal farms (184 logos / 136 banners adopted), 729 memberships (481 active / 248 expired),
  949 succeeded payments = $57,501 (17 orphan test orders without a membership + 14 abandoned skipped by design), 100 listings
  (53 live / 37 sold / 10 expired; 46 bulls / 45 females / 7 feeders / 2 semen), 314 photos, 4,740 stats rows, 584 exact redirects.
  `migrate-redirects.php --test`: 588 resolved, 0 problems. Live curl with `legacy_redirects_enabled` on: every URL group 301s to
  the mapped target which returns 200; off again afterwards (404 as before). Migrated farm / listing / directory / classifieds pages
  render with their photos. Reports in `temp/migration/reports/` (users, farms, memberships, listings, redirects, verify ×3 runs).
- Script bugs found by the rehearsal and fixed: RTCL taxonomies are unregistered while the legacy plugins are inactive, so
  `get_term()` / `wp_get_object_terms()` silently returned nothing (every listing lost its category and users lost their state) →
  SQL term lookups in `migrate-lib`; address parser mis-read "Vernon Al", "Munford Al.36268", "Mayo, fl", leading 5-digit house
  numbers (taken as ZIP) and "County Road 3440" (taken as city) → fixed and unit-checked on 24 samples (address-review list went
  from 21 to 5); a spam account's 125-char first name overflowed `sp_members.first_name` and the typed repository return threw →
  values cut to column length, `profile-failed` flag, `spam-name` flag; `$make_farm` closure used `$wpdb` without `global`;
  the undo log was lost when a script crashed mid-run → `sp_mig_autoflush()` flushes it on shutdown; `migrate-all.sh` kept going
  after a failed step → `pipefail`; a pre-existing profile had its fields overwritten with blanks → fill blanks only; the owner who
  already had a farm (Example Ranch #84) did not get `legacy_store_id` → stamped so `/store/{id}/` redirects; `&amp;` in store
  titles / slogans / user names → decoded (0 entities left); redirect loader now normalises paths like the resolver, lets the first
  row win (farm beats a logged 404 for the same path) and skips RTCL `rtcl-auto-temp-*` placeholder slugs; verify now explains
  orphan orders, counts exact map paths and lists farms without state/city across all farms. `migrate-reset.php` also clears
  `sp_migrated` flags.
- Plugin: `legacy_redirects_enabled` added to Settings → Advanced (`Core\SettingsSchema`) so the cutover switch has a UI.
- Regression against the migrated data: all 7 suites green (34 / 13 / 15 / 40 / 95 / 114 / 149). Six directory assertions in the
  Phase 2 / 3 suites assumed a small dataset (first page of 24 among 501 public farms) → scoped to the test farm with `q`.
- Docs: `03-build-todo.md` T5.1–T5.6 checked with the rehearsal numbers, T5.7/T5.8 marked plan-only; runbook §1.3 carries the
  verified parity figures and the review lists; `scripts/README.md` Migration rows updated. Carson's "don't run the cutover yet"
  still stands: nothing touched staging/production, the legacy 301s are off, the RTCL plugins stay inactive on Local only.
- Local now holds the migrated data alongside the demo farms (14) and demo logins; `migrate-reset.php --yes` removes the migrated
  set again if the demo-only state is wanted back.
- Next: Phase 6 (T6.1 event capture + rollups, T6.2 analytics page, T6.3 help content, T6.4 rollout/announcement, T6.5 QA sweep).
  Open for Carson/SPGCA before a real cutover: runbook §0 (Stripe live keys + webhook, Google keys, Q-01, Q-07, review lists).

## 2026-10-19 — Session 9: Phase 5 (migration & cutover) tooling written; Local rehearsal pending

- Carson: "continue with phase 5" but "don't run the cutover yet" → everything is built as Local-rehearsal tooling plus a written
  runbook; nothing touches staging or production, and the plugin's legacy 301s stay off until the `legacy_redirects_enabled`
  setting is switched on at cutover.
- Built (`scripts/`, documented in `scripts/README.md` → Migration): `lib/migrate-lib.php` (dry-run / limit options, Markdown
  reports to `temp/migration/reports/`, undo log in the `sp_migration_log` option, free-text address parser with confidence,
  state + category maps, in-place attachment adoption that rebuilds the plugin image sizes), `migrate-users.php` (T5.1),
  `migrate-farms.php` (T5.2, incl. minimal farms for the 479 members without a store), `migrate-memberships.php` (T5.3, memberships
  + orders → payments with Stripe ids and `legacy_payment_id`), `migrate-listings.php` (T5.4, incl. stats → `sp_stats_daily` and
  old slugs kept), `migrate-redirects.php` (T5.5, `redirect-map.csv` + `sp_redirects` + `--test`), `migrate-verify.php` (T5.6
  parity checklist), `migrate-reset.php`, `migrate-all.sh`. Plugin: new `Routing\Redirects` service (table rows + pattern
  fallbacks for `/rtcl_listing/`, `/store/`, `/listing-category/`, `/listing-location/`, `/my-account/…`, `/checkout/`), setting
  `legacy_redirects_enabled` (default off). Runbook for T5.7/T5.8: `temp/migration/05-cutover-runbook.md`.
- Assumptions recorded as D-28 in `07-open-questions.md` (members = the 729 membership rows, auto-renew off, minimal farms,
  registry contacts kept as buyers, Steers → Feeders flagged, addresses parsed with review list). Q-01 / Q-07 / Q-17 remain open.
- Blocked: the Local site was stopped mid-session (no MySQL on port 10011), so the dry run, the rehearsal, `migrate-verify` and the
  full regression could not be executed yet. Scripts and plugin files pass `php -l`. Next: start the site in Local, run
  `./migrate-all.sh --dry-run`, review the reports, then the real rehearsal + `migrate-redirects.php --test` + the suites.

## 2026-10-19 — Session 8: demo logins, review fixes from Carson's walkthrough

- Demo logins (`scripts/seed-demo-accounts.php`, credentials printed once, never stored): `buyer@example.test` — verified buyer
  with four favorite farms, four favorite listings and three saved searches (default "Missouri bulls"); `owner@example.test` —
  owner of "Rivera Family Farm" (Marshfield, MO; logo, banner, 4-photo gallery, 7 listings incl. a draft) whose membership was
  paid by check, is **not** recurring and expires in 10 days (per Carson) so the countdown notice and the renew-by-card / claim-a-
  check workflow are visible. Farm/listing building moved into `scripts/lib/demo-lib.php`, shared with `seed-demo-data.php`;
  the 14 demo farms were rebuilt with 3–5 gallery photos each.
- Fixes from the walkthrough: (1) farm page hero — only the logo now overlaps the banner; eyebrow, title, slogan and facts sit on
  white below it (no more copy over the photo); (2) classifieds filter controls — inputs, selects and dropdown buttons share one
  44px height inside `.sp-scope`, so the row lines up and select text is no longer clipped by theme rules; (3) `/members/my-farm/`
  white screen — a staff login without a farm hit a fatal (`FarmRepository::social(null)`); staff now see a "No farm on this
  account" card with links to the admin screens (`templates/dashboard/no-farm.php`; covered in the render suite); (4) filter counts —
  `ListingQuery::facets()` recounts every dimension for the current filter set (each ignoring its own selection); `GET listings`
  returns them in `meta.facets`, the archive re-labels options after every load and disables / dims zero-count choices, so a
  "(1)" can no longer point at an empty result; (5) gold buttons always carry white text: `.sp-btn--secondary`, the active
  Favorite button and the "New listing" badge use `--sp-gold-deep` (#8a6a1c, 5.05:1 with white; contrast audit updated and green);
  (6) Saved Searches — the default is a toggle switch per row (only one can be on; switching it off clears the default) with a toast;
  (7) map info window — the pin payload now carries slogan, region, owner, offerings and favorite state (batched in
  `FarmPresenter::pins()`), and the window shows the full card (logo, name, new badge, slogan, location · region, listings light,
  offerings chips, owner, View farm page + Favorite) at a 360px max width with wrapping, instead of the clipped two-liner.
- Dashboard home (Carson): farm accounts now see My listings and My farm first, then Favorites and Saved searches
  (`DashboardHome` fixes the order after the `sp/dashboard/home_cards` filter); the My farm card is a real summary — logo,
  name, slogan, location · region · breeder #, visibility badge, active-listings light, membership state + end date, contact /
  phone / email / website, offerings, gallery photo count, contact-form state, completeness hint, View farm page + Membership
  buttons. Dashboard sidebar is now actually sticky: as a grid item it stretched to the page height, so `align-self: start`
  (plus the admin-bar offset) makes `position: sticky` take effect.
- Classifieds + directory filter bars (Carson): the Reset button no longer sits inside the filter grid (it wrapped under the
  "Has active listings" toggle); both pages show "Reset filters ×" at the right of the results bar whenever a filter is active.
  Logged-in members with saved searches get a quick-apply bar above the classifieds filters (select → opens that saved search,
  the applied one pre-selected, plus a "View / manage saved searches" link); guests and members without saved searches see nothing.
- Login screen: "Undefined array key account_type" warning on `templates/auth/login.php` line 26 — the GET render passes an
  empty `$old` array; the read is now null-coalesced. No other unguarded `$old[]` reads in the auth templates.
- Dashboard sidebar still scrolled, and all form buttons on the dashboard rendered gold: the standalone layout prints its own
  `<html>` but still runs `wp_head()`, so the Pro theme's stylesheets (parent/child style.css, the Integrity stack) loaded and
  restyled buttons / set overflow rules that defeat `position: sticky`. `Assets::strip_theme_styles()` now dequeues every
  stylesheet served from the themes directory on standalone routes (scripts untouched: the listing editor needs WP core ones),
  and the sidebar is `position: fixed` with the main column offset 260px (phones keep the slide-in drawer).
- Edit listing: the Update / Publish / Renew buttons dropped their `--lg` modifier so all form actions (Update, Pause, Save
  draft, Delete) share one size.
- "Get directions" (farm page + listing page) is now members-only: guests get no button (the URL is not emitted at all), in
  line with the gated contact details.
- Listing cards (farm page / classifieds list): the photo box was locked to 4:3, leaving white space under the image whenever
  the text column was taller; the image now fills the card height (absolute, object-fit cover, 180px minimum; 112px compact),
  while the stacked phone layout keeps its 16:9 box.
- Directory "Has active listings" toggle did not clear on Reset: Alpine's checkbox `x-model` with `true-value`/`false-value`
  writes the string into `el.value` instead of toggling `checked`; the toggle now binds `:checked` and sets the model on
  `@change`. (Only occurrence of that pattern in the templates.)
- Saved-search quick apply now works in place (Carson: inputs didn't reflect an applied search): choosing one sets the filter
  model directly (x-model updates every input), reloads results, updates the URL and marks the search viewed; a slide-in toast
  replaces the inline "Saved search …" / "Shared search …" banner (also used when a page opens with `?saved=` or a share link).
  Map info windows: copy forced to high contrast (#272727 / #444) because Google's info-window styles muted it; "View farm page"
  on the classifieds map now opens the farm page (it previously filtered the classifieds by that farm), with a separate
  "See the listings" link for that filter.
- Default saved search REMOVED (Carson: "too messy"): no auto-apply on `/classifieds/`, no Default column / toggle on Saved
  Searches, no badge on the dashboard card, `is_default` dropped from the REST payload and PATCH. The `is_default` column and
  the two repository helpers stay in place unused (no migration needed); the quick-apply menu replaces the feature.
- (superseded) Default saved search: reproduced in-process as Carson's admin account and the server applies it on `/classifieds/` (notice +
  filters). It is intentionally not applied on category pages (`/classifieds/{category}/`, the category is locked) nor after the
  "Show all listings" link (`?all=1`). No code change; documented here and in the reply.
- Suites: four assertions in the Phase 2/3 suites assumed the test farm was the only one in its state / with semen stock / the
  only alert recipient; they are now scoped to the test farm so the demo data can stay. Full regression green (auth 34, render 13,
  cron 15, payments 40, farms 95, listings 114 incl. two new facet checks, admin 149); lint, JS guard and contrast audit clean.

## 2026-10-19 — Session 7: button-colour fix, Q-20 decided, Phase 4 (admin area) built and verified on Local

- Carson's screenshot (My Favorites): small red buttons showed red text on a red background. Cause: the `.sp-scope a` link rule
  (specificity 0,1,1) beat `.sp-btn--primary`; links are now styled with `:where(.sp-scope) a` (zero specificity) and buttons
  never underline. A stray vertical scrollbar on `.sp-tabs` was fixed at the same time. Q-20 decided: listings publish
  immediately, no admin approval (07-open-questions.md).
- Phase 4 per "proceed to phase 4 - keep api wire up for later" — built T4.1–T4.10 (details per item in `03-build-todo.md`).
  New wp-admin menu: Dashboard (count-up stats, claims queue, latest activity), Users / Farms / Listings / Memberships
  (tabs memberships, payments, claims, tiers) as server-side tables (search, filters, sort and paging kept in the URL; row
  selection + bulk actions) with a slide-in drawer whose HTML is rendered by PHP templates (`templates/admin/drawers/*`) and
  driven by a small `data-sp-*` form protocol in `sp-admin.js`; Taxonomies (categories + offerings, ordering, SEO); Settings
  (every `SettingsSchema` group as one classic form, validated by the same code as the REST API); Integrations (masked keys,
  clear checkboxes, test buttons for Stripe / geocoding / maps / reCAPTCHA / email, webhook URL); Import / Export (CSV export
  that respects the table filters, templates, dry-run report, import with per-row errors) for Users, Farms, Listings and
  Memberships. REST lives under `/sp/v1/admin/*`. `ClaimsPage` (southpoll-claims) is replaced by `MembershipsPage`
  (southpoll-memberships); Status moved to `southpoll-status` and the top-level slug is now the Dashboard. `Members\UserGuards`
  stops anyone deleting a farm owner from wp-admin and purges plugin rows when a user is deleted. `FarmEditor::save_farm()` lets
  staff edit any farm; staff can open any listing in the member editor. New emails: `MembershipCancelledEmail`,
  `AdminBroadcastEmail`. Capabilities unchanged: editors and administrators are staff (`sp_manage`); Settings and Integrations
  need the administrator-only caps.
- Choices made while building (reversible): blank CSV cells leave stored values unchanged; CSV state codes must be real US
  states; "Mark refunded" only records the refund here (card refunds happen in the Stripe Dashboard); deleting a taxonomy term
  keeps the farms/listings that used it; payments have no CSV export (not in T4.10); a farm with an active term that submits a
  new offline claim stays active until the claim is approved; staff Extend / Set end date send no email, Record payment and
  Comp send the normal activation + receipt emails, Cancel emails the farm's membership contacts and the office.
- Two latent bugs fixed along the way: `TierRepository::create()` inserted NULL into `billing_interval` whenever the key was
  omitted (the seeder always passed it, so the new "Add tier" form was the first to hit it); the users CSV export did a member
  lookup per row for address/zip (the columns now come from the table query).
- Verified: `scripts/test-phase4-admin.php` 149/149 — permissions (guest/buyer/editor/admin), every admin screen rendered
  in-process, every admin endpoint, the classic forms through a redirect/wp_die exception harness, CSV round-trips for all four
  resources; full regression green afterwards (auth 34, render 12, cron 15, payments 40, farms 95, listings 112); lint and
  `js-balance-check` clean; `/members/` and `/classifieds/` still 200 with the same script order (sp-admin only loads on plugin
  screens; in wp-admin Alpine now depends on sp-admin so components register before it boots). Browser-level checks of the
  admin screens (drawer, bulk prompts, file-picker imports) remain manual. Sample listings re-seeded on the test farm.
- Demo data (Carson: "populate the tool with example listings and farms - pull logos and photos from the media gallery"):
  `scripts/seed-demo-data.php` creates 14 fictional farms with owner accounts (`sp-demo-*@example.test`), map pins, hours,
  social links, offerings, farm meta, memberships in every state (active / auto-renew / expiring / expired / pending claim),
  payment history and ~50 listings with photos. Images are downscaled copies of the media library's old store logos (174),
  banners (135) and classified photo sets (96), attached to the demo farms so `--delete` only removes the copies. Fictional
  websites use `example.com/<slug>` because `wp_http_validate_url` resolves hosts via DNS (a subdomain of example.com fails).
- Still needed from Carson: the API keys — now enterable on SouthPoll → Integrations (Google Maps browser + Geocoding keys,
  Stripe test secret + webhook secret). Next: Phase 5 (migration & cutover); the CSV importers are the intended migration
  path, in the order Users → Farms → Memberships → Listings.

## 2026-10-19 — Session 6: Phase 3 (classifieds) built and verified on Local

- Per Carson: "move to phase 3, save API wire-up for later" — built without blocking on the Google Maps / Stripe keys (map views and
  pins stay inert until the keys are entered, exactly as in Phase 2).
- Schema data step 0.4.0 (plugin 0.3.0): listing categories seeded (Females, Bulls, Finishers, Feeders, Calves, Semen) with SEO title /
  description / order term meta. No table changes: listings are the `sp_listing` post type with `_sp_*` meta (roadmap §2.3), field groups
  use the existing `sp_meta_field_groups` table, alerts use `sp_notification_queue`, saved searches use `sp_saved_searches`.
- Built T3.1–T3.13 (details per item in `03-build-todo.md`): `Listings\*` (types, `Listing` value object, repository, limits, service with
  publish/status/renew/duplicate/delete/expire/freeze-restore, archive query, presenter, editor validation, cron, visibility/integration
  hooks), `Engagement\*` (saved searches + service, notification queue, alert dispatcher), `Contact\InquiryService::submit_listing`,
  nine new emails, REST (`listings`, `listings/pins|filters|{id}/view`, `my-listings…` incl. featured/gallery media, `saved-searches…`,
  `favorites/share-token`, `account/preferences`, `inquiries/listing`), screens (`/classifieds/`, `/classifieds/{category}/`,
  `/classifieds/listing/{slug}/`, `/search/{token}/`, `/favorites/{token}/`, `/members/unsubscribe/`, My Listings, Edit Listing, My
  Favorites, Saved Searches), `[classifieds]` shortcode, admin Meta Fields → Listing field groups tab + Listing Categories menu link,
  `sp-classifieds.js` + classifieds styles. Router now calls an optional `prepare()` on theme controllers before the header prints (SEO
  description, 404 status, 301 for renamed listings). Dashboard home: listings card (counts + live listings), saved-searches card (with
  a live feed), favorites card now lists listings too. Directory: "stock available" filter + options and the farm page listings section
  are live; farm `active_listing_count` / `last_listing_at` are maintained on every listing transition.
- Choices made while building (reversible; recorded in `07-open-questions.md` D-14/16/18/22/23, Q-17–Q-22): publish immediately (no
  moderation; office gets a new-listing email); price = dollars + free "price note", blank = "Call for price"; listing location = farm
  location; limit counts available/coming soon/pending only; sold listings stay public 14 days then hide; expired listings keep an
  `sp_expired` status and can be renewed; alerts queued per publish, max one per saved search / favorite farm per user per hour, sent by
  the 5-minute queue job in batches of 50, with signed one-click unsubscribe links; view counts come from a page beacon so cached pages
  still count (owners excluded); the farm's contact-form toggle covers listing inquiries too.
- Verified: `scripts/test-phase3-listings.php` 112/112; full regression green (auth 34, render 12, cron 15, payments 40, farms 95 after
  two assertions were updated for the 0.4.0 version and the combined favorites card). HTTP: `/classifieds/`, `/classifieds/bulls/`, a
  listing page, the map/filter query string, and the REST endpoints answer 200 inside the theme chrome; unknown category/listing 404.
  Three product bugs found by the suites/HTTP checks and fixed: saved-search dedupe now normalises the state code; deleting a listing
  field group now goes through `delete_post_meta_by_key` so the post meta cache stays in sync; the new `/members/unsubscribe/` route
  404'd until rewrite rules were flushed, so `Migrator` now requests a flush after every migration (manual fallback:
  `scripts/flush-rewrites.php`; 28 plugin rules registered). Browser-level checks (editor uploads, drag reorder,
  lightbox, map, save-search toasts) remain manual; `scripts/seed-sample-listings.php` puts six sample listings on the newest test farm.
- Still needed from Carson: Google Maps browser + Geocoding keys and the Stripe test secret / webhook secret (unchanged from earlier
  sessions). Decisions worth a glance: Q-20 (publish immediately vs. approval) and the final category list (Q-17).

## 2026-10-19 — Session 5: Phase 2 (farms & member directory) built and verified on Local

- Schema 0.3.0 (plugin 0.2.0): `sp_farms.region` (auto-calculated from state on every save; existing rows backfilled) and
  `active_listing_count` (denormalised counter Phase 3 maintains) with indexes; `sp_offering` taxonomy seeded with Females, Bulls,
  Finishers, Feeders, Calves, Semen (display order in term meta `sp_order`).
- Built T2.1–T2.10 (details per item in `03-build-todo.md`): farm domain (`Farms\Offerings`, `FarmRepository`, `Core\MetaFieldRepository`
  + `FarmMetaRepository`, `FarmMediaRepository`, `MediaUploader`, `Geocoder` + `GeoService`, `DirectoryQuery`, `FarmPresenter`,
  `FarmVisibility`, `FarmEditor`), engagement (`FavoriteRepository`, `Favorites` dashboard card), contact (`InquiryRepository`,
  `InquiryService`, `FarmInquiryEmail`, admin copy via `AdminNoticeEmail` type `inquiry`), REST (`farms`, `farms/pins`, `farms/filters`,
  `farms/{slug}`, `my-farm/image`, `my-farm/media…`, `favorites…`, `inquiries/farm`), screens (`/members/my-farm/` editor, `/farms/{slug}/`,
  `/members/` directory + `[member-directory]` shortcode, admin **Meta Fields** page, **Offerings** link to the term screen, Status-page
  "Geocode farms missing coordinates"), assets (`sp-public.css`, `sp-public.js`: `SP.map` with grid clustering and info windows, `SP.gallery`
  lightbox, `SP.uploader` with browser-side downscale + progress, `SP.favorites` toasts, Alpine `spDirectory` / `spFarmPage` / `spFarmEditor`).
  Dashboard home cards are now keyed (`favorites`, `saved_searches`, `listings`, `farm`) so later phases replace one key each.
- Choices made while building (reversible; see `07-open-questions.md` D-17/D-21/D-24, Q-16): directory cards render once in PHP and REST
  returns the same `html`; the first directory page is server-rendered for SEO; guests and unverified accounts get pins rounded to 0.1°
  and no street address; the farm contact email falls back to the owner's login email; inquiries go to the farm email plus managers who
  receive listing emails, with Reply-To set to the sender; gallery/logo/banner files live in the media library tagged `_sp_farm_id` with
  plugin-only image sizes (`sp-logo` 400×300, `sp-card` 640×480 crop, `sp-banner` 1600×500 crop, `sp-gallery` 1400×1050, `sp-thumb` 320×240);
  About uses `wp_editor` (teeny TinyMCE, kses); breeder number editable by the owner only; hidden farms answer 200 with a minimal page.
- Verified: `scripts/test-phase2-farms.php` 95/95 and idempotent across re-runs; Phase 1 suites still green (auth 34, render 12, cron 15,
  payments 40). HTTP: `/members/`, `/members/?state=TX&view=map`, `/farms/{slug}/` return 200 inside the Pro header/footer with the
  `sp-public` assets and route titles; unknown farm 404 with the not-found page; REST endpoints answer guests. Node is not installed, so
  the JS was checked with the new `scripts/js-balance-check.php` only — browser checks of uploads, drag reorder, lightbox and map are still
  manual. Test owner for browsing: newest `sp-owner-new-*@example.test` (see `test-cleanup.php` dry run), password per the auth suite.
- Still needed from Carson: Google Maps **browser** key (`google.maps_browser_key`) and **Geocoding** key (`google.geocoding_key`) via
  `scripts/sp-integrations.php set …`; until then the directory is list-only and farms have no pins. Stripe test secret + webhook secret
  remain outstanding from Phase 1.
- Process: bash heredocs for PHP/JS bodies failed again in this harness (quote mangling before bash parses) — all files were written with
  the Write tool; Bash is used only to lint/run/verify. `test-cleanup.php` now also removes plugin-uploaded attachments, offerings
  relationships and test inquiries.

## 2026-10-19 — Session 4: Phase 1 (accounts & memberships) built and verified on Local

- Schema 0.2.0 (dbDelta adds `pending_email`, `verification_sent_at`, `reminders_sent`, `activated_at`, `expired_at`, `stripe_subscription_id`, `recurring`).
- Built T1.1–T1.16 (details in `03-build-todo.md`): auth pages, account service, dashboard sections (home, account details, my farm users,
  my membership, checkout return), farm-users REST, Stripe client/service/checkout/webhooks/portal, offline claims + admin Memberships page,
  the full Phase 1 email inventory, and the membership cron.
- Carson (2026-10-19): breeder number is collected on the member signup form and the buyer→member farm step and stored on the farm
  (`sp_farms.breeder_number`); it appears in admin notices and the claims queue.
- Test suites in `scripts/` (all run against Local with throwaway `@example.test` accounts; `test-cleanup.php --yes` removes them):
  `test-phase1-auth.php` 34/34, `test-phase1-render.php` 12/12, `test-phase1-cron.php` 15/15, `test-phase1-payments.php` 40/40 (fake Stripe
  transport: checkout → fulfilment, idempotent completion, signed/deduped webhooks, D-27 renewal math, trial-based auto-renew setup, invoice
  cycle renewals, failures, subscription lifecycle, refunds, offline claim submit/approve/reject/record, unconfigured path).
- Bug found by the suites and fixed: return types written as `stdClass|WP_Error` inside namespaced classes resolved to a non-existent class
  and threw a TypeError that something in the loaded stack swallowed with exit code 0. All such types are now `\stdClass|WP_Error`, and
  `scripts/wp-run.php` registers an exception handler so uncaught throwables print and fail the run (exit 255).
- Process note: a message in this session was cut off by a safety filter while I was building a CLI helper that forged login cookies for curl
  tests. That helper was removed; protected screens and REST are now verified in-process (`wp_set_current_user` + `rest_do_request`).
- Still needed from Carson before Stripe can be exercised for real: Stripe **test** secret key + a webhook signing secret (set with
  `scripts/sp-integrations.php`), and the Stripe Dashboard webhook endpoint `/sp-webhook/stripe/` with the events listed in T1.9.
  reCAPTCHA keys were imported from the old plugin; `recaptcha_enabled` can be switched off locally for form testing.

## 2026-10-19 — Session 3b: legacy plugins off; WCAG AA contrast pass on the UI kit

- Per Carson: deactivated `classified-listing`, `-pro`, `-store` on Local (`plugins-toggle.php deactivate`); rewrite rules flushed (0 RTCL rules
  remain, 36 plugin rules). Home, /members/, /classifieds/, /members/login/, /farms/x/, /my-account/, /website-faq/ all 200, no fatals.
  The three child-theme/mu-plugin guards from session 1b keep the theme safe. Reactivate only for migration reference work.
- Contrast audit (`scripts/contrast-check.php`, WCAG formula) found 9 failing pairs, all gold-with-white or the `#8a8a8a` grey.
  Fixes shipped in `sp-tokens.css` / `sp-ui.css` / email template: gold surfaces take ink text (secondary button, "New listing" badge,
  gold banner: 4.63:1); gold text on white uses `--sp-gold-deep #8a6a1c` (5.05:1); `--sp-muted` → `#6f6f6f` (5.02:1); link hover →
  maroon-dark + underline; status lights → `#1e8e3e` / `#c62828`; form borders + toggle track → `#8a8a8a` (3:1 non-text); focus ring → solid
  2px white + 4px maroon. All 27 shipped pairs pass; rules recorded in `branding/brand-style-guide.md`.

## 2026-10-18/19 — Session 3: Phase 0 foundation built and verified on Local

- Plugin created at `wp-content/plugins/southpoll-membership/` (v0.1.0) and activated on Local. T0.1–T0.13 complete (see `03-build-todo.md`).
- Verified: 18 tables created; default tier + 7 farm meta fields seeded; legacy reCAPTCHA/Stripe/email credentials imported into
  `sp_integrations` (Stripe live keys present, webhook secret empty); roles/caps on admins (5) and editors; cron scheduled; status page renders.
- Verified routing (27 rules): `/members/`, `/members/page/N/`, `/members/login/`, `/farms/{slug}/`, `/classifieds/`, `/classifieds/{cat}/`,
  `/classifieds/listing/{slug}/`, `/favorites/{token}/`, `/search/{token}/` → 200 with the Pro/Cornerstone header + plugin assets;
  `/members/dashboard|my-farm|ui-kit/` → 302 to login with flash when logged out; `/sp-webhook/stripe/` → 503 JSON placeholder;
  `GET /wp-json/sp/v1/ping` → 200. Route titles override Yoast.
- Host pages: directory/login → page 49 (`members`), classifieds → page 71. Those pages' old RTCL/Cornerstone content is now replaced by the
  plugin on Local (intended).
- Test email sent through Local mail (Mailpit) with the branded template; preview saved at `research/email-preview-test.html`.
- New scripts: `scripts/sp-plugin.php` (lint/activate/deactivate/status/migrate/context) and `scripts/sp-login-cookie.php` (curl auth cookie).
- Known gaps carried into later phases: Stripe webhook secret not set (T1.9), Google Maps keys empty (T2.5), map/gallery/uploader components (T2/T3).

## 2026-10-18 — Session 2: design document received; roadmap + to-do written

- Carson delivered `plugin architecture.md` (the spec). Read in full.
- Wrote `02-architecture-roadmap.md` (plugin identity, 15 `sp_*` tables + listing CPT, roles/caps, routing table, front-end stack, Stripe
  design, cron jobs, email inventory, admin pages, analytics, migration, six phases) and `03-build-todo.md` (T0.1–T6.6).
- Logged 26 design questions / flagged issues as D-01…D-26 in `07-open-questions.md`. The ones that change the build materially:
  D-03 (separate credential table vs WP users for auth), D-08/D-09 (farm and listing URL structure), D-10 (favorites share URL exposes
  emails), D-12 (Stripe Checkout + Portal), D-15 (free accounts can scrape contact info), D-19/D-20 (farm ownership + lapse behavior).
- `00-README.md` precedence updated: spec > roadmap > to-do > proposal > branding > research.
- Carson decided: D-03 WP users own auth + `sp_members` profile; D-08 `/farms/{slug}/`; D-09 singles at `/classifieds/listing/{slug}/`
  (not category-nested; `listing` reserved as category slug); D-10 random favorites share token; D-12 Stripe Checkout Sessions + Customer
  Portal; D-13/D-15 email verification + reCAPTCHA + honeypot + rate-limited reveals + suspend; D-27 renew-while-active extends from current
  end date. Roadmap, to-do, and question log updated. Wrote roadmap §3.1 (ownership, managers, lapse state machine) for D-19/D-20.
- Carson confirmed D-19 (one farm per user; attached emails reassignable only by site admin; transfer site-admin only; owner accounts
  undeletable; removed managers' listings stay) and D-20 (lapse table as written). Remaining gates before coding: D-01 slug, D-02 front-end library.
- Carson clarified memberships: one tier now, multi-tier later; default $100/year, one year from creation. Added `sp_membership_tiers`
  (seeded default tier, Stripe product/price per tier, checkout picker only when >1 tier), `tier_id` on `sp_memberships`, term math, to-do
  items T1.6/T1.8/T4.5 updated, and D-27 (renew-while-active math) opened.

## 2026-10-18 — Session 1d: Classified Listing plugins reactivated (Local)

- Per Carson, reactivated `classified-listing` → `classified-listing-pro` → `classified-listing-store` with `scripts/plugins-toggle.php activate`.
  `active_plugins` back to 19. Home, /members/, /classifieds/, /my-account/, /submit-a-listing/ all HTTP 200 with RTCL markup rendering.
- The two guards added to `pro-child/functions.php` in session 1b stay in place (harmless while the plugins are active; they only matter when the
  plugins are off).
- RTCL cron events are scheduled again on the Local copy, so the hourly job can hard-delete expired listings whose `deletion_date` has passed
  (see session 1c note about the one listing already lost locally).

## 2026-10-18 — Session 1c: research material separated from guiding docs

- Per Carson: the site-architecture, classified-listing analysis, data inventory/migration notes, and all generated `db-*-raw.md` reports are
  **reference only**, not guiding principles. Moved them to `research/` with a `research/README.md` stating that. Cross-references updated;
  `scripts/README.md` output paths now point to `research/`.
- A detailed plugin design document from Carson will be added to `temp/` and will dictate what is built and how. `00-README.md` now records that
  precedence (design doc > proposal requirements > style guide > research).
- Also per Carson: branding kept outside the build docs — `05-brand-style-guide.md` moved to `branding/brand-style-guide.md`; references updated.
- Per Carson: built the RTCL URL/endpoint inventory for the cutover redirect map → `research/08-rtcl-urls-and-endpoints.md` + `research/rtcl-urls.csv`
  (generator `scripts/report-rtcl-urls.php`, works with the plugins inactive). 609 rows: 7 pages, 10 account endpoints, 5 checkout endpoints,
  2 listing-form URLs, 3 archive patterns, 105 taxonomy archives, 100 listings + 4 old slugs, 250 farms + 75 old slugs, 8 API endpoints, 40 stale 404 paths.
  The `new_url` column is empty until the design document fixes the new routes.
- Noticed: expired listings dropped from 10 (14:20 inventory) to 9 by the time the URL inventory ran. The RTCL hourly cron
  (`delete_expired_listings`) evidently fired on a page hit before deactivation and hard-deleted one past-`deletion_date` listing on the Local copy.
  Production still has it; the local DB is no longer a perfect mirror for that one row. If an exact mirror is needed, re-pull from WP Engine with the
  plugins already deactivated (or block WP-Cron first).

## 2026-10-18 — Session 1b: Classified Listing plugins deactivated (Local only)

- Deactivated `classified-listing-store`, `classified-listing-pro`, `classified-listing` on the Local site with
  `scripts/plugins-toggle.php deactivate` (uses `deactivate_plugins()`, so core cleared its cron events). `active_plugins` now has 16 entries.
  Reactivate any time with `./wp-run.sh plugins-toggle.php activate` (order core → pro → store).
- Two guards added to `pro-child/functions.php` so the theme survives without the plugins:
  (1) the `after_setup_theme` include of `inc/membership-purchase-email.php` now runs only if `\Rtcl\Models\RtclEmail` exists
  (the class extends it and would have fataled every page); (2) `[member_grid]` returns a "temporarily unavailable" notice when `rtclStore()` is missing.
- Verified: home, /members/, /classifieds/, /my-account/, /submit-a-listing/, /website-faq/ all return HTTP 200 with no fatal markers.
  RTCL shortcodes on pages 71/646/336/337 now render as raw text until the new plugin replaces them. Plugin files and all RTCL data
  (custom tables, posts, meta) remain in place for migration.
- Production is untouched.

## 2026-10-18 — Session 1: discovery and knowledge base

**Done**
- Extracted the proposal PDF to `proposal_extracted.txt` and distilled it into `01-proposal-requirements.md` (REQ ids).
- Surveyed the site: WP 7.1.3, Pro 6.9.7 + Cornerstone 7.9.7, pro-child customizations, 19 plugins, 3 mu-plugins → `02-site-architecture.md`.
- Deep-dived classified-listing 6.1.5 / -pro 4.2.6 / -store 3.2.1: data model, settings, flows, Stripe, cron, emails, customizations, defects,
  parity matrix → `03-classified-listing-analysis.md`.
- Built CLI tooling in `scripts/` (`wp-run.sh` + `wp-run.php` bootstrap WordPress with Local's PHP and this site's php.ini, MySQL port 10011) and
  report scripts; generated `db-inventory-raw.md`, `db-forms-subs-raw.md`, `db-theme-gf-raw.md`, `db-payments-detail-raw.md`, `db-renewals-raw.md`.
- Compiled live data inventory and migration notes → `04-data-inventory-and-migration.md`.
- Compiled the brand/style guide from theme options, Cornerstone globals, rendered CSS and child theme → `05-brand-style-guide.md`.
- Listed discovery questions → `07-open-questions.md`.

**Key findings worth remembering**
- 9,967 users but only 729 membership rows (482 active). Most users were bulk-imported in 2025 from the registry list.
- Membership = $100 / 365 days via Stripe PaymentIntents, plus a self-service $0 "Pre-paid" option (honor system) used 377 times.
- 499 Stripe subscription objects exist in the plugin table (all `sub_…`), but no recurring charges have ever been recorded in WordPress. Must be
  reconciled in the Stripe Dashboard before launch (Q-01). No Stripe payment has been recorded since 2026-08-02 while $0 pre-paid orders continue (Q-02).
- Listings publish instantly, expire at 90 days, and are hard-deleted ~18 days later. 101 listings exist (91 published).
- Farm pages: 250; addresses are free text; directory shortcode filters by regex on address.
- No membership expiry emails or cron exist today; expired members just lose access.
- Brand: maroon `#8d183c`, gold `#b2892e`, ink `#272727`, body `#5a5a5a`; Oswald 600 uppercase headings, Montserrat body; square gold buttons that hover to maroon; 1200px max width.

**Environment notes**
- Bash heredocs with long PHP bodies were unreliable in this harness; write scripts with the file tool, run them with `scripts/wp-run.sh`.
- The IDE linter flags a false syntax error in `scripts/report-inventory.php` line 73 (SQL LIKE pattern inside a string); the script runs cleanly.
- Reading `DB_PASSWORD` from `wp-config.php` is blocked by the permission classifier; the PHP bootstrap approach avoids needing it.

**Next**
- Receive the action plan from Carson; then begin module-by-module build. Resolve Q-01/Q-02 early because they shape the Stripe design and migration.
