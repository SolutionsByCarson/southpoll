# Pushing the cut-over Local site to WP Engine staging

Status 2026-10-27: the cutover has been **executed on Local** (migration + decommission + redirects + page/menu swaps). Local is
now the post-cutover build. This note is the checklist for getting it onto staging and finishing the pieces that can only be
done there or in third-party dashboards. Production stays untouched until the staging round-trip is signed off
(`05-cutover-runbook.md` §2 is the production script; its DB steps are now the two scripts below).

## 1. What changed on Local (all reversible)

| Area | Change | Undo |
|---|---|---|
| Data | Migration run (`scripts/migrate-all.sh`), parity 10/10 — see `06-build-log.md` Session 10 | `scripts/migrate-reset.php --yes` |
| Data | `scripts/cutover-local.php`: pages 71/49 shortcodes → `[classifieds]` / `[member-directory]`; Join page button → `/members/login/?action=register`; old pages My Account #337, Checkout #336, Submit a Listing #646 → draft; mobile "My Account" menu item → `/members/login/`; "Member Login" added under Members in both menus; `legacy_redirects_enabled` on; the 301 Redirects plugin's malformed add-listing rule removed; `.rtcl-listing-sidebar-info-wrap` customizer rule dropped; Stripe mode = test and the live key that sat in the test slot cleared | `scripts/cutover-local.php --undo` |
| Data | Geocoding backfill (`scripts/geocode-backfill.php`) with the Google key already on Integrations | nothing to undo (coordinates only) |
| Files | Child theme `pro-child/functions.php` rewritten without the Classified Listing code (member grid, Store→Farm gettext, breeder-number hooks, email reveal, membership e-mail class); `single-rtcl_listing.php`, `classified-listing/`, `inc/`, `functions.php.bak-20260707` removed; `style.css` reduced to the subscriber admin-bar rule | copies in `temp/migration/retired/pro-child/` |
| Files | mu-plugins `southpoll-store-save.php` and `zzz-southpoll-debug.php` removed (`0-worker.php` = ManageWP, kept) | copies in `temp/migration/retired/mu-plugins/` |
| Plugins | Classified Listing / Pro / Store stay **inactive**; files still on disk (delete 30 days after production launch) | re-activate with `scripts/plugins-toggle.php activate` |
| Backup | `app/backups/20261027-201951-before-cutover.sql.gz` (pre-cutover database, 35 MB raw) | restore with Local's DB tool |

## 2. Before pushing

- [ ] **Stripe sandbox keys** (Carson): Stripe Dashboard → toggle *Test mode* → Developers → API keys → copy `pk_test_…` and `sk_test_…`
      into SouthPoll → Integrations → Stripe (mode is already *test*). Click *Test connection* (it calls `POST /sp/v1/admin/integrations/test`
      with service `stripe`). Keys live in the `sp_integrations` option, never in files.
- [ ] **Stripe sandbox webhook** (Carson, after staging is up — Stripe cannot reach Local): Developers → Webhooks → *Add endpoint* →
      `https://<staging-domain>/sp-webhook/stripe/` → events `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`,
      `customer.subscription.updated`, `customer.subscription.deleted` → paste the `whsec_…` signing secret into Integrations.
      The plugin logs a *mode mismatch* warning if a live event ever hits the test endpoint.
- [ ] Decide what **not** to push: `temp/` (research, logs, scripts, retired copies) and `app/backups/` are not needed on staging. If the
      push tool copies the whole `public/`, exclude `temp/` or delete it on staging afterwards — nothing in it is referenced at runtime.
- [ ] Re-run `scripts/wp-run.sh sp-plugin.php lint` and the suites one last time (done 2026-10-27, see build log).

- [ ] **Outgoing mail on staging (important).** Staging holds real member addresses, and the hourly job will queue renewal
      reminders / expiry notices for anyone whose term crosses a threshold while testers use the site. Before the push, on staging:
      either point WP Mail SMTP at a sandbox mailbox (Mailtrap / Mailpit, or its "log only" mode), or set SouthPoll → Settings →
      "Send reminders from" to an empty start time AND turn the queue job off (Status → the `sp_queue` job). Transactional mail the
      testers trigger themselves (verification, password reset, contact forms) should still work for the tester's own address.
- [ ] **Spam accounts** were purged on Local on 2026-10-27 (`scripts/purge-spam-users.php`, CSV records in `app/backups/`); run its
      dry run again on staging after the push to confirm nothing re-appeared.

## 3. Push (Local → WP Engine staging)

1. Local → *Connect* → push **files + database** to the staging environment (the migrated data and the cutover live in the DB,
   the decommissioned theme/mu-plugin changes live in files; both are needed).
2. On staging: Settings → Permalinks → Save (flushes rewrites for the new host), then SouthPoll → Status: schema up to date, 18 tables,
   cron scheduled, Stripe mode test, integrations green.
3. Search-replace is handled by the push tool; spot-check that `home_url()` on Status shows the staging domain and that
   `sp_redirects.to_url` rows use it (they are absolute URLs). If they still say `south-poll-grass-cattle-association.local`, run
   `scripts/migrate-redirects.php` on staging (it rebuilds the table from `research/rtcl-urls.csv` with the current `home_url()`).
4. WP Engine: exclude `southpoll-membership` from Smart Plugin Manager; retire the server-level `/my-account/my-store/` redirect rule
   (the plugin table covers it); confirm WP-Cron runs (Status page shows last run times).
5. Stripe sandbox webhook (section 2), then a full renewal in test mode: `4242 4242 4242 4242`, any future date, any CVC.

## 4. Staging acceptance (what to click)

- Old URLs 301: `/my-account/`, `/my-account/my-store/`, `/checkout/membership/`, `/store/<id>/`, `/rtcl_listing/<slug>/`,
  `/listing-category/bulls/`, `/listing-location/missouri/`, `/listings/`, `/stores/` — each lands on a 200 page.
- Live pages still 200 and **not** redirected: `/`, `/members/`, `/classifieds/`, `/website-faq/`, `/join-spgca/`.
- Nav: "Member Login" under Members (desktop + mobile) → `/members/login/`; Join page "Join Today Via Online" → registration.
- Member flow: password reset for a migrated member → login → dashboard → My Farm (migrated logo/banner/address) → My Listings
  (migrated listings with photos) → Manage Membership → renew in Stripe test mode → webhook marks it paid (Status → log).
- Offline claim: "I paid by check" → appears in SouthPoll → Memberships → Claims → approve.
- Directory map: pins for geocoded farms; farm page "Get directions" only when logged in.
- Admin: Users / Farms / Listings / Memberships / Payments tables, CSV export, Settings → Advanced shows the redirects toggle on.
- Emails: Integrations → *Send test email* arrives from info@southpoll.com.

## 5. Still open (not blockers for staging)

- Q-01 old Stripe `sub_…` subscriptions: auto-renew is off for every migrated member; cancel the old subscriptions in the live
  Stripe account only when SPGCA confirms (runbook §3).
- Q-07 / Q-17 confirmations and the office review lists (`temp/migration/reports/`: 5 `address-review`, 44 `untitled-farm`,
  7 `category-steers`, 2 `spam-name`, 1 duplicate e-mail) — staff can fix these on staging; the fixes come along when the
  staging DB is promoted, or are redone on production after the real cutover (runbook §2 re-runs the migration from live data).
- Website FAQ page (#880) still describes the old my-account screens — Phase 6 help content (T6.3) replaces it.
- Production (runbook §2): same scripts in the same order — `backup-db.php` (or WPE backup point) → `migrate-all.sh` →
  `geocode-backfill.php` → `cutover-local.php` → file deploy of the child theme / mu-plugins — then the live Stripe keys + webhook.
