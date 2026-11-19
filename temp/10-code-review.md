# Pre-launch code review — 2026-11-19 (Session 13, Carson's item 9)

Review run by a read-only subagent over `wp-content/plugins/southpoll-membership` (security + correctness, 204 file reads).
Result: **1 critical, 4 high, 1 medium, 9 low**. Every finding was verified against the code and fixed the same session;
the seven suites were re-run afterwards (34 / 40 / 40 / 29 / 97 / 121 / 160 green). Residual risks are listed at the end.

| # | Sev | Finding | Fix (file) |
|---|---|---|---|
| 1 | critical | `PATCH /sp/v1/admin/users/{id}` let an **editor change an administrator's e-mail** (→ password reset → takeover); `require_staff` = `sp_manage`, which editors hold | `update()` and `action()` refuse targets that hold `sp_manage` / `manage_options` unless the caller can `edit_user` + `promote_users` (`Rest/Admin/AdminUsersController.php`). Admin-suite check "editor cannot change an administrator (403)" |
| 2 | high | **Double fulfilment** of a Stripe checkout: success URL and webhook both passed the non-atomic `status === succeeded` guard → term extended twice, receipts twice | `PaymentRepository::claim()` flips the row to `processing` with `UPDATE … WHERE status NOT IN ('succeeded','processing')`; `CheckoutService::fulfil_session` continues only when it won the claim, otherwise returns `already` / `pending` |
| 3 | high | **Every auto-renewal first expired**: the hourly cron expired `active` rows at `ends_at` while Stripe pays the renewal invoice ~1 h later → "expired" e-mails, farm hidden, digest REMOVE then ADD | `MembershipCron::AUTO_RENEW_GRACE_DAYS = 3`: auto-renewing rows are skipped by the expiry step for 3 days past `ends_at` (`Memberships/MembershipCron.php`). Residual: public visibility SQL still hides the farm between `ends_at` and the paid invoice (minutes to an hour) — accepted |
| 4 | high | **Log files web-readable on nginx** (`uploads/sp-logs/sp-YYYY-MM-DD.log`, only `.htaccess`-protected) with recipient and farm addresses | Log dir is now `uploads/sp-logs-<24-char token>` (token in option `sp_log_dir_token`, `Core/Logger.php`); `Mailer` logs masked recipients (`j***@domain`); old `sp-logs/` removed on Local; `wp-content/uploads/sp-logs*/` added to `.wpe-push-ignore` |
| 5 | high | Verified buyers could **favorite (and read) other farms' draft / withdrawn / expired listings** via `favorites/toggle` (`listing_exists` only checked existence) | `ListingVisibility::listing_exists` requires `is_public()` + an active farm membership. Phase-3 check "buyer cannot favorite another farm's draft listing (404)" |
| 6 | medium | `uninstall.php` fataled when `delete_data_on_uninstall` is on (`Service` interface not loaded); digest cron not cleared | requires `Core/Service.php`; clears `sp_registry_digest`; deletes `sp_log_dir_token` |
| 7 | low | Admin notice e-mails lost addresses written as `<user@x>` (stripped by `wp_kses_post`) in 5 places | `' &lt;' . esc_html( $email ) . '&gt;'` in `MembershipCron`, `CheckoutService`, `OfflineClaims`, `ListingVisibility`, `InquiryService` |
| 8 | low | Minimum fill time skippable by omitting `elapsed` (feedback + inquiries) | guests must send `elapsed` ≥ 3 s (`Feedback/FeedbackService.php`, `Contact/InquiryService.php`) |
| 9 | low | CSV formula injection in the feedback export | `=+-@` / tab / CR prefixes neutralised (`Admin/Pages/FeedbackPage.php`) |
| 10 | low | Any member could mark another member's pending Stripe payment "failed" via `?payment=` on the cancel URL | `cancel_session( $id, $user_id )` checks ownership (`CheckoutReturn` passes the context user) |
| 11 | low | `claim()` passed a nullable tier into `OfflineClaims::submit( object … )` → TypeError when no tier exists | explicit `WP_Error` "No membership option is available" (`Frontend/Sections/ManageMembership.php`) |
| 12 | low | Alert de-dupe needle `"listing_id":12` also matched 120–129 | needle now ends with the comma (`Engagement/AlertDispatcher.php`) |
| 13 | low | No per-IP throttle on registration / lost password (each sends e-mail) | `AuthController::ip_limited()`: 10 registrations and 8 reset requests per IP per hour |
| 14 | low | Feedback screenshot upload open to unverified / suspended accounts, 20 × 5 MB per hour | verified, non-suspended only; 10 per hour (`Rest/FeedbackController.php`) |
| 15 | low | Webhook mode-mismatch events were recorded before the mode check, so they could never be replayed | event is recorded only after the livemode check (`Frontend/Controllers/WebhookController.php`) |

Areas the reviewer cleared with no findings: SQL (all `prepare`d / whitelisted sorts), REST per-object authorization for
listings, farm media/users, favorites, saved searches, feedback, settings; nonces on every classic form; `wp_safe_redirect`
everywhere; template escaping and JS DOM building (`SP.escape`, `textContent`); uploads (`wp_check_filetype_and_ext` +
`getimagesize` + random names + ownership meta); guest disclosure (contact details gated, pins rounded); Stripe signature
verification + event de-dupe; cron idempotency; e-mail queue; invite matching; lockout; no debug paths in production.

## Residual / follow-ups
- Finding 3: during the Stripe settlement window the directory and classifieds still hide an auto-renewing farm (SQL uses
  `ends_at > now`); the e-mail/digest churn is gone. A full fix needs the four visibility queries to honour the grace too.
- reCAPTCHA keys came across from the old site with the production pull; their version (v2 vs v3) is unknown, so
  `recaptcha_enabled` is **off on Local** until Carson confirms v3 keys (walk-through item 7). Without captcha the guest
  forms rely on honeypot + fill time + per-IP limits.
- Orphan feedback screenshots (uploaded, never submitted) are not purged; low volume expected.

## Web-root hygiene (Carson's item 9)
- `.wpe-push-ignore` (honoured by Local → Connect → Push) now lists `temp/`, `.claude/`, `*.csv`, `local-xdebuginfo.php`,
  `wp-content/uploads/sp-logs*/`. The registry CSVs were moved from the web root to `temp/migration/registry/`.
- On Local, `/temp/` answers 403 and a direct file path inside it 200 (nginx serves static files); nothing in `temp/` reaches
  production because of the ignore file. Post-push check in `08-launch-setup.md` S15: `https://southpoll.com/temp/` and
  `/temp/00-README.md` must both be 404.
