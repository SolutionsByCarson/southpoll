---
name: feedback-round
description: Run one round of post-launch feedback for the SouthPoll member portal - intake from SouthPoll → Feedback, triage into a numbered plan, build the accepted items on Local with tests and logs, hand off to /wpe-rollout. Use when Carson says "next round of feedback", "triage the feedback", "fix what came in", or pastes feedback items.
---

# Feedback round (SouthPoll member portal)

Read `CLAUDE.md` (web root) first if this session has not; it names the docs, rules, suites and the release path.

## 1. Intake
- Source of truth: production wp-admin → SouthPoll → **Feedback** (status new / seen / closed, "Export CSV"). Carson exports
  the CSV into `temp/feedback/<YYYY-MM-DD>.csv` (git-ignored, may hold member names) or pastes the items into chat. The same
  submissions were e-mailed to carson@solutionsbycarson.com. Also ask for anything Kathy / Paul / Nathan said outside the button.
- Each row carries: who (name, e-mail, account role), page URL, browser, message, screenshot URL, status, notes.

## 2. Triage → `temp/11-feedback-round-<n>.md`
Create the file with: the date range and CSV name; a table with one row per item — `id`, who, screen, expected, actual,
severity (blocker / high / normal / low / idea), decision (fix now / next round / won't / needs Carson), step id `R<n>.<k>`;
then a "Decisions" list (reversible, say so if wrong) and a "Progress log" like `08-soft-launch-plan.md` §3.
Group duplicates. Check `temp/10-code-review.md` residuals and `temp/07-open-questions.md` before calling anything new.
Mirror the accepted steps in `temp/03-build-todo.md` under a new "Feedback round <n>" heading.

## 3. Build each accepted step on Local
For every `R<n>.<k>`: name the touchpoints (files) before editing; keep the `.sp-` CSS/token conventions and WCAG AA; add or
extend a check in the relevant suite (`temp/scripts/test-phase*.php`); run `./wp-run.sh sp-plugin.php lint` and the suite(s)
touched; append the result to the round file's progress log and tick the todo. Data or schema changes go into
`Core\Migrator::data_steps()` under a new `SP_DB_VERSION`, never into a Local-only script, because production is updated only
by the files push. Settings changes are instructions for Carson (production wp-admin), not Local edits.
To reproduce with real data, ask Carson for a production **database** pull, then on Local run
`./wp-run.sh sp-integrations.php set stripe.mode test` and confirm `wp-content/mu-plugins/zz-local-mail-guard.php` exists.

## 4. Close the round
Full regression (seven suites in order, contrast if CSS tokens changed, JS balance if JS changed), a dated entry in
`temp/06-build-log.md`, commit + push to GitHub (`main`), then invoke `/wpe-rollout`.
Report per step: what changed, what was tested, what is next.
