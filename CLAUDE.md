# SouthPoll.com member portal — working instructions for Claude

Custom WordPress plugin `wp-content/plugins/southpoll-membership` (memberships, farm pages, classifieds, Stripe, e-mail, cron,
admin, feedback) for the South Poll Grass Cattle Association. It replaced the Classified Listing (RadiusTheme) plugin family.
**Live on production (southpoll.com) since 2026-11-19, in soft launch.** Owner: Carson (carson@solutionsbycarson.com).

## Read first, in this order
1. `temp/00-README.md` — knowledge-base rules and folder map (everything about this project lives in `temp/`).
2. `temp/06-build-log.md` — dated log, newest first; the top entries say exactly where things stand.
3. `temp/08-soft-launch-plan.md` — what was built for launch (S1–S15) and the decisions taken.
4. `temp/migration/08-launch-setup.md` — environments, credentials map (no values), post-push checklist.
5. `temp/10-code-review.md` — pre-launch review, fixes, residual risks.
6. `temp/scripts/README.md` — every script, the runner, the seven test suites.
Read `temp/02-architecture-roadmap.md`, `temp/plugin architecture.md` (the spec) and `temp/07-open-questions.md` when a task
needs the design or an open decision.

## Standing rules (Carson's)
- Every fact, log, script and doc goes in `temp/`; append a dated entry to `temp/06-build-log.md` each session (newest first).
- Never write secrets into any file (keys are typed into wp-admin by Carson). Test accounts use `@example.test`.
- PHP is not on PATH: run scripts with `temp/scripts/wp-run.sh <script.php>` (Git Bash); plain-PHP tools use the Local
  binary path in `temp/scripts/README.md`. The site is Local by WP Engine; MySQL on port 10011.
- Before calling a step done: `./wp-run.sh sp-plugin.php lint`, then the seven suites in order (auth → render → payments →
  cron → phase2 → phase3 → phase4; counts as of 2026-11-19: 34 / 40 / 40 / 29 / 97 / 121 / 160), `contrast-check.php --shipped`
  after touching `sp-tokens.css`/`sp-ui.css`, `js-balance-check.php` after touching `assets/js`.
- Verify protected screens in-process (the suites' `$render_section` / `rest_do_request`), never by forging cookies.
- Every button WCAG AA; Alpine.js is bundled, no build step; keep `.sp-` class conventions and `sp-ui.css` tokens.
- `temp/scripts/backup-db.php --label=…` before any destructive data step on Local.
- Keep `temp/08-soft-launch-plan.md` §3 (progress log) and `temp/03-build-todo.md` current as steps finish.
- Report each step with what changed, what was tested, what is next. Do not push to WP Engine or touch production data;
  Carson pushes. Reversible Local work needs no confirmation.

## Environments
| Where | What | Notes |
|---|---|---|
| Local (this folder) | dev copy; DB = production snapshot of 2026-11-19 + launch pipeline | Not the source of truth any more. `.local` host; `wp-content/mu-plugins/zz-local-mail-guard.php` blocks mail to anyone except `@example.test` / `solutionsbycarson.com` (inert off `.local`). |
| Production | https://southpoll.com — live, holds the real data | Pushed from Local via Local → Connect → Push (files + DB once, on 2026-11-19). Claude has no access except public HTTPS. |
| Staging | https://southpollgrstg.wpenginepowered.com/ | Optional rehearsal; stale. |
| GitHub | https://github.com/SolutionsByCarson/southpoll.git (branch `main`) | Tracks the plugin, `pro-child`, `mu-plugins`, this file and `temp/` docs + scripts. Data, reports, CSVs, WordPress core and third-party code are ignored. Commit when a step is done; Carson asked for the repo to hold current state. |

`.wpe-push-ignore` keeps `temp/`, `.claude/`, `CLAUDE.md`, `*.csv`, logs and the mail guard off production; `.wpe-pull-ignore`
keeps a production pull from overwriting them on Local. After the push, `https://southpoll.com/temp/` must be 404.

## After launch: how changes reach production
**Never push the database to production again** — it would overwrite members' registrations, listings and payments.
1. Code (plugin, theme, CSS/JS): change on Local → lint + suites → commit → Carson runs Local → Connect → Push → Production
   → **files only** (the ignore file applies) → Carson clears the WP Engine cache → Claude verifies read-only over HTTPS.
   Bump `SP_VERSION` in `southpoll-membership.php` for every release: it is the asset cache-buster on production.
2. Data / schema changes: put them in `Core\Migrator::data_steps()` keyed by a new `SP_DB_VERSION` (and `Core\Schema` for
   tables). They run once on production on the first request after the files land. The `temp/scripts/*.php` tools are
   Local-only (they need `wp-run.sh`); do not plan on running them against production.
3. Settings and integrations: SouthPoll → Settings / Integrations in production wp-admin, by Carson. Local's copies are
   for testing only.
4. To reproduce a production problem with real data: Carson pulls the production **database** into Local (Local → Connect →
   Pull → Production → database only). Immediately afterwards on Local: `./wp-run.sh sp-integrations.php set stripe.mode test`
   (the pull brings the live Stripe keys), confirm the mail guard is still in `mu-plugins`, and remember the pull resets
   nothing in `temp/`. Take `backup-db.php` first.

## Feedback round (what the next sessions do)
- Intake: production wp-admin → SouthPoll → **Feedback** (table, status new/seen/closed, CSV export via "Export CSV"); every
  submission is also e-mailed to carson@solutionsbycarson.com. Carson exports the CSV and drops it in
  `temp/feedback/<date>.csv` (git-ignored) or pastes the items.
- Triage into `temp/11-feedback-round-<n>.md`: one row per item (who, screen, what they expected, what happened, severity,
  decision, step id), then build the accepted items on Local like the S-steps in `08-soft-launch-plan.md` (each with a
  touchpoints list, tests, progress-log entry), then release per the procedure above.
- Soft-launch switches live in Settings → Launch & feedback (login notice, dashboard popup + version, feedback button).
  Bump `launch_popup_version` to re-show the popup after a release.

## Current production state (2026-11-19 push; verify against the Status page before relying on it)
- Plugin `SP_VERSION` 0.3.0, schema 0.5.0. 1,196 users, 999 farms (876 geocoded; 118 have no usable address), 930 active
  memberships (terms from the registry export, most end 2026-12-31), 93 migrated listings with all 312 photos.
- ON: legacy 301s, login notice, dashboard popup (version `2026-11`), feedback button (→ carson@), registry digest daily 08:00
  Central skipping quiet days (→ registry@southpoll.com), reCAPTCHA v3, e-mail verification. Stripe live (restricted
  `rk_live` key + webhook `https://southpoll.com/sp-webhook/stripe/`), Google Maps + Geocoding, WP Mail SMTP → SendGrid from
  wordpress@southpoll.com.
- Listing classes: Bulls · Cows · Heifers · Steers · Semen (same list for farm offerings). Farm fields: Primary Forage Type,
  Cover Crops, Supplemental Feed, Hay Type, Grazing Style, Additional Info.
- Known residuals: auto-renewing farms can disappear from the directory for up to a few hours between `ends_at` and Stripe's
  renewal invoice (cron grace covers e-mails/digest, not the visibility SQL — see `10-code-review.md`); 37 registry rows had
  no e-mail and no web account (`temp/migration/reports/registry-unmatched-*.csv`, office has it); 72 accounts were created
  from the registry without passwords (they use "Forgot your password?"); the old `/website-faq/` page was deleted —
  recommend a 301 to `/members/help/` in the 301 Redirects plugin; the public FAQ copy is in `temp/migration/09-faq-copy.*`
  if a page is wanted again; 1 spam-review account left (`purge-spam-users.php` REVIEW list).

## Project skills
`.claude/skills/feedback-round/SKILL.md` (triage + build loop) and `.claude/skills/wpe-rollout/SKILL.md` (release checklist).
Invoke with `/feedback-round` and `/wpe-rollout`.
