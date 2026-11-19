# Phase 5 — migration & cutover runbook (T5.7 / T5.8)

Status 2026-10-27: **executed on Local** (migration + §2.6/§3 database steps via `scripts/cutover-local.php`, child theme and
mu-plugins cleaned, legacy 301s on). Staging and production are untouched. `06-staging-push.md` is the checklist for the
push to WP Engine staging and the acceptance round; this file remains the production script. Carson: "Don't run the cutover yet"
(session 9) was superseded by "do the cut over" (session 11) for the Local build.
Local rehearsal uses `scripts/migrate-all.sh`; reports land in `temp/migration/reports/`. Database steps now have scripts:
`backup-db.php` → `migrate-all.sh` → `geocode-backfill.php` → `cutover-local.php` (each with `--dry-run` / `--undo` where it applies).

## 0. Inputs still needed before a real cutover

| Item | Why | Owner |
|---|---|---|
| Stripe live keys + webhook secret (SouthPoll → Integrations) | renewals by card; webhook for subscriptions | Carson / SPGCA |
| Google Maps browser + Geocoding keys | directory map, pins for 250 migrated farms (Status → Geocode) | Carson |
| Q-01 Stripe subscription export | decide whether any of the 493 old `sub_…` objects should be attached as auto-renew (migration sets auto-renew OFF for everyone) | SPGCA |
| Q-07 member list | confirm the 729 membership rows are the member set; registry contacts stay as accounts with status "none" | SPGCA |
| Review lists from the Local rehearsal (`migrate-farms-*.md`: `address-review`, `untitled-farm`; `migrate-listings-*.md`: `category-steers`) | staff fix names/addresses after cutover in SouthPoll → Farms | SPGCA office |

## 1. Staging rehearsal (WP Engine staging copy, fresh from production)

1. Copy production → staging. Activate SouthPoll Membership (migrations run on activation; check SouthPoll → Status: schema up to date, 18 tables, cron scheduled).
2. Enter **test** Stripe keys and the Google keys on Integrations; send the test email.
3. Run the migration in order (via WP-CLI `wp eval-file` or the scripts adapted for the host): users → farms → memberships → listings → redirects, then `migrate-verify.php`. Expected parity (verified on the Local rehearsal of 2026-10-20, 10/10 checks OK; active/expired split moves with the date): 10,011 users → profiles; 250 stores → 250 farms (+ 479 minimal farms for members without a store; 184 logos + 136 banners); 729 memberships (481 active / 248 expired); 949 succeeded payments / $57,501 (17 orphan test orders without a membership and 14 abandoned orders are skipped by design); 100 listings (53 live, 37 sold, 10 expired), 314 photos, 4,740 stats rows; 584 exact redirect paths + the pattern rules. Review lists to hand to the office: 5 `address-review`, 44 `untitled-farm`, 7 `category-steers`, 2 `spam-name`, 1 duplicate e-mail.
4. Status → "Geocode farms missing coordinates" (100 per click) until the count is 0.
5. Spot-check 10 farm pages, 10 listings, the directory map, a member login (password reset flow), My Farm, My Listings, a renewal in Stripe test mode, an offline claim + approval, the admin screens.
6. Enable `legacy_redirects_enabled` (SouthPoll → Settings → Advanced, or `sp-integrations.php setting legacy_redirects_enabled 1`) and run `migrate-redirects.php --test`; then curl 20 old URLs from `redirect-map.csv` on staging expecting 301s.
7. Decommission on staging exactly as in §3; retest everything in §5.

## 2. Production cutover window (≈ 2 hours, low-traffic evening)

1. Announce the window (members see a maintenance notice on /my-account/ pages). Put the site in maintenance mode.
2. **Backups**: WP Engine backup point + `wp db export` + a copy of `uploads/classified-listing/`. Export the RTCL CSVs (core exporter) as a second snapshot.
3. Activate the plugin (schema + seeds). Enter **live** Stripe keys + webhook secret; register the webhook URL shown on Integrations in the Stripe Dashboard (events listed on that screen). Enter the Google keys.
3b. **Purge spam registrations before migrating** — `purge-spam-users.php` (dry run, read the KEEP list, then `--delete`). On the
   2026-10-27 Local copy this removed 8,859 bot accounts (two floods: Apr–May 2025 with random-case names, and Jul 2025 onwards
   with lowercase gibberish + junk in the breeder-number field) and left ~1,170 real accounts. Doing it first keeps the bots out
   of `sp_members` and out of the parity counts; running it afterwards works too (the plugin's user guards clean the profile).
4. Run the migration (same order), then `migrate-verify.php`; counts must match the staging rehearsal (user counts will be lower than
   the Session 10 figures by the number of purged accounts).
5. Geocode farms (Status page) — budget ≈ 750 geocoding calls.
6. Flip `legacy_redirects_enabled` on. Swap page content: page 71 (Classifieds) → `[classifieds]`, page 49 (Members) → `[member-directory]`; update the theme menu links (Classifieds, Members, My Account → `/members/login/`).
7. Decommission (§3). Leave maintenance mode. Smoke-test §1.5 on production.
8. Send the member announcement (Phase 6 copy): new login at /members/login/, "reset your password" link, what changed.

## 3. Decommission list

- Deactivate: Classified Listing, Classified Listing Pro, Classified Listing Store (keep the files for 30 days, then delete).
- mu-plugins: remove the South Poll RTCL customisations (inventory in `research/03-classified-listing-analysis.md` §6).
- Child theme: remove RTCL template overrides and functions (same inventory); keep the theme otherwise.
- Retire the server-level 301 rule that currently covers `/my-account/my-store/` (WP Engine redirect rules) — the plugin's redirect table replaces it.
- Stripe: remove the old RTCL webhook endpoint; keep the old `sub_…` subscriptions untouched until Q-01 is answered (cancel them in bulk only if SPGCA confirms they are not charging).
- WP Engine Smart Plugin Manager: exclude `southpoll-membership` from auto-updates (it is not in the .org repo).
- Cron: confirm `sp_hourly`, `sp_daily`, `sp_queue` run on the host (Status page shows last run); add a real cron hit if WP-Cron is disabled.

## 4. Rollback

Within the window: restore the WP Engine backup point (database + files), reactivate the RTCL plugins, revert the two page edits and menu links, disable the plugin. The migration never deletes RTCL data, so a partial run can also be undone with `migrate-reset.php --yes` without a restore.

## 5. After launch (first 2 weeks)

- Daily: Status page (cron, webhook, log), Memberships → Claims queue, admin notices for failed payments.
- Watch `sp_redirects.hits` and the 404 log for old URLs the map missed; add rows via SQL or a follow-up script.
- Staff pass through the review lists (addresses, untitled farms, steers → feeders/finishers).
