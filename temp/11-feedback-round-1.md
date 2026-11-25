# Feedback round 1 — soft-launch week 1 (2026-11-19 → 2026-11-25)

Status: **intake pending.** Started 2026-11-25. `temp/feedback/` held only `.gitkeep` and no items were pasted, so the
table below has only the carry-overs from `temp/10-code-review.md`. Member items go in when Carson drops the export at
`temp/feedback/2026-11-25.csv` (SouthPoll → Feedback → Export CSV; columns `id, received_utc, status, name, email,
user_id, page_url, user_agent, message, screenshot_url, notes`) or pastes them, together with anything Kathy / Paul /
Nathan said outside the button.

## Items

| id | who | screen | expected | actual | severity | decision | step |
|---|---|---|---|---|---|---|---|
| F1.1 | — (code review, finding 3) | Member directory, classifieds, map, farm page | An auto-renewing farm stays visible while Stripe pays the renewal invoice | The four visibility queries use `ends_at > now`, so the farm (and its listings) drop out for minutes to hours after `ends_at` until the invoice settles; cron grace (3 days) already covers e-mails and the digest | normal | **needs Carson** — proposed fix now (first auto-renewals fall due in December) | R1.1 |
| F1.2 | — (code review residual) | Feedback button | Screenshots uploaded but never submitted are cleaned up | Orphan attachments stay in the media library | low | next round unless volume shows up in Media | R1.2 |
| F1.3 | — (T6.3 follow-up) | `/website-faq/` | Old links land on the Help Center | Page deleted on production 2026-11-19; 31 legacy redirect rows and external links 404 | normal | **Carson, production only**: add a 301 `/website-faq/` → `/members/help/` in the 301 Redirects plugin (no code) | R1.3 |
| F1.4+ | (from the CSV / notes) | | | | | | |

Severity scale: blocker / high / normal / low / idea. Decision: fix now / next round / won't / needs Carson.

## Decisions (reversible — say so if wrong)
- R1.1 is the only candidate that touches code; it waits for Carson's go because the brief said triage first. Plan if
  accepted: give `ListingVisibility` / the farm directory, listing, pin and farm-page queries the same
  `AUTO_RENEW_GRACE_DAYS` window as `MembershipCron` (`ends_at > now OR (auto_renew = 1 AND ends_at > now − 3 days)`), with
  checks in the render and phase-3 suites. No schema change.
- R1.3 is a production setting, not a code change; it is listed so the round file records it.

## Progress log
- 2026-11-25 — file opened; intake not yet available; residuals triaged. Next: Carson drops the CSV / pastes the notes,
  Claude fills F1.4+, Carson confirms the decisions, then the "fix now" steps are built on Local.
