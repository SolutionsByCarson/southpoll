# Launch set-up walk-through (Block B of `08-soft-launch-plan.md`) — written 2026-11-18, Session 13

Who does what: **Carson** holds every account and credential (nothing is stored in these files); **Claude** runs the Local
scripts and verifies in-process. Staging is optional (a rehearsal); the real target is **Local → production** per Carson.
Order: S13 (fresh data) → S14 (registry dates, already rehearsed) → S4 / S5 scripts → S11 (SendGrid) → S12 (Stripe) → S15 (push).

What Claude needs from Carson, in one place (details in each section):

| # | Needed | Why | Where it goes |
|---|---|---|---|
| 1 | A fresh **production database** on Local: either run Local → *Connect* → **Pull** (database only, from the Production environment) into this site, or download the WP Engine backup `.sql` and tell Claude the path | S13 | Local only |
| 2 | The **current registry export** (the two `MemAddressExp` CSVs are from 2026-11-18; a newer export is fine) | S14 | `app/public/` (Claude runs the script) |
| 3 | Decision: create web accounts for the **unmatched registry rows that have an e-mail** (`--create-missing`), or leave them to the office | S14 | one word: yes / no |
| 4 | **SendGrid**: SPGCA-owned account (office e-mail as owner), domain authentication done, and a **restricted API key (Mail Send only)** | S11 | WP Mail SMTP on production (Carson pastes it; never in temp/) |
| 5 | **Stripe live keys** (`pk_live…`, `sk_live…`) and the **live webhook signing secret** for `https://southpoll.com/sp-webhook/stripe/`; optionally the **test keys** + a sandbox webhook on staging for Paul's re-test | S12 | SouthPoll → Integrations on the target site (Carson types them) |
| 6 | **Google Cloud**: enable the *Geocoding API* on the project that owns the existing Maps key (or a new server key) | S13 geocode step | SouthPoll → Integrations → Google |
| 7 | **reCAPTCHA v3** site + secret key for `southpoll.com` (if the plugin's captcha should be on for guest forms incl. the feedback button) | S8 / login | SouthPoll → Integrations → reCAPTCHA |
| 8 | Confirm the **feedback recipients** (default carson@solutionsbycarson.com) and whether Kathy wants the registry digest list changed | S8 / S9 | SouthPoll → Settings |
| 9 | A production **maintenance window** (30–45 min) and the go-ahead to push | S15 | — |
| 10 | (added 2026-11-19) The production **`wp-content/uploads/2026/10` and `2026/11` folders** on Local — Local → Connect → Pull → Production → *files only*, or a copy via SFTP: 108 listing photos uploaded since October exist in the database but not as files here, so their listings would lose them on push | S13 | Claude re-adopts them with `migrate-listings.php` media step |

Status 2026-11-19: items 1, 2, 3 done (pull landed, CSVs current, accounts created). Code review done (`temp/10-code-review.md`).
`.wpe-push-ignore` keeps `temp/`, `.claude/`, `*.csv`, `local-xdebuginfo.php` and the log folders off production; after the
push, `https://southpoll.com/temp/` and `https://southpoll.com/temp/00-README.md` must both answer 404 (S15 step 5).

---

## S13 — Fresh production data on Local

Goal: Local = production users / listings / payments as of launch day, with the plugin's migration + cutover re-run on it, so
the push to production carries data that is at most a day old.

1. **Carson**: in Local, open the site → *Connect* tab → make sure it is connected to the WP Engine environment → **Pull** →
   choose **Production** → *Database only* (files on Local are newer than production: the plugin, child theme and retired
   mu-plugins must not be overwritten). Local replaces `wp_*` tables and rewrites URLs to the `.local` domain.
   - Alternative: WP Engine User Portal → Production → Backups → *Download* the latest → unzip → tell Claude the `.sql` path;
     Claude imports it with Local's mysql (same as `backup-db.php` in reverse) and runs a search-replace of the domain.
2. **Claude** (all on Local, each step logged in `06-build-log.md`):
   1. `backup-db.php --label=fresh-prod-pull` (so the pull itself can be restored).
   2. Re-activate the plugin if the pulled options table lost it (`sp-plugin.php activate`); schema 0.5.0 migrates on load.
   3. `purge-spam-users.php` dry run → review the SPAM / REVIEW lists → `purge-spam-users.php --delete` (the pull brings the
      bot registrations back; the classifier is the one that removed 8,859 on the October snapshot).
   4. `./migrate-all.sh --dry-run` → `./migrate-all.sh` → `migrate-verify.php` (parity checklist, reports in `migration/reports/`).
   5. `cutover-local.php` (shortcodes on pages 71/49, menus, Join button, old pages drafted, legacy redirects ON).
   6. `sync-registry-terms.php --dry-run` with the newest CSVs → review → `sync-registry-terms.php` (S14; `--create-missing`
      only if Carson says yes).
   7. `update-farm-fields.php` and `recategorize-listings.php --dry-run` → run → `migrate-redirects.php` (+ `--test`).
   8. `geocode-backfill.php` once the Geocoding API is enabled (item 6 above) — ~3 minutes for ~750 farms.
   9. Settings on Local that the push will carry: Launch & feedback (notice / popup / feedback ON), registry digest daily,
      legacy redirects ON, digest recipients, feedback recipients. The seven suites + `migrate-verify` must be green before S15.
3. Expected deltas vs the October snapshot: a month of registrations (mostly bots), a handful of listings and payments. The
   verify report lists them.

## S14 — Membership dates from the registry export (rehearsed on Local 2026-11-18)

`scripts/sync-registry-terms.php` is written and was run on the October snapshot: 947 rows → 828 matched (759 by e-mail, 17 by
breeder number on a farm, 4 by breeder number on an account, 37 by unique person name, 11 by unique farm name), 1 staff account
skipped, **118 unmatched** (53 of them have no e-mail at all) → `migration/reports/registry-unmatched-<stamp>.csv`. On the
snapshot it created 188 minimal farms for accounts without one, wrote 434 missing breeder numbers, flagged 16 junior members, set
820 terms (398 lapsed website memberships became active again because the registry runs calendar-year terms to 12/31/2026),
and a second run changes nothing. The run on the fresh production data (S13 step 6) will show different numbers; the office
gets the unmatched CSV either way.

Carson decides: `--create-missing` (accounts for the unmatched rows with an e-mail: role buyer, unverified, **no e-mail is
sent**; they use "Forgot your password?" to get in) — yes or no.

## S11 — SendGrid on SPGCA's own account

1. **Carson** (or the office): sendgrid.com → *Start for free* → sign up with an SPGCA address (e.g. info@southpoll.com) so the
   account belongs to the association, not to a contractor. Complete the sender identity questions.
2. Settings → **Sender Authentication** → *Authenticate Your Domain* → DNS host = wherever southpoll.com's DNS lives
   (WP Engine / the registrar) → domain `southpoll.com` → SendGrid shows **3 CNAME records** → add them at the DNS host → *Verify*.
   Quick fallback while DNS propagates: *Single Sender Verification* for info@southpoll.com (works immediately, less deliverable).
3. Settings → **API Keys** → *Create API Key* → name "southpoll.com website" → **Restricted Access** → *Mail Send* = Full
   Access, everything else none → Create → copy the key **once** (SendGrid never shows it again).
4. Production wp-admin → **WP Mail SMTP** → Settings: From Email `info@southpoll.com` + *Force From Email*; From Name `SPGCA`;
   Mailer **SendGrid**; paste the API key; Sending Domain `southpoll.com` → Save → *Email Test* tab → send to yourself.
5. SouthPoll → **Integrations** → Email: from address `info@southpoll.com`, from name SPGCA (the plugin's own From override);
   SouthPoll → **Status** → *Send test email*.
6. Keep Mailgun's DNS and account for a week after the first clean SendGrid send; then retire it. Staging (if used) needs the
   same key entered separately. The key is never written into temp/.

## S12 — Stripe on the website

Staging / sandbox (optional, for Paul's re-test):
1. Stripe Dashboard → toggle **Test mode** → Developers → API keys → copy `pk_test…` and `sk_test…`.
2. Staging wp-admin → SouthPoll → Integrations → Stripe: mode **test**, paste both → *Test connection*.
3. Developers → Webhooks → *Add endpoint* → `https://southpollgrstg.wpenginepowered.com/sp-webhook/stripe/` → events
   `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`,
   `customer.subscription.deleted` → copy the signing secret `whsec_…` → paste it on the same Integrations screen.
4. Paul: Manage Membership → Pay by card → `4242 4242 4242 4242`, any future date, any CVC → membership turns active.

Production (at S15):
1. Stripe Dashboard → **Live mode** → Developers → API keys → `pk_live…` / `sk_live…` (reveal the secret once).
2. Production wp-admin → SouthPoll → Integrations → Stripe: mode **live**, paste both → *Test connection*.
3. Developers → Webhooks (live) → *Add endpoint* `https://southpoll.com/sp-webhook/stripe/` with the same five events → paste
   `whsec_…`. **Disable / delete the old Classified Listing webhook** on the same screen so it stops posting to the retired plugin.
4. SouthPoll → Status: the webhook health line turns green after the first event; a $100 test membership on a staff account,
   refunded from the Dashboard, is the cheapest end-to-end check.

## S15 — Push Local → production (Carson runs the push; Claude prepares and verifies)

Before the push (Local): S13 pipeline done, suites green, `migrate-verify.php` OK, Launch & feedback settings ON, digest
daily, legacy redirects ON, Help Center and FAQ copy in place (`09-faq-copy.md` pasted on page #880 can also be done after).

1. **Carson**: WP Engine → Production → *Backup points* → create a manual backup "before SouthPoll launch".
2. **Carson**: Local → *Connect* → **Push** → Production → **files + database**. Exclude nothing: the plugin, the cleaned
   child theme and the retired mu-plugins must all land. (If the push UI offers "include uploads", include them — the migration
   adopted existing media in place, no new uploads are required, but listing/farm images created on Local since the pull would
   otherwise be missing.) Local rewrites `.local` → `southpoll.com`.
3. **Carson** on production wp-admin, in this order: SouthPoll → Integrations → Stripe live keys + webhook secret (S12);
   Google key (Geocoding API enabled); reCAPTCHA keys; WP Mail SMTP → SendGrid (S11); SouthPoll → Status → *Send test email*,
   *Geocode now*, check cron (`sp_registry_digest` scheduled for tomorrow 08:00, hourly / 5-minute jobs present).
4. **Carson**: WP Engine → remove the server-level 301 for the old add-listing rule if one exists; WPE Smart Plugin Manager →
   exclude `southpoll-membership` from auto-updates (nothing to update, but no surprises); clear the WP Engine cache.
5. **Claude** (read-only over HTTPS): home, `/members/login/` (notice + feedback button), `/classifieds/`, `/classifieds/cows/`,
   a farm page, a listing, `/listing-category/heifers/` 301, `/my-account/` 301, `/website-faq/`, REST ping — then the
   acceptance list in `06-staging-push.md` §4 adapted to production.
6. **Carson** runs the three post-push scripts **only if** they were not run on Local before the push (they were, so normally
   nothing): `update-farm-fields.php`, `recategorize-listings.php`, `sync-registry-terms.php`. These scripts are Local-only
   (`wp-run.sh`); on WP Engine they would need WP-CLI `eval-file` — avoid by doing everything on Local first.
7. Announce: login notice + dashboard popup are on; Kathy / Paul / Nathan re-test their items on production with real logins;
   feedback arrives at carson@solutionsbycarson.com and under SouthPoll → Feedback.
8. Rollback: WP Engine restore of the manual backup point (files + DB) — the old plugin family is still on disk, inactive.
