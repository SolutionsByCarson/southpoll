# Soft-launch plan (requests of 2026-11-18) — working document

Read this first after a context reset. Standing rules still apply (`00-README.md`): everything lives in `temp/`, append to
`06-build-log.md`, never write secrets into temp docs, test accounts use `@example.test`, Local-only runs via `scripts/wp-run.sh`.
The ordered checklist is also mirrored in `03-build-todo.md` (section "Soft launch — requests of 2026-11-18"); tick items there.

## 0. Where things stand (verified 2026-11-18)

- Local = post-cutover build (Session 11): migration run, RTCL decommissioned, legacy 301s on, 8,859 spam accounts purged
  (1,168 users left), geocoding blocked only by the Google key (Geocoding API not enabled on the Cloud project).
- Staging `https://southpollgrstg.wpenginepowered.com/` is up with the plugin (Paul created listing #8048 there). Stripe keys on
  staging are **not** entered → Paul's "Stripe isn't set up" message.
- Local/staging data is a **2026-10-17 snapshot** (last live registration 10-15, listing 10-16, payment 10-17). Live has a month of
  newer users / listings / payments.
- Outgoing mail = WP Mail SMTP → **Mailgun** (`wordpress@mg.southpoll.com`, forced From). SendGrid section of the plugin is empty.
- Registry CSVs (`app/public/MemAddressExp(32).csv` 907 adult rows, `MemAddressExp(33) (1).csv` 40 junior rows): columns
  `memidord` (= SPGCA breeder number, e.g. 40702), `regname`, `farm_name`, `first_name`, `last_name`, address, `phone1`, `email`,
  `mem_sts` AA/JR, `member_num` (8-digit), `exp_date` m/d/Y. **Calendar-year terms**: 854 rows expire 12/31/2026, 54 in 2027,
  the rest 2028–2038 (multi-year/lifetime). 53 rows have no e-mail. Match against Local: 760 by e-mail, 21 by breeder number,
  32 by unique person name, 11 by unique farm name, **123 unmatched** (no web account).
- Listing categories today: Females 22 / Bulls 32 / Finishers 5 / Feeders 9 / Calves 4 / Semen 6 (terms #120–#125, taxonomy
  `sp_listing_category`). Migrated listings carry `_sp_legacy_id`; by old category: females←heifers 19, females←cows 26,
  females←(unknown) 45, bulls 46(+58 unknown), feeders←steers 7, semen 2. Demo/sample listings also use every category.
- Farm meta fields (`sp_meta_fields`, scope farm): #1 forage_type "Forage Type" text, #2 cool_season bool, #3 warm_seasons bool,
  #4 cover_crops text, #5 grazing_system text, #6 feeding_program text, #7 feeding_notes textarea. Seeded by `Core\Seeder`.
- Listing editor bug (Paul): `ListingService::save()` only publishes on action `publish` (or `save` on an already-published
  listing); a draft saved with status Active via "Save draft" stays a draft, and the Publish button is disabled when the farm
  membership is not active (`$can_publish`). My Listings then shows the draft as "Inactive".
- Error styling: `.sp-alert--error` (12px padding, danger text on pale bg) and `.sp-error` (0.8rem) in `assets/css/sp-ui.css`.
- Farm page: "← Back to the member directory" is the last line of `templates/farm/single.php` (line ~221); the Favorite button is
  in the hero at line ~82.
- Settings groups (`Core\SettingsSchema::groups()`): listings, memberships, accounts, contact (admin & email), registry (digest),
  directory, pages, alerts, advanced. Front-end config reaches JS as `window.SP_CONFIG` (`Core\Assets::config()`), modals via
  `SP.modal({title, html|node, footer, size, onClose})` in `assets/js/sp-core.js`, toasts via `SP.toast`.
- Digest (T6.8) defaults: `registry_digest_days` = `mon,thu`, `registry_digest_time` 08:00, `registry_digest_send_empty` true.

## 1. Execution order

### A. Code on Local (then push to staging) — no outside input needed

| # | Item | Plan | Touchpoints |
|---|---|---|---|
| S1 | Listing stuck "inactive" | Drafts: replace the Active/Inactive select with a read-only "Draft — not visible until you press Publish" line (status applies after publishing); "Save draft" flash says exactly that; when Publish is disabled show the reason next to it (membership not active → link to Manage Membership; listing limit reached); My Listings status column shows "Draft" for drafts. `ListingService::save()` unchanged except a clearer `sp_membership_inactive` message. Test: render + phase-3 checks. | `templates/dashboard/edit-listing.php`, `src/Frontend/Sections/EditListing.php`, `src/Listings/ListingService.php`, `templates/dashboard/my-listings.php` / `ListingPresenter::status_label` |
| S2 | Error messages | `.sp-alert--error`: 1rem text, bold heading line, 4px danger left border, icon, full width; `.sp-error`: 0.95rem, bold, with an icon; `.sp-field--error` label turns red; on render with errors scroll/focus the first `.sp-field--error`. Re-run `contrast-check.php`. | `assets/css/sp-ui.css`, `assets/css/sp-tokens.css`, `assets/js/sp-core.js` (focus helper), `scripts/contrast-check.php` |
| S3 | Directory link on farm pages | Add the link into the hero action cluster next to Favorite / Get directions (`sp-btn--ghost sp-btn--sm`), keep the bottom one. | `templates/farm/single.php` |
| S4 | Farm field labels | New script `scripts/update-farm-fields.php [--dry-run]`: relabel/rekey to Primary Forage Type (`forage_type`), Cover Crops (`cover_crops`), Supplemental Feed (`feeding_program` → label), Hay Type (new `hay_type` text), Grazing Style (`grazing_system` → label), Additional Info (`feeding_notes` → label, textarea); `cool_season` / `warm_seasons` set inactive (data kept). Seeder gets the same list for fresh installs. Re-runnable on staging/production. | `src/Core/Seeder.php`, new script, `temp/scripts/README.md` |
| S5 | Listing classes | New script `scripts/recategorize-listings.php [--dry-run]`: Feeders → rename "Steers" (slug `steers`, keep term id); create Cows + Heifers; Females → Cows/Heifers by legacy `rtcl_category` (cows/heifers), remaining by title keywords (heifer → Heifers; cow/pair/bred → Cows; else Cows + review list); Finishers → Steers; Calves → Cows (demo data only) then Finishers/Calves/Females deleted when empty; order Bulls, Cows, Heifers, Steers, Semen; SEO term meta regenerated. Code: `ListingTypes::DEFAULT_CATEGORIES`, migration category map (`migrate-lib`: cows→cows, heifers→heifers, steers→steers), `Routing\Redirects` category patterns (`/listing-category/cows/` → `/classifieds/cows/` etc.), `Admin\Csv\FarmsResource` offerings list if it references categories, demo seeders, suites (`test-phase3-listings`, `test-phase2-farms`), `research/rtcl-urls.csv` map rebuilt via `migrate-redirects.php`. Semen kept; subtypes deferred (team). | as listed |
| S6 | Release notice on login | Settings group **launch** ("Launch & feedback"): `launch_notice_enabled` toggle, `launch_notice_title` text, `launch_notice_text` textarea (plain text + line breaks), `launch_notice_link_label` / `launch_notice_link_url`, `launch_notice_until` (date, empty = no end). Rendered above the login form as `sp-alert sp-alert--info`. | `src/Core/Settings.php`, `src/Core/SettingsSchema.php` (+ `textarea`, `date` types in sanitize/render), `templates/auth/login.php`, `src/Frontend/Controllers/AuthController` (pass settings) |
| S7 | Dashboard popup | `launch_popup_enabled`, `launch_popup_title`, `launch_popup_text`, `launch_popup_version` (bump to show again). Dashboard home: if enabled and user meta `sp_launch_popup_seen` ≠ version → `SP.modal` on load with "Got it" (posts `POST /sp/v1/me/dismiss` with key) and a "Send feedback" button that opens S8. | `templates/dashboard/home.php` or `layout.php`, `assets/js/sp-dashboard.js`, `src/Rest/MeController` (dismiss endpoint), settings as above |
| S8 | Floating feedback button | Service `Feedback\FeedbackService` + table `sp_feedback` (schema bump 0.x: id, user_id, name, email, page_url, user_agent, message, screenshot_id, status new/seen/closed, created_at); REST `POST /sp/v1/feedback` (logged-in: no captcha; guest on login page: reCAPTCHA when configured, honeypot + rate limit) + `POST /sp/v1/feedback/upload` (image ≤ 5 MB via `MediaUploader`); e-mail `FeedbackEmail` to `feedback_emails` (default carson@solutionsbycarson.com) with the log link; floating button (bottom-right, `sp-feedback-fab`, 44px, high contrast, hidden on print) rendered only on the login page and dashboard pages when `feedback_enabled`; admin page SouthPoll → Feedback (table, status, CSV export) via `Admin\Pages\FeedbackPage` + `Rest\Admin\AdminFeedbackController`. Settings in the same **launch** group: `feedback_enabled`, `feedback_emails` (email_list), `feedback_label` ("Feedback / report a problem"). | new `src/Feedback/*`, `src/Core/Schema.php` (+ migration), `src/Routing/Routes.php`, `src/Rest/Api.php`, `src/Admin/Menu.php`, `templates/auth/login.php`, `templates/dashboard/layout.php`, `assets/js/sp-core.js` (feedback modal), `assets/css/sp-ui.css`, i18n in `Assets` |
| S9 | Digest daily | Defaults: `registry_digest_days` = `mon,tue,wed,thu,fri,sat,sun`, `registry_digest_send_empty` = false (skip quiet days — Carson to confirm; Kathy can switch), empty-run subject already says "no changes". Update the Local setting via `sp-integrations.php setting …`. | `src/Core/Settings.php`, settings on Local/staging |
| S10 | FAQ / help | `/members/help/` route + `templates/dashboard/help.php` (or a WP page set in `page_help`) with sections: account & login, farm page, listings, membership & payment, renewals & cancel, favorites & saved searches, account managers, contact & feedback; rewrite the public Website FAQ page #880 copy (Cornerstone page — give Carson the text in `temp/migration/09-faq-copy.md` to paste) | route/template, content file |

### B. With Carson (credentials / accounts) — walk-throughs in `temp/migration/08-launch-setup.md` (to write)

| # | Item | Steps |
|---|---|---|
| S11 | SendGrid SMTP on SPGCA's own account | 1) sendgrid.com → create account in SPGCA's name (office e-mail as owner) → 2) Settings → Sender Authentication → *Authenticate Your Domain* for southpoll.com → add the 3 CNAME records at the DNS host → verify; (quick fallback: *Single Sender Verification* for info@southpoll.com) → 3) Settings → API Keys → *Create API Key*, restricted: Mail Send = Full Access → copy once → 4) WordPress → WP Mail SMTP → Settings: From Email info@southpoll.com (force), From Name SPGCA, Mailer **SendGrid**, paste the key, sending domain southpoll.com → Save → *Email Test* tab → 5) SouthPoll → Integrations → Email from info@southpoll.com; Status → *Send test email* → 6) Only then retire Mailgun (keep its DNS until the first week of SendGrid logs is clean). Staging and production each need the key entered (never in files). |
| S12 | Stripe | Staging: Stripe Dashboard → *Test mode* → Developers → API keys → `pk_test`/`sk_test` → SouthPoll → Integrations (mode test) → *Test connection*; Developers → Webhooks → Add endpoint `https://southpollgrstg.wpenginepowered.com/sp-webhook/stripe/` with `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted` → paste `whsec_…` → Paul re-tests with 4242 4242 4242 4242. Production at cutover: same with live keys + live endpoint on southpoll.com; remove the old RTCL webhook. |
| S13 | Fresh live data | Preferred: fresh production DB (WP Engine → Backups → download, or Local → Connect → pull DB from the live environment into a new Local site) → run `backup-db.php` → `purge-spam-users.php --delete` → `migrate-all.sh` → `cutover-local.php` → `sync-registry-terms.php` (S14) → `geocode-backfill.php` → `recategorize-listings.php` / `update-farm-fields.php` → push to staging. Fallback: Carson exports Users (users-customers-import-export plugin) + Classifieds (RTCL export) from live → `scripts/merge-live-delta.php` imports rows newer than 2026-10-17 (users by e-mail, listings by legacy id; photos re-downloaded by URL). |
| S14 | Membership dates from the registry CSVs | New `scripts/sync-registry-terms.php --file=… [--dry-run]`: match order e-mail → `memidord` vs breeder number (user meta / farm) → unique first+last name → unique farm name; for matched: ensure farm (minimal farm if the account has none), set `sp_memberships` `ends_at` = exp_date 23:59:59 site time, `starts_at` = max(existing, Jan 1 of exp year − term) if empty, status active/expired by date, `source` = registry, note "Registry export <file> <date>", `auto_renew` untouched, breeder number written to the farm (`memidord`) when empty, JR rows get farm meta `membership_class` = junior; unmatched → `temp/migration/reports/registry-unmatched-<stamp>.csv` for the office; `--create-missing` creates minimal accounts (`sp_buyer`, unverified) + farms for unmatched rows that have an e-mail. Idempotent. Prints a diff table before/after. |

### C. Launch
S15: push Local → staging (files + DB), re-enter the staging keys, run S14/S4/S5 scripts on staging, Paul/Kathy/Nathan re-test their items, then production per `05-cutover-runbook.md` §2 with the login notice, popup and feedback button switched on.

### Carson's additions (2026-11-18, mid Session 13)
- This Local site is a **Local (WP Engine) Connect** site attached to both the staging and the production environments, so S13 =
  *Local → Connect → Pull from production (database)* into this site (or a fresh Local site from the same pull), not a CSV merge.
  The pulled users will include the bot registrations again → `purge-spam-users.php` (dry run, then `--delete`) runs on the fresh
  copy before `migrate-all.sh`, exactly as the cutover runbook §2 says.
- **Launch target: push directly from Local to production** once everything is ready (files + database). Staging is only an optional
  rehearsal; S15 is rewritten accordingly: nothing goes live until S1–S14 are done and re-verified on the fresh production data.

## 2. Decisions taken (reversible) — say so if wrong
- Semen stays a listing class (old site has semen listings); Finishers / Calves retired; subtypes later.
- Cancel/feedback/notice settings live in one new settings group "Launch & feedback".
- Digest: daily at 08:00, quiet days skipped.
- Registry CSV wins over the migrated dates (calendar-year terms); JR rows are members (junior) with the same rights.
- Unmatched registry rows are **not** auto-created unless `--create-missing` is passed (office decides).
- (Session 13) Farm **offerings** use the same five classes as the listings, so the directory filter and the classifieds agree:
  Females → Cows + Heifers (both), Finishers → Steers, Calves → Steers + Heifers, Feeders renamed Steers. Listings: Calves whose
  title says "heifer" went to Heifers, the rest to Steers.
- (Session 13) Error alerts carry a short bold heading line per screen ("Your listing was not saved yet", "Something needs your
  attention", …) rather than a generic "Error".

## 3. Progress log
- 2026-11-18: findings gathered, plan written (this file + todo). Nothing built yet for S1–S15.
- 2026-11-18 (Session 13): **S1 done on Local.** Never-published drafts now read "Draft" everywhere (`Listing::is_unpublished_draft()` /
  `effective_status()` → `draft`, `ListingTypes::status_label('draft')`); the editor shows a read-only state line instead of the
  status select whenever the listing is not live (Draft / Paused / Hidden while membership inactive / Expired) and posts
  `status=available` (or the last active status) as a hidden field, so "Inactive" can no longer block Publish; Publish is disabled
  with a warning box naming the reason (membership → Manage Membership link for owners, "ask the owner" for managers; listing
  limit → My Listings link) plus `aria-describedby`; "Save draft" flash: "Draft saved. It is not visible to buyers until you press
  Publish."; `sp_membership_inactive` now keeps the typed edits and says what to do; My Listings' quick-status select only offers
  the stored statuses (`selectable_status_labels()`). New icons `info` / `alert`, CSS `.sp-listing-state` / `.sp-farm-form__note`.
  Tests: phase-3 suite +6 S1 checks (120/120), render 15/15, admin 149/149, lint clean.
- 2026-11-18 (Session 13): **S2 done on Local.** `sp-ui.css`: `.sp-alert` 0.95rem / full width / `grid-column: 1 / -1` inside forms,
  `.sp-alert__title` bold heading line; `.sp-alert--error` 1rem, 4px danger left border, warning-triangle icon (CSS data-URI, no
  template change needed), focus ring; `.sp-error` 0.95rem bold with an icon; `.sp-field--error` turns the label red and the
  control border 2px red; error toasts get the pale-red background, 6px border, 1rem ink text and stay 20 s (was 9 s). Every
  error alert now carries `role="alert"` and a heading line (listing editor: "Your listing was not saved yet" + count of
  highlighted fields, or "Not published" + the reason; farm editor, login, reset-password ×2, account details, manage membership).
  `SP.focusFirstError()` in `sp-core.js` runs on load: scrolls to and focuses the first `.sp-field--error` control (or the error
  alert) once Alpine has shown the right panel. `contrast-check.php` +6 shipped pairs (all ≥ 4.9:1). Verified: contrast audit
  green, JS balance OK, lint clean, auth 34/34, render 15/15.
- 2026-11-18 (Session 13): **S3 done on Local.** `templates/farm/single.php` hero action cluster now starts with "← Member
  directory" (`sp-btn sp-btn--ghost sp-btn--sm sp-farm__directory-link`, 44px like its neighbours) before Favorite / Share; the
  bottom "← Back to the member directory" line stays. Phase-2 suite +1 check (both links present) → 97/97.
- 2026-11-18 (Session 13): **S4 done on Local.** `Core\Seeder::DEFAULT_FARM_FIELDS` (Primary Forage Type `forage_type`, Cover Crops
  `cover_crops`, Supplemental Feed `feeding_program`, Hay Type `hay_type` new, Grazing Style `grazing_system`, Additional Info
  `feeding_notes` textarea) + `RETIRED_FARM_FIELDS` (`cool_season`, `warm_seasons`); fresh installs seed that list. New
  `scripts/update-farm-fields.php [--dry-run]` relabels in place (keys + stored values untouched), creates `hay_type`, sets the two
  booleans inactive (41 stored values kept, hidden), reorders, prints before/changes/after; second run = "none". **Run on Local**
  (field ids 1,4,6,45,5,7 active; 2,3 inactive). Must be run again on staging / production after the push (README row added).
  Phase-2 meta check rewritten for the new labels → 97/97; lint clean.
- 2026-11-18 (Session 13): **S5 done on Local.** Classes are now Bulls #121 · Cows #142 · Heifers #144 · Steers #123 (was Feeders,
  id kept) · Semen #125; Females / Finishers / Calves deleted. Backup `app/backups/20261119-005741-before-recategorize.sql.gz`,
  then `scripts/recategorize-listings.php` (new; dry-run first): females → cows 31 / heifers 28 (old-site category first, then
  title keywords — review list empty), finishers → steers 10, calves → steers 7 / heifers 3 (incl. the 11 expired listings a first
  pass missed because `sp_expired` is exclude_from_search — fixed to SQL); farm offerings converted alike (Females → Cows + Heifers
  13 farms, Finishers → Steers 4, Calves → Steers + Heifers 5; old offering terms deleted); 2 saved searches rewritten; second run =
  nothing to move. Code: `ListingTypes::DEFAULT_CATEGORIES` / `Offerings::DEFAULTS` = the five classes; `migrate-lib` category map
  cows→cows, heifers→heifers, steers→steers, pairs→cows, calves→steers; `Routing\Redirects` legacy map updated + new
  `retired-category-pattern` rule (`/classifieds/females|feeders|finishers|calves/` → cows / steers, in code so a redirect-table
  rebuild cannot lose it); `migrate-redirects.php` re-run (580 rows, `--test` 584/0, probes extended); demo seeders re-keyed
  (`demo-lib` lots bulls / cows / heifers / steers / semen, sample listings, demo accounts' saved search "Cows"); CSV example
  `bulls|cows`; `report-listing-classes.php` (new, read-only). Curl: `/classifieds/females/` 301 → cows, `/listing-category/heifers/`
  301 → heifers. Found on the way: **`legacy_redirects_enabled` was off on Local** (a suite's Settings post had reset it) → turned
  back on with `sp-integrations.php setting legacy_redirects_enabled 1`; verify it on every environment before launch. Suites:
  phase-2 97/97 (two "6 offerings" checks → 5), phase-3 120/120 (S5 order check), phase-4 149/149; lint clean. Must be re-run on
  each environment after the push (dry-run, backup, run, then `migrate-redirects.php`).
- 2026-11-18 (Session 13): **S6 done on Local.** New settings group **Launch & feedback** (`SettingsSchema` key `launch`, before
  Advanced) holding the S6/S7/S8 keys: `launch_notice_enabled` / `_title` / `_text` (textarea) / `_link_label` / `_link_url` (url) /
  `_until` (date); `launch_popup_enabled` / `_title` / `_text` / `_version`; `feedback_enabled` / `feedback_emails` (email_list,
  default carson@solutionsbycarson.com) / `feedback_label`. New schema types `textarea`, `url`, `date` (sanitize + classic form
  render). Defaults in `Core\Settings` (notice + popup copy drafted, everything **off** by default). `templates/auth/login.php`
  renders the notice under the page title as `sp-alert sp-alert--info sp-launch-notice` (heading line, `nl2br` text, optional
  link) while enabled and `launch_notice_until` is empty or ≥ today (site time). Verified by curl on Local and by 5 new
  render-suite checks (on / past end date / on the end date / off / sanitising) → 20/20; admin suite 149/149 with the new group.
  **Suite bug found and fixed:** `test-phase4-admin.php` §8 posts the classic Settings form, which turns every unchecked toggle
  off, and never restored them — that is what had switched `legacy_redirects_enabled` (and `registry_digest_enabled`) off on Local.
  The suite now snapshots `Settings::all()` before §8 and restores it (+1 check). Local toggles re-enabled: legacy redirects,
  registry digest, launch notice.
- 2026-11-18 (Session 13): **S7 done on Local.** `DashboardHome::launch_popup()` embeds `<script type="application/json"
  id="sp-launch-popup">` on the dashboard home when `launch_popup_enabled` and the user's `sp_dismissed_launch_popup` meta ≠
  `launch_popup_version`; `sp-dashboard.js` opens `SP.modal` (title, text lines as paragraphs, "Got it" + "Send feedback" when
  `feedback_enabled`), any close → `POST /sp/v1/account/dismiss {key, value}` (new, `AccountController::dismiss`, user meta
  `sp_dismissed_<key>`, reusable for other one-time notices). i18n `gotIt` / `sendFeedback`; CSS `.sp-launch-popup`. Render suite
  +6 checks (embedded / dismiss 200 + meta / hidden after / shown again on version bump / off / 422) → 26/26; lint + JS balance OK.
  Local: `launch_popup_enabled` on (version `2026-11`).
- 2026-11-18 (Session 13): **S8 done on Local.** Schema 0.5.0 adds `sp_feedback` (user_id, name, email, page_url, user_agent,
  message, screenshot_id, status new/seen/closed, ip_hash, notes, timestamps; migrated on Local). New `Feedback\FeedbackRepository`,
  `Feedback\FeedbackService` (honeypot, 3 s minimum fill time + reCAPTCHA v3 action `feedback` for guests, 10/hour rate limit per
  account/IP, message 5–5000 chars, guests must give name + e-mail, screenshot only if uploaded by the same user), `Feedback\
  FeedbackButton` (prints the FAB + `<template>` form; `templates/feedback/button.php`), `Email\Messages\FeedbackEmail`
  (reply-to = sender, link to the admin page) to `feedback_emails`, REST `POST /sp/v1/feedback` (public) + `POST /sp/v1/feedback/
  upload` (logged-in, image ≤ 5 MB, `_sp_feedback_user` meta), admin `GET/PATCH/DELETE /sp/v1/admin/feedback[/id]` +
  `/bulk` (seen / closed / new / delete), **SouthPoll → Feedback** page (`Admin\Pages\FeedbackPage`, spAdminTable, status filter,
  search, bulk, per-row Seen / Close / Reopen / Delete, screenshot thumbnail, CSV export via `admin-post.php?action=
  sp_feedback_export`). Button printed on the login page and every dashboard page while `feedback_enabled`; `SP.feedback.open()` in
  `sp-core.js` builds the modal (sender prefilled for members, name/e-mail for guests, page URL + browser captured, screenshot
  upload for members, field errors inline, thank-you state); the S7 popup's "Send feedback" opens it. CSS `.sp-feedback-fab`
  (fixed bottom-right, 44px, white on maroon = the audited primary pair, hidden on print). Tests: render suite +11 (button
  on/off, both forms, member POST + e-mail + reply-to, 422 / honeypot 400 / too-fast 400 / guest 200 / upload 401 / off 403)
  → 38/38; admin suite +9 (list + counts, PATCH, 422, bulk, filter, page render + export link, DELETE, 404, buyer 403) → 159/159;
  lint + JS balance clean; login page curl shows the button. Local: `feedback_enabled` on, recipient carson@solutionsbycarson.com.
- 2026-11-18 (Session 13): **S9 done on Local.** `Core\Settings` defaults: `registry_digest_days` = every day, `registry_digest_send_empty`
  = false (quiet days skipped; the empty-run subject "Registry digest — no changes (…)" stays for anyone who turns it back on),
  time 08:00 unchanged. Local settings updated the same way (`sp-integrations.php setting …`), next slot = 2026-11-19 08:00
  America/Chicago, cron event rescheduled. Cron suite: the slot-math check now pins `mon,thu` itself, +2 checks (daily slot math,
  defaults) → 29/29. Note for staging/production: existing sites keep their stored `mon,thu` until the two settings are changed on
  SouthPoll → Settings → Cattle registry digest (S15 checklist).
- 2026-11-18 (Session 13): **S10 done on Local.** `/members/help/` is a real section now: `Frontend\Sections\HelpCenter` +
  `templates/dashboard/help.php` — eight cards (account & login, farm page, listings, membership & payment, renewals &
  cancelling, favorites & saved searches, account managers, contact & feedback) of `<details>` Q&As with a jump-pill nav; the copy
  reads the live settings (tier name/price, listing days, limit, sold window, reminder days, offline claims, verification, feedback
  label, office contact) so numbers cannot drift; buyers get a note that farm topics apply to member accounts. CSS `.sp-help*`
  (44px summaries, focus ring). Public FAQ: `temp/migration/09-faq-copy.md` has the intro + 7 accordion items for Carson to
  paste into Cornerstone page #880 (the old items described the retired plugin). Render suite +2 → 40/40.
- 2026-11-18 (Session 13): **Block A regression** — all seven suites green in order: auth 34, render 40, payments 40, cron 29,
  farms 97, listings 120, admin 159; contrast audit green; JS balance OK; lint clean; sample listings reseeded. Local toggles:
  legacy redirects ON, digest ON (daily, skip empty), launch notice ON, popup ON, feedback ON.
- 2026-11-18 (Session 13): **S14 built and rehearsed on Local.** `scripts/sync-registry-terms.php` (README row): match e-mail →
  farm e-mail → breeder number (farm / user meta) → unique person name → unique farm name; pre-pass keeps only the latest expiry
  date when several rows land on one farm; minimal farm via `FarmService::create_for_owner` for farm-less accounts; term =
  exp_date 23:59:59 site time (UTC in the table), `starts_at` Jan 1 of that year when empty, status by date, tier default, source
  `registry`, note; breeder number written when missing; JR → `sp_farm_meta.membership_class = junior`; flips to expired hide
  listings, flips to active restore them; **no digest events** (decision: the digest reports changes from now on, not the
  back-fill). Backup `app/backups/20261119-013222-before-registry-sync.sql.gz`, then live run on the October snapshot: 947 rows →
  828 matched (e-mail 759, breeder-farm 17, breeder-user 4, person-name 37, farm-name 11), 1 staff skipped, 118 unmatched (CSV in
  `migration/reports/registry-unmatched-20261119-013241.csv`), 188 minimal farms created, 434 breeder numbers written, 16 junior,
  820 terms set, 398 lapsed memberships → active, 1 → expired; website status counts 474/281 → 871/72. Review list (86 lines:
  name/breeder matches + duplicate rows) in `registry-sync-20261119-013241.md`. Second run: 808 unchanged, nothing set. Render +
  cron suites still green. **Must run again on the fresh production data (S13 step 6); `--create-missing` is Carson's call.**
- 2026-11-18 (Session 13): **Block B walk-through written:** `temp/migration/08-launch-setup.md` — one table of the nine things
  Claude needs from Carson (fresh production DB pull, newest registry CSVs, create-missing decision, SendGrid account + restricted
  key, Stripe live keys + live webhook secret (+ optional test keys for staging), Geocoding API enabled, reCAPTCHA keys, feedback /
  digest recipients confirmed, a maintenance window), then step-by-step S13 / S14 / S11 / S12 / S15 with Local → production as the
  push path. Nothing in Block B touches staging or production until Carson acts.
- 2026-11-19 (Session 13, cont.): **S13 done on Local on the fresh production pull** (Carson: Local → Connect → Pull, DB only;
  confirmed by sp_* tables gone, 9,983 users, newest registration 2026-11-17, RTCL active, siteurl rewritten). Backup
  `app/backups/20261119-014532-fresh-prod-pull.sql.gz` → plugin re-activated (schema 0.5.0, categories/fields seeded with the new
  lists) → `purge-spam-users.php` 8,859 deleted (CSV in `app/backups/`), 1 REVIEW left, 1,124 users → `migrate-all.sh`: 1,119
  profiles, 252 store farms + 486 minimal, 738 memberships (469 active), 971 payments = $57,501, 93 listings, 4,639 stats rows,
  580 redirects; `migrate-verify` 9 OK / **1 DIFF: listing images 312 → 204 — 108 photo files uploaded to production since
  October are not on Local (DB-only pull)** → ask Carson to pull `wp-content/uploads` (2026/10–2026/11) and re-adopt →
  `plugins-toggle.php deactivate` → `cutover-local.php` → **S14 live with `--create-missing`** (Carson: yes): 947 rows, 743
  e-mail / 18 breeder-farm / 4 breeder-user / 41 person / 9 farm-name matches, **72 accounts created** (2 failed: duplicate
  logins — in the unmatched CSV), 261 farms created, 438 breeder numbers, 24 junior, 887 terms set, 462 → active; website
  memberships 469/269 → **930 active / 69 expired**; 37 rows still unmatched (all without e-mail) →
  `migration/reports/registry-unmatched-20261119-015657.csv`; `update-farm-fields` / `recategorize-listings` = nothing to do
  (fresh seed); `migrate-redirects` 580 rows, `--test` 584/0; Local settings re-applied (notice / popup / feedback / digest daily /
  legacy redirects ON; **reCAPTCHA OFF** because the imported keys' version is unknown). Curl: home, login, classifieds, cows,
  dashboard, farm page, listing 200; legacy 301s OK; `/temp/` 403 but files inside 200 on Local → `.wpe-push-ignore` now
  excludes `temp/`, `.claude/`, `*.csv`, `local-xdebuginfo.php`, `uploads/sp-logs*/`; registry CSVs moved to
  `temp/migration/registry/`. Digest scheduled 2026-11-19 08:00.
- 2026-11-19 (Session 13, cont.): **Code review (item 9) done** — `temp/10-code-review.md`: 1 critical (editor → admin takeover
  via admin users PATCH), 4 high (double checkout fulfilment, auto-renew expiring before Stripe pays, web-readable logs,
  favorites exposing drafts), 1 medium, 9 low — **all fixed**, two regression checks added; suites 34 / 40 / 40 / 29 / 97 /
  121 / 160 green, lint clean.
