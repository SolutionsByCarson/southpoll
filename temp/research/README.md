# research/ — reference material only

Everything in this folder describes the **current** site and the Classified Listing plugin family being replaced. It is background
for migration and parity checks. It is **not** a specification: the plugin design document that Carson supplies at the top level of
`temp/` dictates what gets built and how. Where this material and the design document disagree, the design document wins.

| File | Content |
|---|---|
| `02-site-architecture.md` | Environments, WP, theme, plugins, pages/URLs, menus, forms, cron, uploads, DB tables (as of 2026-10-18) |
| `03-classified-listing-analysis.md` | Data model, settings, user flows, Stripe, emails, customizations, defects, parity matrix for the RTCL plugins |
| `04-data-inventory-and-migration.md` | Live data counts, data-quality flags, draft field mapping, migration outline |
| `db-inventory-raw.md` | Generated: full option/post/meta/taxonomy/payment inventory (secrets masked) |
| `db-forms-subs-raw.md` | Generated: form-builder fields, subscriptions, stats keys, roles, user cross-checks |
| `db-theme-gf-raw.md` | Generated: theme design options, Cornerstone usage, Gravity Forms fields, key page text |
| `db-payments-detail-raw.md` | Generated: payments by gateway/pricing/year, renewal patterns, member meta coverage, import CSV headers |
| `db-renewals-raw.md` | Generated: Stripe recurring-charge check |
| `08-rtcl-urls-and-endpoints.md` | Generated: every URL/endpoint the RTCL plugins exposed (pages, account/checkout endpoints, archives, all 100 listing + 250 farm URLs with old slugs, API routes, 175 cached rewrite rules, stale 404 paths, existing 301s) — basis for the cutover redirect map |
| `rtcl-urls.csv` | Generated: the same inventory as one row per URL/pattern with an empty `new_url` column to fill in when the new routes exist |

Regenerate the `db-*` files with the scripts in `../scripts/` (see `../scripts/README.md`).
