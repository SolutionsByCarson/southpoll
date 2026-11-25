---
name: wpe-rollout
description: Release checklist for pushing SouthPoll plugin changes from Local to WP Engine production after launch (files only, never the database), with the pre-push gate, what Carson does in Local Connect and wp-admin, and the read-only verification Claude runs afterwards. Use when Carson says "roll this out", "push to production", "release", or after a feedback round is built.
---

# Rollout to WP Engine (post-launch)

Production holds the real data since 2026-11-19. **Code goes up as files; the database never goes up again.**

## A. Gate (Claude, on Local)
1. `./wp-run.sh sp-plugin.php lint`; seven suites green in order (auth → render → payments → cron → phase2 → phase3 → phase4);
   `contrast-check.php --shipped` if `sp-tokens.css` / `sp-ui.css` changed; `js-balance-check.php` if `assets/js` changed.
2. Bump `SP_VERSION` in `wp-content/plugins/southpoll-membership/southpoll-membership.php` (asset cache-buster). Bump
   `SP_DB_VERSION` too if `Core\Schema` or `Core\Migrator::data_steps()` changed, and make the data step idempotent.
3. No test data on Local matters (the DB is not pushed), but `temp/`, `.claude/`, `CLAUDE.md`, CSVs, logs and
   `zz-local-mail-guard.php` must still be in `.wpe-push-ignore` — check the file.
4. Dated entry in `temp/06-build-log.md` with the release contents; commit + push to GitHub `main`.
5. Tell Carson: version numbers, what changed for members/office, any setting he must change in production wp-admin after the
   push (name the screen and the value), and whether `launch_popup_version` should be bumped to re-show the popup.

## B. Push (Carson)
1. WP Engine portal → Production → Backup points → create a manual backup named for the release.
2. Local → site → Connect → **Push to WP Engine** → Production → **files only** (uncheck the database). Include uploads only if
   image sizes were regenerated on Local; normally not needed.
3. WP Engine → Caching → clear all caches. If routes were added: wp-admin → Settings → Permalinks → Save.
4. Apply any settings Claude listed (SouthPoll → Settings / Integrations). Tell Claude "pushed".

## C. Verify (Claude, read-only HTTPS)
`curl -sk -o /dev/null -w "%{http_code} %{redirect_url}\n"` on: `/`, `/members/login/` (grep `sp-launch-notice`,
`sp-feedback-fab`), `/classifieds/`, `/classifieds/cows/`, one farm page, one listing, `/listing-category/heifers/` (301),
`/my-account/` (301), `/temp/` and `/temp/00-README.md` (both 404), `/wp-json/sp/v1/ping`. Confirm the asset URLs carry the new
`ver=` and that no page shows a PHP warning. Ask Carson to open SouthPoll → Status (cron next runs, webhook health, log tail)
and SouthPoll → Feedback. If anything is wrong: Carson restores the WP Engine backup point; Claude fixes on Local and the
gate runs again.

## D. Log
Append the verification result to `temp/06-build-log.md`; note the production version in `CLAUDE.md` ("Current production
state") so the next session starts from the truth.
