# Feedback round 1 — soft-launch week 1 (2026-11-19 → 2026-11-25)

Intake: `temp/feedback/2026-11-25.csv` (SouthPoll → Feedback export `southpoll-feedback-20261125-185913.csv`, 5 rows, ids 12–16).
Carson: no other notes from Kathy / Paul / Nathan. Screenshots fetched from the public upload URLs and read on 2026-11-25.
Carry-overs from `temp/10-code-review.md` residuals and `03-build-todo.md` listed after the member items.

## Items

| id | who | screen | expected | actual | severity | decision | step |
|---|---|---|---|---|---|---|---|
| F1.1 | Kathy (South Poll Cattle, user 12, farm 253), feedback 14–16, 2026-11-20 | Manage Membership → checkout, receipt e-mail, payment history | One $100 payment extends the term by one year (her term already ran to 2027-10-14 from the October 2026 pre-paid order, so the new term is 2027-10-14 → 2028-10-14, by design D-27) | Two paid rows for one checkout: "Card (Stripe)" 2027-10-14 → 2028-10-13 **and** "Card (Stripe) (auto)" 2028-10-13 → 2029-10-13; status shows current term to **2029-10-13**, 1,058 days, auto-renew **On** although she bought a one-time membership. Cause: the one-time Checkout Session is created with `invoice_creation` on, so Stripe also sends `invoice.paid` (billing reason `manual`, no subscription) for it; `CheckoutService::on_invoice_paid` only exempts `subscription_create`, matched the membership by customer id and ran the recurring-renewal path (second row, second +365 days, auto_renew = 1, second receipt, admin "renewal" notice, digest RENEWED event). One real charge (`pi_3UdrmsCcNYBVy0a61gR5YQe2`), to be confirmed in Stripe | **blocker** (every one-time card payment on production does this) | **fix now** | R1.1 code, R1.2 data repair |
| F1.2 | Kathy, feedback 13 | Feedback button, Help Center | — | "Testing the feedback section; e-mailed questions about the payment test" | — | no action here; Carson has the e-mail (the questions are F1.1) | — |
| F1.3 | Nathan Hahn (user 310), feedback 12, iPhone Safari | My Listings → new listing → Publish | Publishing without typing a title is refused like any other missing field | The new draft is created with the post title "Untitled listing"; the Ad title box is pre-filled with it, so the 3-character rule passes and the listing goes live as "Untitled listing" | high | **fix now** | R1.3 |
| F1.4 | code review finding 3 residual | Directory, classifieds, map, farm page | Auto-renewing farm stays visible while Stripe settles the renewal invoice | Four visibility queries use `ends_at > now`; farm hidden for minutes to hours after `ends_at`. No auto-renewing subscription exists on production yet (Kathy's flag is the F1.1 side effect) | normal | next round (round 2, before the first real auto-renewal falls due) | R1.4 |
| F1.5 | code review residual | Feedback screenshots | Orphan uploads purged | Never purged; 4 uploads so far | low | next round | R1.5 |
| F1.6 | T6.3 follow-up | `/website-faq/` | Old links reach the Help Center | Page deleted on production 2026-11-19 → 404 for 31 legacy redirect rows and external links | normal | **Carson, production only**: 301 `/website-faq/` → `/members/help/` in the 301 Redirects plugin | R1.6 |

Severity: blocker / high / normal / low / idea. Decision: fix now / next round / won't / needs Carson.

## Steps

- **R1.1 Webhook: a one-time Checkout invoice must never count as a renewal.** `Payments/CheckoutService`: `on_invoice_paid`
  extends the term only for `billing_reason = subscription_cycle` with a known subscription; `manual` / `subscription_create` /
  any invoice whose payment intent already belongs to a payment row just gets its invoice id attached (`one_time_invoice` /
  `first_invoice` / `already`). `fulfil_session` stores the session's `invoice` id for payment-mode sessions so
  `find_by_invoice` de-dupes too. Suite: `test-phase1-payments.php` §5 gains the `manual` case and the intent-match case.
- **R1.2 Data repair on production (and Local): roll back phantom renewals.** `Core\Migrator` data step `0.5.1`
  (`SP_DB_VERSION` 0.5.1) → `Migrator::repair_phantom_renewals()`: every `sp_payments` row with `method = stripe`,
  `recurring = 1`, empty `stripe_subscription_id` and the "Automatic renewal (invoice …)" note is voided (status `void`,
  note), its membership's `ends_at` is set back to the row's `term_start` (= the end date before the phantom extension) when
  it still equals the row's `term_end`, and `auto_renew` is cleared when the membership has no Stripe subscription. Logged
  per row. Idempotent. Covered by a new check in the payments suite (a planted phantom row is repaired, a real renewal is
  left alone). On production this fixes Kathy's farm 253 on the first request after the files land.
- **R1.3 Listings: no publishing with the placeholder title.** `Listing::is_untitled()`; `ListingEditor::save` rejects the
  placeholder like an empty title; `Frontend/Sections/EditListing` shows an empty Ad-title box (placeholder visible) for an
  untitled draft; the My Listings table shows "(untitled draft)". Suite: `test-phase3-listings.php` §2.
- R1.4 / R1.5: round 2. R1.6: Carson, no code.

## Decisions (reversible — say so if wrong)
- Kathy's term after the repair: **2025-09-05 → 2028-10-14** (her October 2026 pre-paid year + the $100 paid on 2026-11-20),
  auto-renew **off**. If the office would rather refund the $100 (she paid a year early), that is a Stripe Dashboard refund;
  `charge.refunded` then marks the row refunded but does **not** shorten the term — tell Claude and R1.2 can do that too.
- `invoice.paid` with billing reasons other than `subscription_cycle` never extends a term. `subscription_update` /
  `subscription_threshold` cannot occur with this integration (one price, no mid-cycle changes).
- Voided phantom rows stay in the payment history as "Void" (not deleted) so the audit trail matches Stripe.
- The listing placeholder fix does not touch listings already published as "Untitled listing"; Carson checks
  SouthPoll → Listings for any and tells the owner (Nathan's test is probably one).

## Progress log
- 2026-11-25 — file opened with carry-overs; intake missing.
- 2026-11-25 — CSV received (5 rows) and triaged; cause of F1.1 traced in `CheckoutService::on_invoice_paid` and confirmed
  against Kathy's Local snapshot (term ended 2027-10-14 before the payment). Building R1.1 → R1.3.
- 2026-11-25 — **R1.1 built**: `CheckoutService::on_invoice_paid` — invoice whose payment intent already belongs to a payment
  row → `already` (invoice id attached); `subscription_create` → `first_invoice` (unchanged); anything that is not
  `subscription_cycle` with a subscription id → `not_a_renewal` (logged). `fulfil_session` stores the session's `invoice`
  id for payment-mode sessions. Touchpoints: `src/Payments/CheckoutService.php`. The new §5b checks reproduced the bug
  first (three `renewed` results, three phantom rows) before the guard was in place.
- 2026-11-25 — **R1.2 built**: `Migrator::repair_phantom_renewals()` + data step `0.5.1`, `SP_DB_VERSION` 0.5.1,
  `SP_VERSION` 0.3.1; payment status `void` (label "Void", admin filter option). Touchpoints: `src/Core/Migrator.php`,
  `southpoll-membership.php`, `src/Payments/PaymentRepository.php`, `src/Admin/Pages/MembershipsPage.php`. Suite §5c plants a
  phantom row, repairs it, re-runs (0). **Rehearsal on Local** (backup `app/backups/20261125-191729-before-round1-kathy-rehearsal.sql.gz`):
  Kathy's farm 253 planted exactly as production left it (checkout row 2027-10-14 → 2028-10-13, phantom row → 2029-10-13,
  auto-renew on) → repair: `ends_at` 2028-10-13 07:04:26, status active, auto-renew 0, phantom → Void, checkout row → Paid,
  membership note written, second run 0. Local keeps that state (it mirrors what production will show after the push).
- 2026-11-25 — **R1.3 built**: `Listing::is_untitled()`, `ListingEditor::save` refuses the placeholder title, `EditListing`
  shows an empty Ad-title box for an untitled draft, `ListingPresenter` adds `untitled`, My Listings prints "(untitled
  draft)". Touchpoints: `src/Listings/Listing.php`, `ListingEditor.php`, `ListingPresenter.php`,
  `src/Frontend/Sections/EditListing.php`, `templates/dashboard/my-listings.php`. Suite §2 + 2 checks.
- 2026-11-25 — Suites (lint clean): auth 34 / render 40 / **payments 50** / cron 29 / farms 97 / **listings 123** / admin 160.
  `test-phase2-farms.php` had two environment failures unrelated to the round (Local has carried a real geocoding key and a
  fully geocoded farm table since 2026-11-19): the suite now starts with the key blanked (restored on shutdown) and gives
  `backfill()` a candidate farm. No CSS/JS touched → no contrast / JS-balance run needed.
- Next: commit + push to GitHub, `/wpe-rollout` (files only). After the push Carson checks farm 253 in SouthPoll →
  Memberships (term to 2028-10-13, auto-renew off, second payment row Void) and confirms in Stripe that 2026-11-20 holds
  one $100 charge for customer `cus_S1MqirYprJon19`.
- 2026-11-25 — **R1.7 (Carson's follow-up)**: Help Center "How do I pay online?" gained a second paragraph — paying before
  the current term ends adds the year to the end of it; the receipt shows the dates covered (the D-27 rule that puzzled
  Kathy). Touchpoint: `templates/dashboard/help.php`; render suite +1 check. Login notice / popup unchanged, no popup version
  bump. Suites after: 34 / **41** / 50 / 29 / 97 / 123 / 160, lint clean. Ready for the files push.
