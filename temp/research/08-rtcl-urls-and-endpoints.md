# Classified Listing URLs & Endpoints (for cutover redirects)

Generated 2026-10-18 23:50 by `scripts/report-rtcl-urls.php`. Paths are relative to `https://southpoll.com`. The CSV twin
`rtcl-urls.csv` has one row per concrete URL/pattern with an empty `new_url` column to fill in once the new plugin's routes exist.

Reference only — the new plugin's own URL design comes from the design document. This file exists so that every old URL a member,
search engine, or email might still hold gets a 301 to the right new page.

## 1. Settings that defined the URL grammar (`rtcl_advanced_settings`)

| Setting | Value | Effect |
|---|---|---|
| `myaccount` page | #337 → `/my-account/` | Account hub + all account endpoints |
| `checkout` page | #336 → `/checkout/` | Checkout + payment endpoints |
| `listing_form` page | #646 → `/submit-a-listing/` | Add/edit listing form |
| `listings` page | (none) | Not set → listings archive falls back to post-type archive `/listings/` |
| `store` page | (none) | Not set → stores archive falls back to `/stores/` |
| `permalink` (listing base) | `rtcl_listing` | Single listing `/rtcl_listing/{slug}/` |
| `category_base` | `listing-category` | `/listing-category/{slug}/` |
| `location_base` | `listing-location` | `/listing-location/{slug}/` |
| `tag_base` | `listing-tag` | `/listing-tag/{slug}/` |
| `permalink_store` | `store` | Farm page `/store/{slug}/` |
| `store_category_base` | `store-category` | `/store-category/{slug}/` |
| `myaccount_store_endpoint` | `my-store` | Farm editor under My Account |

## 2. Plugin pages (WordPress pages holding RTCL shortcodes)

| Path | Page ID | Shortcode / purpose | Redirect target (fill in) |
|---|---|---|---|
| `/my-account/` | 337 | [rtcl_my_account] — login/register (logged out) or dashboard (logged in) | |
| `/checkout/` | 336 | [rtcl_checkout] — no direct use; endpoints below | |
| `/submit-a-listing/` | 646 | [rtcl_listing_form] — add listing | |
| `/classifieds/` | 71 | [rtcl_listings view='list'] + [classifieds_search] (Cornerstone page) | |
| `/members/` | 49 | [member_grid] (child-theme directory shortcode, Cornerstone page) | |
| `/website-faq/` | 880 | Member how-to FAQ (links into my-account endpoints) | |
| `/join-spgca/` | 51 | Join page; "Join Today Via Online" → /website-faq/ | |

## 3. My Account endpoints (`/my-account/{endpoint}/`)

| Path | Setting key | Purpose |
|---|---|---|
| `/my-account/listings/` | `myaccount_listings_endpoint` | My Listings table (edit / mark sold / renew / delete). Paginates `/page/N/`. |
| `/my-account/my-store/` | `myaccount_store_endpoint` | Farm page editor ("Farm" nav item). Renamed to `my-store` on this site. |
| `/my-account/payments/` | `myaccount_payments_endpoint` | Payment history. Paginates `/page/N/`. |
| `/my-account/edit-account/` | `myaccount_edit_account_endpoint` | Account details (name, email, phone, breeder number, password). |
| `/my-account/privacy-settings/` | `myaccount_profile_settings_endpoint` | Profile/privacy settings (show phone/email publicly). |
| `/my-account/favourites/` | `myaccount_favourites_endpoint` | Favourites (feature disabled on this site). |
| `/my-account/lost-password/` | `myaccount_lost_password_endpoint` | Lost/reset password form; reset link appends `?key=…&login=…`. |
| `/my-account/logout/` | `myaccount_logout_endpoint` | Logout (nonce-protected). |
| `/my-account/chat/` | `myaccount_chat_endpoint` | Chat (feature disabled). |
| `/my-account/verify/` | `myaccount_verify` | Email verification (feature disabled). |
| `/my-account/` (logged out) | — | Login + registration forms on one page |
| `/my-account/?action=register` / `#register` | — | Registration deep link used in some emails |

## 4. Checkout endpoints (`/checkout/{endpoint}/[{id}/]`)

| Path | Purpose |
|---|---|
| `/checkout/membership/` | Buy/renew membership — lists pricing options; `?option={pricing_id}` preselects (847 online, 639 pre-paid) |
| `/checkout/submission/` | Pay for a listing submission `/submission/{listing_id}/` (not used — free for members) |
| `/checkout/promote/` | Promote/renew listing `/promote/{listing_id}/` (renew email links point here) |
| `/checkout/payment-receipt/` | Receipt `/payment-receipt/{order_id}/` (linked from order emails and Stripe return URL) |
| `/checkout/payment-failure/` | Failure page `/payment-failure/{order_id}/` |

## 5. Listing form URLs (`/submit-a-listing/`)

| Path | Purpose |
|---|---|
| `/submit-a-listing/` | Add new listing |
| `/submit-a-listing/edit/{listing_id}/` | Edit listing (rewrite `submit-a-listing/([^/]+)/([0-9]+)/` → `rtcl_action`, `rtcl_listing_id`) |
| `/submit-a-listing/?rtcl_action=edit&rtcl_listing={id}` | Non-pretty equivalent |

## 6. Archive URLs

| Path | Source | Count / notes |
|---|---|---|
| `/listings/` (+ `/page/N/`, `/feed/`) | `rtcl_listing` post-type archive (has_archive) | all published listings |
| `/stores/` (+ `/page/N/`) | `store` post-type archive | all farm pages (not linked in nav; /members/ is used instead) |
| `/listing-category/{slug}/` | taxonomy `rtcl_category` (listing category) | 5 terms — listed below |
| `/listing-location/{slug}/` | taxonomy `rtcl_location` (listing location (state)) | 50 terms — listed below |
| `/listing-tag/{slug}/` | taxonomy `rtcl_tag` (listing tag) | 49 terms — listed below |
| `/store-category/{slug}/` | taxonomy `store_category` (store category) | 1 terms — listed below |
| `/listing-category/{cat}/listing-location/{state}/` and `/…/tag/{tag}/` | RTCL combined filter rewrites (`__cat`, `__loc`, `__tag`, `__page` query vars) | pattern only |

### Taxonomy terms

**rtcl_category** (5): `/listing-category/bulls/` (41), `/listing-category/cows/` (25), `/listing-category/heifers/` (17), `/listing-category/semen/` (2), `/listing-category/steers/` (6)

**store_category** (1): `/store-category/south/` (107)

**rtcl_tag** (49): `/listing-tag/steers/` (0), `/listing-tag/00322/` (1), `/listing-tag/00722/` (1), `/listing-tag/1024-l/` (0), `/listing-tag/11/` (0), `/listing-tag/132022/` (1), `/listing-tag/189/` (1), `/listing-tag/2121/` (0), `/listing-tag/2129/` (0), `/listing-tag/2205/` (0), `/listing-tag/23/` (0), `/listing-tag/23-5/` (0), `/listing-tag/2301/` (0), `/listing-tag/2302/` (0), `/listing-tag/2401/` (0), `/listing-tag/2402/` (0), `/listing-tag/2403/` (0), `/listing-tag/2404/` (0), `/listing-tag/2404-2405/` (0), `/listing-tag/2405/` (0), `/listing-tag/2406/` (0), `/listing-tag/2408/` (0), `/listing-tag/2409/` (0), `/listing-tag/2410/` (0), `/listing-tag/2501/` (0), `/listing-tag/2503/` (0), `/listing-tag/2512/` (0), `/listing-tag/4120/` (0), `/listing-tag/49260/` (0), `/listing-tag/537/` (0), `/listing-tag/57114/` (1), `/listing-tag/622/` (0), `/listing-tag/8110g/` (0), `/listing-tag/bull/` (2), `/listing-tag/cassanova/` (0), `/listing-tag/feeders/` (0), `/listing-tag/heifers/` (0), `/listing-tag/j02/` (0), `/listing-tag/k306/` (0), `/listing-tag/l202/` (0), `/listing-tag/m616/` (0), `/listing-tag/pairs/` (0), `/listing-tag/pending/` (0), `/listing-tag/purebred-bulls/` (1), `/listing-tag/shut/` (0), `/listing-tag/south-poll-bull/` (2), `/listing-tag/south-poll-heifers/` (0), `/listing-tag/south-poll-steer/` (0), `/listing-tag/wt12/` (0)

**rtcl_location** (50): `/listing-location/alabama/` (9), `/listing-location/alaska/` (0), `/listing-location/arizona/` (0), `/listing-location/arkansas/` (3), `/listing-location/california/` (0), `/listing-location/colorado/` (0), `/listing-location/connecticut/` (0), `/listing-location/delaware/` (0), `/listing-location/florida/` (0), `/listing-location/georgia/` (3), `/listing-location/hawaii/` (0), `/listing-location/idaho/` (0), `/listing-location/illinois/` (2), `/listing-location/indiana/` (0), `/listing-location/iowa/` (0), `/listing-location/kansas/` (4), `/listing-location/kentucky/` (3), `/listing-location/louisiana/` (0), `/listing-location/maine/` (0), `/listing-location/maryland/` (0), `/listing-location/massachusetts/` (0), `/listing-location/michigan/` (0), `/listing-location/minnesota/` (0), `/listing-location/mississippi/` (3), `/listing-location/missouri/` (18), `/listing-location/montana/` (0), `/listing-location/nebraska/` (0), `/listing-location/nevada/` (0), `/listing-location/new-hampshire/` (0), `/listing-location/new-jersey/` (0), `/listing-location/new-mexico/` (0), `/listing-location/new-york/` (0), `/listing-location/north-carolina/` (1), `/listing-location/north-dakota/` (0), `/listing-location/ohio/` (3), `/listing-location/oklahoma/` (12), `/listing-location/oregon/` (0), `/listing-location/pennsylvania/` (4), `/listing-location/rhode-island/` (0), `/listing-location/south-carolina/` (0), `/listing-location/south-dakota/` (0), `/listing-location/tennessee/` (6), `/listing-location/texas/` (7), `/listing-location/utah/` (0), `/listing-location/vermont/` (0), `/listing-location/virginia/` (13), `/listing-location/washington/` (0), `/listing-location/west-virginia/` (0), `/listing-location/wisconsin/` (0), `/listing-location/wyoming/` (0)

## 7. Single listing URLs (`/rtcl_listing/{slug}/`) — 100 listings

Rewrite also serves `/rtcl_listing/{slug}/{n}/` (paged), `/embed/`, `/feed/`, `/trackback/`, `/attachment/{slug}/`, `/comment-page-N/`. Old slugs
(`_wp_old_slug`) are auto-redirected by WordPress today; the new plugin's redirect map must cover them too.

| ID | Path | Title | Status | Owner | Category | State | Old slugs |
|---|---|---|---|---|---|---|---|
| 1685 | `/rtcl_listing/genesis-520-heifer-plus-and-conventional-semen/` | GENESIS #520 HEIFER PLUS AND CONVENTIONAL SEMEN | publish | 3702 | Semen | Missouri |  |
| 4814 | `/rtcl_listing/fullblood-south-poll-bull/` | Fullblood South Poll Bull | rtcl-expired | 6120 | Bulls | Texas |  |
| 5258 | `/rtcl_listing/south-poll-bulls-2/` | SOUTH POLL BULLS | publish | 3702 | Bulls | Missouri | `/rtcl_listing/rtcl-auto-temp-2/` |
| 5264 | `/rtcl_listing/full-blood-genesis-520-semen/` | FULL BLOOD GENESIS #520 SEMEN | publish | 3702 | Semen | Missouri | `/rtcl_listing/rtcl-auto-temp-2/` |
| 5535 | `/rtcl_listing/fullblood-yearlings/` | Fullblood Yearlings | rtcl-expired | 134 | Bulls | Alabama |  |
| 5540 | `/rtcl_listing/fullblood-yearling/` | Fullblood Yearling | rtcl-expired | 134 | Bulls | Alabama |  |
| 5542 | `/rtcl_listing/open-heifers-for-fall-calving/` | Open Heifers for fall calving. | rtcl-expired | 49 | Heifers | Missouri |  |
| 5544 | `/rtcl_listing/2025-fall-born-spgc-heifers-for-sale/` | 2025 Fall Born SPGC Heifers For Sale | rtcl-expired | 34 | Heifers | Missouri |  |
| 5549 | `/rtcl_listing/registered-50-south-poll-cow-proven-young-cow/` | Registered 50% South Poll Cow – Proven Young Cow | rtcl-expired | 358 | Cows | Virginia |  |
| 5553 | `/rtcl_listing/selling-our-full-blood-south-poll-bull-named-vento-14-months-old-sw-missouri/` | Selling our Full Blood South Poll Bull named Vento, 14 months old, SW Missouri | rtcl-expired | 364 | Bulls | Missouri |  |
| 5595 | `/rtcl_listing/south-poll-steers/` | South Poll Steers | rtcl-expired | 295 | Steers | Alabama |  |
| 5609 | `/rtcl_listing/three-year-old-proven-fullblood-sp-bull-for-sale/` | Pending Sale-- Three Year Old Proven Fullblood SP Bull For Sale | rtcl-expired | 100 | Bulls | Missouri |  |
| 5625 | `/rtcl_listing/south-poll-registered-cow/` | South Poll Registered Cow | publish | 11327 | Cows | Tennessee |  |
| 5633 | `/rtcl_listing/full-blood-bull-2/` | 13 month Full Blood Bull | publish | 237 | Bulls | Texas |  |
| 5650 | `/rtcl_listing/cow-calf-pair/` | Cow calf pair | publish | 3631 | Cows | Kansas |  |
| 5654 | `/rtcl_listing/cow-calf-pair-2/` | Cow calf pair | publish | 3631 | Cows | Kansas |  |
| 5673 | `/rtcl_listing/9-high-percentage-south-poll-heifers-available/` | 9 high percentage south poll heifers available | publish | 10959 | Heifers | Missouri |  |
| 5684 | `/rtcl_listing/registered-full-blood-cow-calf-pair/` | Registered Full-Blood Cow/calf Pair | publish | 141 | Cows | Virginia |  |
| 5693 | `/rtcl_listing/registered-full-blood-cow-calf-pair-2/` | Registered Full-Blood Cow/calf Pair | publish | 141 | Cows | Virginia |  |
| 5697 | `/rtcl_listing/registered-purebred-cow-calf-pair/` | Registered Purebred Cow/calf Pair | publish | 141 | Cows | Virginia |  |
| 5702 | `/rtcl_listing/registered-purebred-cow-calf-pair-2/` | Registered Purebred Cow/calf Pair | publish | 141 | Cows | Virginia |  |
| 5733 | `/rtcl_listing/registered-purebred-cow-calf-pair-3/` | Registered Purebred Cow/calf Pair | publish | 141 | Cows | Virginia |  |
| 5737 | `/rtcl_listing/registered-full-blood-cow-calf-pair-3/` | Registered Full-Blood Cow/calf Pair | publish | 141 | Cows | Virginia |  |
| 5741 | `/rtcl_listing/registered-purebred-cow-calf-pair-4/` | Registered Purebred Cow/calf Pair | publish | 141 | Cows | Virginia |  |
| 5744 | `/rtcl_listing/registered-full-blood-cow-calf-pair-4/` | Registered Full-Blood Cow/calf Pair | publish | 141 | Cows | Virginia |  |
| 5748 | `/rtcl_listing/registered-full-blood-bred-cow-with-last-years-heifer-calf/` | Registered Full-Blood Bred Cow with last year’s Open Full-Blood Heifer Calf | publish | 141 | Cows | Virginia |  |
| 5751 | `/rtcl_listing/registered-full-blood-bred-cow-with-last-years-open-full-blood-heifer-calf/` | Registered Full-Blood Bred Cow with last year’s Open Full-Blood Heifer Calf | publish | 141 | Cows | Virginia |  |
| 5756 | `/rtcl_listing/open-full-blood-heifer/` | Open Full-Blood Heifer | publish | 141 | Heifers | Virginia |  |
| 5765 | `/rtcl_listing/southpoll-yearling-bulls/` | Southpoll yearling bulls | publish | 11342 | Bulls | Ohio |  |
| 5774 | `/rtcl_listing/full-blood-registered-bull-great-bloodlines/` | *SOLD* Full Blood Registered Bull great bloodlines! | publish | 2747 | Bulls | Georgia |  |
| 5848 | `/rtcl_listing/fall-bred-cows/` | Fall bred cows | publish | 533 | Cows | Tennessee | `/rtcl_listing/rtcl-auto-temp-3/` |
| 5876 | `/rtcl_listing/fullblood-2-yr-old-bull-very-slick-holiday-bloodlines/` | FULLBLOOD 2 YR OLD BULL , VERY SLICK, HOLIDAY BLOODLINES. Tag#2401 Reg#54796 | publish | 5791 | Bulls | Oklahoma |  |
| 5881 | `/rtcl_listing/fullblood-herd-sire-4-yr-old/` | FULLBLOOD HERD SIRE 4 YR OLD Tag # T154. Reg # 48051 | publish | 5791 | Bulls | Oklahoma |  |
| 5975 | `/rtcl_listing/fullblood-bred-heifer/` | Fullblood bred heifer | publish | 116 | Heifers | Illinois |  |
| 5980 | `/rtcl_listing/fullblood-cow-heifer-calf-pair/` | FULLBLOOD COW / HEIFER CALF PAIR | publish | 1582 | Cows | Alabama |  |
| 5991 | `/rtcl_listing/4-year-old-herd-bull/` | 4-Year Old Herd Bull | publish | 409 | Bulls | Oklahoma |  |
| 5994 | `/rtcl_listing/purebred-south-poll-bull-2/` | Purebred South Poll Bull | publish | 409 | Bulls | Oklahoma |  |
| 6009 | `/rtcl_listing/full-blood-south-poll-bull/` | Full Blood South Poll Bull | publish | 380 | Bulls | Mississippi |  |
| 6016 | `/rtcl_listing/50-south-poll-heifers/` | 50% South Poll Heifers | publish | 295 | Heifers | Alabama |  |
| 6021 | `/rtcl_listing/percentage-cow-calf-pair/` | Percentage Cow/Calf Pair | publish | 224 | Cows | Ohio |  |
| 6024 | `/rtcl_listing/percentage-heifer/` | Percentage Heifer | publish | 224 | Heifers | Ohio |  |
| 6047 | `/rtcl_listing/full-blood-bulls/` | Full Blood Bull | publish | 8392 | Bulls | Oklahoma |  |
| 6049 | `/rtcl_listing/bred-heifers/` | Open and Exposed 2025 Heifers | publish | 8392 | Heifers | Oklahoma |  |
| 6075 | `/rtcl_listing/registration52251/` | Registration#:52251 | publish | 8018 | Bulls | Alabama |  |
| 6077 | `/rtcl_listing/registration57218/` | Registration#:57218 | publish | 8018 | Bulls | Alabama |  |
| 6079 | `/rtcl_listing/purebred-5-yr-old-pair-3-in-1/` | Purebred 5 Yr Old Pair 3 in 1 | publish | 10613 | Cows | Arkansas |  |
| 6095 | `/rtcl_listing/8-steers-14-16-months-old/` | 8 Steers - 14-16 Months Old | publish | 274 | Steers | Alabama |  |
| 6108 | `/rtcl_listing/50-registered-heifer/` | 50% Registered South Poll Heifer | publish | 2788 | Heifers | Texas |  |
| 6114 | `/rtcl_listing/sf-f312-8yr-fullblood-herd-sire/` | SF F312 -- 8yr. Fullblood Herd Sire | publish | 222 | Bulls | Pennsylvania |  |
| 6131 | `/rtcl_listing/fullblood-3-year-old-bull-chief-for-trade/` | FullBlood, 3 Year Old Bull "Chief" for Trade | publish | 1577 | Bulls | North Carolina |  |
| 6150 | `/rtcl_listing/3-in-1-registered-50-south-poll-cow-with-registered-75-bull-calf-she-was-exposed-to-our-full-blood-bull-should-calve-in-a-few-months-preg-check-incoming/` | UPDATE: SOLD Yup, she's preggers! 3-in-1 Registered 50% South Poll cow with registered 75% bull calf | publish | 280 | Cows | Tennessee |  |
| 6208 | `/rtcl_listing/fullblood-bull/` | Fullblood Bull | publish | 233 | Bulls | Kentucky |  |
| 6211 | `/rtcl_listing/registered-south-poll-full-blood-bull-for-sale/` | Registered South Poll Full Blood Bull for Sale | publish | 516 | Bulls | Missouri |  |
| 6216 | `/rtcl_listing/two-south-poll-bull-calves-for-sale/` | Two South Poll Bull Calves For Sale | publish | 11299 | Bulls | Georgia |  |
| 6222 | `/rtcl_listing/registered-purebred-proven-south-poll-8-y-o-bull-for-sale-sperm-tested-and-ready-to-go/` | Registered Purebred proven South Poll 8 y/o Bull for sale. Sperm tested and ready to go. | publish | 11330 | Bulls | Kentucky |  |
| 6226 | `/rtcl_listing/2-1-2-year-old-full-blood-bull-for-trade-or-sell/` | 2 1/2 year-old full blood bull for trade or sell | publish | 397 | Bulls | Missouri |  |
| 6230 | `/rtcl_listing/six-year-old-50-commercial-cow-bread-back-full-blood-south-pole-bull/` | Six-year-old 50% commercial cow bread back full blood South pole bull | publish | 397 | Cows | Missouri |  |
| 6239 | `/rtcl_listing/purebred-bull-for-sale/` | Purebred bull for sale | publish | 397 | Bulls | Missouri |  |
| 6245 | `/rtcl_listing/three-year-old-registered-bull/` | Three year old registered bull | publish | 197 | Bulls | Texas |  |
| 6248 | `/rtcl_listing/17-month-old-bull/` | 17 month old Bull | publish | 10923 | Bulls | Alabama |  |
| 6258 | `/rtcl_listing/register-purebred-bull-in-kansas/` | Register Purebred Bull in Kansas | publish | 11238 | Bulls | Kansas |  |
| 6261 | `/rtcl_listing/buyers-choice-pick-4-of-7-open-heifers/` | Buyers Choice! Pick 4 of 7 Open Heifers | publish | 206 | Heifers | Missouri |  |
| 6268 | `/rtcl_listing/registered-75-heifer-dob-12-11-25-has-been-exposed-to-bull/` | Registered 75% heifer. DOB 12/11/25, has been exposed to bull | publish | 10851 | Heifers | Texas |  |
| 6271 | `/rtcl_listing/registered-50-cow-75-bull-calf-pair/` | Registered 50% Cow/ 75% bull calf pair | publish | 10851 | Cows | Texas |  |
| 6280 | `/rtcl_listing/spring-2025-steers-for-sale/` | Spring 2025 Steers for Sale | publish | 360 | Steers | Kansas |  |
| 6289 | `/rtcl_listing/another-3-in-1-confirmed-pregnant-4-year-old-registered-purebred-south-poll-cow-with-registered-purebred-bull-calf/` | SOLD! Another 3-in-1! Confirmed pregnant, 4-year-old Registered Purebred South Poll cow with registered Purebred bull calf | publish | 280 | Cows | Tennessee |  |
| 6308 | `/rtcl_listing/2025-spring-born-bulls-for-sale/` | 2025 Spring Born Registered Full Blood Bulls For Sale | publish | 34 | Bulls | Missouri |  |
| 6324 | `/rtcl_listing/100-registered-south-poll-18-month-bulls-100-grass-developed-10-head/` | 100% Registered South Poll 18 Month Bulls - 100% Grass Developed. 10 Head | publish | 188 | Bulls | Missouri |  |
| 6344 | `/rtcl_listing/commercial-south-poll-bulls-for-sale/` | Commercial South Poll Bull Calves For Sale | publish | 3405 | Bulls | Oklahoma |  |
| 6350 | `/rtcl_listing/100-registered-purebred-south-poll-bull-for-sale/` | 100% Registered, Purebred South Poll Bull For Sale | publish | 3405 | Bulls | Oklahoma |  |
| 6356 | `/rtcl_listing/100-registered-purebred-south-polls-bull-for-sale/` | 100% Registered, Purebred South Poll Bulls For Sale | publish | 3405 | Bulls | Oklahoma |  |
| 6361 | `/rtcl_listing/1-2-south-poll-steers/` | 1/2 south poll steers | publish | 523 | Steers | Illinois |  |
| 6368 | `/rtcl_listing/75-south-pole-25-black-angus-bull/` | 75% South Pole 25% Black Angus Bull | publish | 285 | Bulls | Georgia |  |
| 6376 | `/rtcl_listing/4-heifers/` | 4 Heifers | publish | 392 | Heifers | Pennsylvania |  |
| 6385 | `/rtcl_listing/full-blood-and-pure-bred-18-month-old-bulls-for-sale/` | Full Blood and Pure Bred 18 month old bulls for Sale | publish | 44 | Bulls | Missouri |  |
| 6392 | `/rtcl_listing/registered-full-blood-bulls/` | Registered Full Blood Bull | publish | 54 | Bulls | Arkansas |  |
| 6398 | `/rtcl_listing/registered-south-poll-semi-annual-online-auction-thursday-oct-15th-hoof-hide-llc-texas/` | Registered South Poll Semi-Annual Online Auction - Thursday Oct 15th - Hoof &amp; Hide LLC - Texas | publish | 71 | Cows | Texas |  |
| 6409 | `/rtcl_listing/2025-exposed-heifers/` | 2025 Exposed Heifers | publish | 8392 | Heifers | Oklahoma |  |
| 6412 | `/rtcl_listing/fullblood-south-poll-bull-for-sale-3/` | Fullblood South Poll Bull for Sale | publish | 83 | Bulls | Arkansas | `/rtcl_listing/rtcl-auto-temp-2/` |
| 6432 | `/rtcl_listing/registered-3-4-south-poll-cow-and-commercial-bull-calf/` | Registered 3/4 South Poll cow and commercial bull calf | publish | 303 | Cows | Mississippi |  |
| 6435 | `/rtcl_listing/group-of-eight-2026-steers/` | Group of eight 2026 steers | publish | 303 | Steers | Mississippi |  |
| 6446 | `/rtcl_listing/top-of-line-5-yr-old-south-poll-bull/` | Top of Line 5 Yr. Old South Poll Bull | publish | 10669 | Bulls | Kentucky |  |
| 6453 | `/rtcl_listing/purebred-heifer/` | Purebred Heifer | publish | 8392 | Heifers | Oklahoma |  |
| 6456 | `/rtcl_listing/registered-fullblood-bull/` | Registered Fullblood Bull | publish | 205 | Bulls | Missouri |  |
| 6478 | `/rtcl_listing/5-pairs-and-15-heavy-bred/` | 11 Pairs and 9 heavy bred | publish | 489 | Cows | Oklahoma |  |
| 6489 | `/rtcl_listing/heifer-for-sale-al/` | Full blood Heifer for sale AL | publish | 4757 | Heifers | Alabama |  |
| 6494 | `/rtcl_listing/full-blood-heifer-for-sale-al/` | Full blood Heifer for sale AL | publish | 4757 | Heifers | Alabama |  |
| 6502 | `/rtcl_listing/full-blood-proven-bull/` | Full blood proven bull | publish | 7233 | Bulls | Missouri |  |
| 6508 | `/rtcl_listing/3-6-month-old-south-poll-cross-bull-calves/` | 3 - 6 month old South Poll cross bull calves. | publish | 11372 | Bulls | Pennsylvania |  |
| 6535 | `/rtcl_listing/3-in1-cow-with-a-heifer-by-her-side/` | 3-in-1 50% cow with a 75% heifer by her side | publish | 7233 | Cows | Missouri |  |
| 6541 | `/rtcl_listing/cow-bred-to-our-southpoll-bull/` | Heifer bred to our Southpoll bull | publish | 7233 | Heifers | Missouri |  |
| 6547 | `/rtcl_listing/30-steers/` | 30 steers | publish | 11384 | Steers | Texas |  |
| 6555 | `/rtcl_listing/bull-for-sale/` | Bull For Sale | publish | 210 | Bulls | Tennessee |  |
| 6559 | `/rtcl_listing/50-bull-south-poll-red-angus/` | 50% bull, South Poll/ Red Angus | publish | 210 | Bulls | Tennessee |  |
| 6568 | `/rtcl_listing/4-south-poll-steers/` | 4 South Poll Steers | publish | 12 | Steers | Virginia |  |
| 6573 | `/rtcl_listing/4-heifers-1-cow-calf-pair-for-sale/` | 4 Heifers & 1 Cow/calf Pair for Sale | publish | 12 | Heifers | Virginia |  |
| 6579 | `/rtcl_listing/great-tempered-red-bull-for-sale/` | Great tempered Red Bull for sale | publish | 11389 | Bulls | Alabama |  |
| 6582 | `/rtcl_listing/fullblood-2-year-old-bull-for-sale/` | FullBlood 2 year old bull for sale | publish | 4042 | Bulls | Pennsylvania |  |
| 6589 | `/rtcl_listing/2-fullblood-heifers-and-1-young-fullblood-cow-one-package/` | 2 Fullblood heifers and 1 young Fullblood cow- one package | publish | 1813 | Heifers | Missouri |  |
| 6594 | `/rtcl_listing/fullblood-bull-great-temperament-diligent-breeder/` | Fullblood Bull - Great Temperament, Diligent Breeder | publish | 10967 | Bulls | Missouri |  |

## 8. Farm page URLs (`/store/{slug}/`) — 250 stores

Rewrite also serves `/store/{slug}/page/N/` (listing pagination on the farm page), `/embed/`, `/feed/`, `/attachment/…`. Expired members'
pages currently render an 'unavailable' notice but still resolve (HTTP 200).

**44 farm pages have no name** (empty `post_title`, slug = post ID, shown as *(untitled)* below) — members saved the farm form without a
farm name; the directory shows them as blank cards. Flag for migration review.

| ID | Path | Farm | Owner user | Member expiry | Old slugs |
|---|---|---|---|---|---|
| 5486 | `/store/5486/` | *(untitled)* | 360 | 2026-09-30 |  |
| 5573 | `/store/5573/` | *(untitled)* | 11321 | 2027-07-10 |  |
| 5578 | `/store/5578/` | *(untitled)* | 11299 | 2028-06-14 |  |
| 5581 | `/store/5581/` | *(untitled)* | 10960 | 2027-06-23 |  |
| 5590 | `/store/5590/` | *(untitled)* | 11285 | 2027-06-02 |  |
| 5671 | `/store/5671/` | *(untitled)* | 11339 | 2027-07-25 |  |
| 5708 | `/store/5708/` | *(untitled)* | 11289 | 2027-06-05 |  |
| 6287 | `/store/6287/` | *(untitled)* | 340 | 2026-11-17 |  |
| 6562 | `/store/6562/` | *(untitled)* | 11387 | 2027-10-12 |  |
| 1209 | `/store/1209/` | *(untitled)* | 124 | 2026-04-07 |  |
| 1212 | `/store/1212/` | *(untitled)* | 116 | 2027-08-20 |  |
| 1252 | `/store/1252/` | *(untitled)* | 1487 | 2026-04-08 |  |
| 1292 | `/store/1292/` | *(untitled)* | 1577 | 2027-08-05 |  |
| 1317 | `/store/1317/` | *(untitled)* | 1664 | 2027-04-09 |  |
| 1627 | `/store/1627/` | *(untitled)* | 3417 | 2026-05-02 |  |
| 1730 | `/store/1730/` | *(untitled)* | 3702 | 2027-05-25 |  |
| 1941 | `/store/1941/` | *(untitled)* | 4684 | 2026-05-30 |  |
| 2002 | `/store/2002/` | *(untitled)* | 218 | 2026-05-20 |  |
| 2330 | `/store/2330/` | *(untitled)* | 5551 | 2026-06-12 |  |
| 2379 | `/store/2379/` | *(untitled)* | 3628 | 2026-05-08 |  |
| 2436 | `/store/2436/` | *(untitled)* | 7303 | 2026-08-06 |  |
| 2515 | `/store/2515/` | *(untitled)* | 7669 | 2026-08-22 |  |
| 2567 | `/store/2567/` | *(untitled)* | 6863 | 2026-07-27 |  |
| 2813 | `/store/2813/` | *(untitled)* | 4683 | 2026-05-30 |  |
| 2865 | `/store/2865/` | *(untitled)* | 454 | 2026-09-24 |  |
| 3010 | `/store/3010/` | *(untitled)* | 10122 | 2026-10-13 |  |
| 3098 | `/store/3098/` | *(untitled)* | 10132 | 2026-10-22 |  |
| 3701 | `/store/3701/` | *(untitled)* | 338 | 2026-12-24 |  |
| 3791 | `/store/3791/` | *(untitled)* | 10906 | 2027-01-01 |  |
| 3842 | `/store/3842/` | *(untitled)* | 10923 | 2027-01-07 |  |
| 3878 | `/store/3878/` | *(untitled)* | 529 | 2027-07-01 |  |
| 4064 | `/store/4064/` | *(untitled)* | 165 | 2026-06-25 |  |
| 4095 | `/store/4095/` | *(untitled)* | 4244 | 2027-05-22 |  |
| 4229 | `/store/4229/` | *(untitled)* | 205 | 2027-02-18 |  |
| 4250 | `/store/4250/` | *(untitled)* | 279 | 2027-02-18 |  |
| 4279 | `/store/4279/` | *(untitled)* | 10965 | 2027-02-23 |  |
| 4446 | `/store/4446/` | *(untitled)* | 4873 | 2026-06-03 |  |
| 4783 | `/store/4783/` | *(untitled)* | 49 | 2027-07-16 |  |
| 4959 | `/store/4959/` | *(untitled)* | 480 | 2027-04-29 |  |
| 5070 | `/store/5070/` | *(untitled)* | 484 | 2027-02-21 |  |
| 5192 | `/store/5192/` | *(untitled)* | 11272 | 2027-05-19 |  |
| 5212 | `/store/5212/` | *(untitled)* | 7233 | 2026-10-16 |  |
| 5410 | `/store/5410/` | *(untitled)* | 25 | 2027-03-29 |  |
| 5459 | `/store/5459/` | *(untitled)* | 11303 | 2027-06-17 |  |
| 2614 | `/store/1tribe-farm/` | 1Tribe Farm | 89 | 2027-09-25 | `/store/2614/` |
| 2764 | `/store/https-www-3jfarmsok-com/` | 3J Farms OK | 8392 | 2027-10-01 | `/store/2764/` |
| 5083 | `/store/3lfarmsky-com/` | 3L Farms | 11266 | 2027-05-07 | `/store/5083/` |
| 2852 | `/store/4-bar-c-ranch/` | 4 Bar C Ranch | 189 | 2027-09-23 | `/store/2852/` |
| 1221 | `/store/4m-ranch/` | 4M Ranch | 201 | 2027-04-08 | `/store/1221/` |
| 1646 | `/store/4q-cattle/` | 4Q Cattle | 455 | 2026-04-04 |  |
| 1224 | `/store/1224/` | 4th Quarter Ranch, LLC | 1179 | 2026-04-07 |  |
| 1401 | `/store/5kr/` | 5K Ranch | 2788 | 2029-04-14 |  |
| 1476 | `/store/1476/` | Adabelle Cattle Company | 3030 | 2026-04-23 |  |
| 1592 | `/store/aor/` | Airborne Okie Ranch | 1469 | 2029-04-07 |  |
| 935 | `/store/mailtoalaneckl60gmail-com/` | Alan Eckl Farms | 519 | 2027-04-16 |  |
| 3071 | `/store/alan-kapp/` | Alan Kapp | 10129 | 2026-10-20 |  |
| 3568 | `/store/amazing-grace-ranch/` | Amazing Grace Ranch | 10851 | 2026-12-11 |  |
| 1450 | `/store/angelusacresfarmla/` | Angelus Acres | 2993 | 2027-04-21 | `/store/1450/` |
| 1347 | `/store/1347/` | AP Sand Road Farms | 359 | 2027-04-11 |  |
| 1408 | `/store/www-southpoll-com-store-applehorsefarm/` | Apple Horse Farm | 129 | 2026-04-09 | `/store/1408/` |
| 1461 | `/store/1461/` | Ararat Farm | 2862 | 2026-04-17 |  |
| 5372 | `/store/arleecattleco/` | Arlee Cattle Co. | 11293 | 2027-06-11 |  |
| 3719 | `/store/1955/` | Aspen Cattle, LLC | 5724 | 2027-06-17 |  |
| 2171 | `/store/2171/` | Back Hills Farm llc | 54 | 2027-06-11 |  |
| 1701 | `/store/bagley-farms/` | Bagley Farms | 3588 | 2027-06-29 | `/store/1701/` |
| 3285 | `/store/bankston-farm/` | Bankston Farms | 10604 | 2026-11-11 |  |
| 3835 | `/store/bankstonfarms/` | Bankston Farms | 10921 | 2027-01-06 |  |
| 920 | `/store/barberfarmssouthpoll/` | Barber Farms | 495 | 2029-04-02 |  |
| 3760 | `/store/bardotfamilyfarm/` | Bardot Family Farm | 115 | 2028-05-13 |  |
| 1097 | `/store/bartimusfamilyfarm/` | Bartimus Family Farm | 410 | 2027-05-17 |  |
| 4540 | `/store/www-facebook-com-bborganicfarmnc/` | BB Organic Farm NC | 451 | 2026-05-27 | `/store/4540/` |
| 4017 | `/store/grass-fed-red-cows/` | Bear Branch Farm | 44 | 2027-01-23 |  |
| 3822 | `/store/jbwfarmfresh-com/` | Bert Woodham Farms | 10917 | 2027-01-05 | `/store/3822/` |
| 1501 | `/store/betterearthfarms/` | Better Earth Farms | 324 | 2027-04-03 | `/store/1501/` |
| 4547 | `/store/blasdellegacyfarms/` | Blasdel Legacy Farms | 239 | 2027-02-12 | `/store/4547/` |
| 2031 | `/store/www-blueduckranch-com/` | Blue Duck Ranch | 5187 | 2027-06-10 |  |
| 4020 | `/store/brackenridgefarm/` | Bracken Ridge Farm | 164 | 2027-01-24 | `/store/4020/` |
| 5770 | `/store/https-brasstacksranch-wixsite-com-brasstacksranch/` | Brass Tacks Ranch | 224 | 2027-07-30 | `/store/5770/` |
| 3766 | `/store/breezyacresfarm/` | Breezy Acres Farm | 350 | 2026-12-29 |  |
| 1238 | `/store/1238/` | Briar Creek Ranch | 462 | 2027-04-08 |  |
| 1376 | `/store/1376/` | Buck Creek Farms | 387 | 2026-04-15 |  |
| 2126 | `/store/buck-creek-farms/` | Buck Creek Farms | 166 | 2026-04-22 |  |
| 1236 | `/store/1236/` | Buffalo Creek Ranch, LLC | 325 | 2027-08-17 |  |
| 4772 | `/store/4772/` | Bull Skin Ranch | 4678 | 2026-05-30 |  |
| 2992 | `/store/andy-duke/` | Burnley Farms | 3771 | 2027-05-13 |  |
| 2251 | `/store/https-www-burttsbeef-com/` | Burtt&#039;s Beef | 5930 | 2026-07-07 | `/store/2251/` |
| 4092 | `/store/c-squared-farms-llc/` | C Squared Farms LLC | 9752 | 2026-10-03 | `/store/4092/` |
| 3392 | `/store/www-cffarms-org/` | C&amp;F Farms, LLC | 10620 | 2026-11-18 | `/store/3392/` |
| 5067 | `/store/5067/` | Cardinal Cove Ranch | 3038 | 2028-05-05 |  |
| 5397 | `/store/store-slug/` | Carson Bennett | 8 | 2027-04-07 |  |
| 1075 | `/store/1075/` | Carte Cattle Company | 170 | 2026-04-04 |  |
| 1002 | `/store/chicoryhillfarm/` | Chicory Hill Farm | 485 | 2027-04-03 | `/store/1002/` |
| 2664 | `/store/n-a/` | Circle W Livestock | 7562 | 2026-08-20 | `/store/2664/` |
| 4115 | `/store/crf/` | Clay Ridge Farm LLC | 10889 | 2026-12-29 | `/store/4115/` |
| 1426 | `/store/1426/` | coleman grass fed | 26 | 2026-04-07 |  |
| 3543 | `/store/collinroadfarms/` | Collin Road Farms | 10844 | 2026-12-07 | `/store/3543/` |
| 1569 | `/store/cof/` | Collins Country Farm | 272 | 2027-04-06 |  |
| 2154 | `/store/2154/` | Cool Creek Farm | 3880 | 2026-06-10 |  |
| 2578 | `/store/2578/` | Coyote Creek Ranch | 7737 | 2026-08-24 |  |
| 2234 | `/store/cravofarm/` | Cravo Farm | 419 | 2028-06-08 |  |
| 2178 | `/store/2178/` | Cross B Ranch &amp; Cattle Co LLC | 5791 | 2028-08-02 |  |
| 1842 | `/store/d-rocking-t-land-and-cattle-co/` | D Rocking T Land and Cattle Co | 406 | 2026-05-21 | `/store/1842/` |
| 1442 | `/store/dean-ranch/` | Dean Ranch | 265 | 2026-04-19 |  |
| 2129 | `/store/2129/` | Dewey Acres | 5704 | 2026-06-15 |  |
| 2459 | `/store/dobbs-hill-farm/` | DOBBS HILL FARM | 196 | 2026-08-13 |  |
| 995 | `/store/djf/` | Double J Farm | 295 | 2027-07-14 | `/store/995/` |
| 1659 | `/store/1659/` | Double R Cattle Services, Inc. | 72 | 2027-04-03 |  |
| 4997 | `/store/4997/` | Down Range Farm | 11251 | 2027-05-01 |  |
| 1751 | `/store/drc/` | Dozier Road Cattle Co. | 225 | 2026-05-14 |  |
| 3975 | `/store/dutchspringfarm/` | Dutch Spring Farm | 10935 | 2027-01-19 | `/store/3975/` |
| 1527 | `/store/1527/` | East Ridge Farms | 399 | 2026-04-25 |  |
| 1394 | `/store/virginia-south-poll-cattle/` | Ember Cattle Company | 15 | 2027-04-03 | `/store/1394/` |
| 1674 | `/store/erickson-cattle-company/` | Erickson Cattle Company  Josh and Brody Erickson | 45 | 2026-05-08 | `/store/1674/` |
| 4417 | `/store/4417/` | Evans Farm &amp; Ranch | 11044 | 2027-03-09 |  |
| 4916 | `/store/www-everlizer-com/` | EverFarm | 11252 | 2027-04-21 | `/store/4916/` |
| 3666 | `/store/family-tradition-farm/` | Family Tradition Farm | 10868 | 2026-12-23 | `/store/3666/` |
| 1508 | `/store/fechtel-south-poll/` | Fechtel South Poll Cattle &amp; American White Sheep | 51 | 2026-04-24 | `/store/1508/` |
| 1866 | `/store/fields-farms/` | Fields Farms | 367 | 2027-06-10 |  |
| 3798 | `/store/010103/` | Fisher Growers | 10909 | 2027-01-02 |  |
| 3742 | `/store/3742/` | Flat Rock Cattle Company | 303 | 2026-12-28 |  |
| 1285 | `/store/flatwoodsfarm/` | Flatwoods Farm | 285 | 2027-09-28 |  |
| 2424 | `/store/flying-w-farm/` | Flying &quot;W&quot; Farm | 3979 | 2026-08-03 | `/store/2424/` |
| 4381 | `/store/pasture-based-low-input-cattle/` | Flying K Cattle | 10995 | 2027-03-05 | `/store/4381/` |
| 3216 | `/store/3216/` | Foggy Bottom Pastures | 10140 | 2026-10-29 |  |
| 1924 | `/store/fondriestfamilyfarm/` | Fondriest Family Farm | 3411 | 2027-05-02 |  |
| 1898 | `/store/http-forestvalleyfarm-com/` | Forest Valley Farm | 293 | 2026-05-19 | `/store/1898/` |
| 1414 | `/store/gila-diversified-livestock/` | Gila Diversified Livestock | 318 | 2027-07-02 | `/store/1414/` |
| 2138 | `/store/adamgoodlifegrassfarms-com/` | Good Life Grass Farms | 153 | 2026-06-16 |  |
| 2316 | `/store/2316/` | Gossett Gossett Farms | 6152 | 2026-07-16 |  |
| 1521 | `/store/1521/` | Graybill Grass Ranch | 2898 | 2026-04-25 |  |
| 1597 | `/store/griffithlivestock/` | Griffith Livestock, LLC | 3405 | 2029-04-30 |  |
| 1071 | `/store/1071/` | Harrison Valley Farms | 34 | 2027-04-03 |  |
| 1296 | `/store/hfr/` | Helms Farm | 1582 | 2027-05-21 |  |
| 4720 | `/store/hickmansunnymeadow/` | Hickman Sunny Meadow Farms | 11204 | 2027-03-28 |  |
| 1331 | `/store/1331/` | HIGH PRAIRIE PASTURES | 138 | 2028-04-09 |  |
| 1112 | `/store/1112/` | Honnas Land and Cattle Co. | 119 | 2026-04-04 |  |
| 982 | `/store/hoofandhide/` | Hoof &amp; Hide LLC | 71 | 2027-04-03 | `/store/982/` |
| 6173 | `/store/6173/` | Hudson Ridge Farms - Anthony Parnell | 11368 | 2027-09-10 |  |
| 3411 | `/store/http-www-huntercattleranch-com/` | HUNTER CATTLE RANCH | 240 | 2026-11-19 |  |
| 4925 | `/store/immaculatafarms/` | Immaculata Farms | 11250 | 2027-04-20 | `/store/4925/` |
| 1434 | `/store/1434/` | Indian Creek Family Farms | 2932 | 2026-04-19 |  |
| 1458 | `/store/inc/` | Indian Creek Farm | 470 | 2027-04-03 |  |
| 2336 | `/store/jbwfarmscattleco-com/` | JBW Farms Cattle Co. | 6215 | 2028-07-20 | `/store/2336/` |
| 4684 | `/store/4684/` | Jireh Farms | 10579 | 2026-11-07 |  |
| 1199 | `/store/1199/` | JMK CATTLE | 507 | 2027-05-01 |  |
| 1447 | `/store/south-poll/` | Johnson family farm | 2990 | 2027-04-21 | `/store/1447/` |
| 1120 | `/store/1120/` | Kent Ranch | 483 | 2026-04-05 |  |
| 3687 | `/store/3687/` | Kenzo Lane Farm | 2778 | 2026-04-15 |  |
| 5006 | `/store/kummerhomestead/` | Kummer Homestead | 11260 | 2027-05-03 | `/store/5006/` |
| 1970 | `/store/kunert-cattle-company/` | Kunert Cattle Company | 105 | 2027-06-02 | `/store/1970/` |
| 2696 | `/store/2696/` | L&amp;M Farms | 336 | 2026-08-04 |  |
| 4166 | `/store/https-www-facebook-com-profile-phpid61557002775572/` | Land of Rest Farms | 10958 | 2027-02-12 | `/store/4166/` |
| 3636 | `/store/lawmasterfarms/` | Lawmaster Farms | 10859 | 2027-12-21 | `/store/3636/` |
| 1168 | `/store/levyfarmsinc/` | Levy Farms Inc | 215 | 2027-09-07 | `/store/1168/` |
| 4015 | `/store/lil-dawg-farms/` | Lil Dawg Farms | 10940 | 2027-01-23 |  |
| 1533 | `/store/https-www-littlelagniappefarm-com/` | Little Lagniappe Farm | 306 | 2026-04-25 |  |
| 3963 | `/store/3963/` | Lonesome Ridge Ranch | 10927 | 2027-01-12 |  |
| 1341 | `/store/lotus-hill/` | Lotus Hill LLC | 43 | 2027-04-11 | `/store/1341/` |
| 1734 | `/store/1734/` | M.A.D. Farms LLC | 3631 | 2027-07-23 |  |
| 1955 | `/store/www-magnoliagrovefarms-com/` | Magnolia Grove Farms | 2651 | 2026-04-13 |  |
| 1809 | `/store/massey-ranch/` | Massey Ranch | 3640 | 2027-05-08 |  |
| 2732 | `/store/matt-thieret-farms/` | Matt Thieret Farms | 8337 | 2026-09-15 |  |
| 1145 | `/store/lrf/` | McClure Licking River Farm | 292 | 2027-04-05 | `/store/1145/` |
| 1594 | `/store/cmc/` | McLelland Cattle Co. | 371 | 2026-05-01 |  |
| 1651 | `/store/1651/` | Meadowlark Farm | 222 | 2027-05-07 |  |
| 3203 | `/store/3203/` | Moonlight Meadows | 941 | 2028-04-05 |  |
| 2161 | `/store/morgan-cattle/` | Morgan Cattle Co. | 327 | 2027-07-17 | `/store/2161/` |
| 1107 | `/store/mfi/` | Morrison Farms, Inc. | 136 | 2026-04-04 | `/store/1107/` |
| 3358 | `/store/3358/` | Mountain Glen Farm | 10615 | 2026-11-15 |  |
| 3913 | `/store/https-www-movingmeadowsfarm-com-shop-online-html/` | Moving Meadows Farm | 10929 | 2027-01-13 | `/store/3913/` |
| 930 | `/store/nelsonranchcattleland/` | Nelson Ranch Cattle &amp; Land | 477 | 2026-04-03 | `/store/930/` |
| 4369 | `/store/4369/` | Newberry Family Farm | 425 | 2027-01-15 |  |
| 5135 | `/store/5135/` | Nolting Cattle Company | 62 | 2027-05-14 |  |
| 943 | `/store/northwoodsranch/` | Northwoods Cattle Ranch LLC | 332 | 2026-04-03 | `/store/943/` |
| 1403 | `/store/1403/` | O&#039;Neal Cattle Company | 146 | 2026-04-16 |  |
| 3269 | `/store/oldmidwayfarm-com/` | Old Midway Farm | 10412 | 2027-11-10 | `/store/3269/` |
| 5088 | `/store/omacresfarm/` | Om Acres Farm | 11267 | 2027-05-08 | `/store/5088/` |
| 4003 | `/store/4003/` | Omega Ridge Farm | 1813 | 2027-10-15 |  |
| 1175 | `/store/1175/` | Ozark Hills Genetics | 18 | 2026-04-06 |  |
| 1806 | `/store/https-parsonslandandcattle-grazecart-com/` | Parsons Land and Cattle | 4042 | 2027-08-26 |  |
| 2243 | `/store/patriotfarmsnc/` | Patriot Farms LLC | 289 | 2028-06-03 | `/store/2243/` |
| 2807 | `/store/pecancattlecompany/` | Pecan Cattle Company | 8453 | 2026-09-21 |  |
| 1505 | `/store/na/` | Pendergrass Farms | 3088 | 2026-04-24 |  |
| 3991 | `/store/3991/` | Perlich Family Farm | 2143 | 2027-04-10 |  |
| 1517 | `/store/1517/` | Persimmon Ridge Farm | 3116 | 2027-04-25 |  |
| 979 | `/store/https-www-facebook-com-phippsranch/` | Phipps Ranch | 522 | 2026-04-03 | `/store/979/` |
| 2277 | `/store/klp/` | Prestwood Beef Cattle | 530 | 2027-04-04 |  |
| 3681 | `/store/primetime/` | Primetime Ranch | 10845 | 2026-12-08 | `/store/3681/` |
| 1059 | `/store/1059/` | PRUDENT RANCH | 532 | 2027-04-04 |  |
| 4367 | `/store/puregrassfarm-com/` | Pure Grass Farm LLC | 377 | 2027-03-04 | `/store/4367/` |
| 5370 | `/store/rafters4cattleco/` | RafterS4 | 11294 | 2027-06-11 |  |
| 5120 | `/store/5120/` | Rankin Cattle Co. | 10957 | 2028-02-10 |  |
| 1727 | `/store/redhillfarms/` | Red Hill Farms | 3739 | 2026-05-12 | `/store/1727/` |
| 4247 | `/store/4247/` | Redemption Creek Ranch | 233 | 2029-07-14 |  |
| 5902 | `/store/renegade-hills-plantation/` | Renegade Hills Plantation | 481 | 2028-04-13 |  |
| 4510 | `/store/restoration-farm/` | Restoration Farm | 175 | 2027-03-17 | `/store/4510/` |
| 1044 | `/store/rex-conley-farms/` | Rex Conley Farms | 445 | 2026-04-04 |  |
| 923 | `/store/r3/` | RIVER RIDGE RANCH | 331 | 2026-04-03 |  |
| 3496 | `/store/rrh/` | RIVER ROCK HOMESTEAD | 358 | 2027-11-27 |  |
| 3662 | `/store/robinsnestfarmtn/` | Robin&#039;s Nest Farm | 10867 | 2026-12-23 | `/store/3662/` |
| 2573 | `/store/rc3/` | Robinson Creek Cattle Company | 8018 | 2026-08-31 | `/store/2573/` |
| 3096 | `/store/3096/` | Rock  N J Farms, LLC | 9914 | 2027-10-07 |  |
| 4794 | `/store/http-www-rockhousefarmsllc-com/` | Rock House Farms, LLC. | 11240 | 2027-04-08 | `/store/4794/` |
| 1261 | `/store/1261/` | Rolling Hill Ranch | 131 | 2027-04-07 |  |
| 3825 | `/store/rosehill-southpoll/` | Rose Hill Farm | 158 | 2027-01-05 | `/store/3825/` |
| 3364 | `/store/runningdoubleh/` | Running Double H | 243 | 2026-10-24 | `/store/3364/` |
| 970 | `/store/970/` | Rusty Hide Cattle Company | 190 | 2026-04-03 |  |
| 1279 | `/store/support-farmers/` | Ruth Farm | 496 | 2027-04-08 |  |
| 3774 | `/store/3774/` | Salt Creek Farms | 133 | 2026-12-30 |  |
| 2861 | `/store/sand-mountain-south-poll/` | Sand Mountain South Poll | 1746 | 2026-09-24 | `/store/2861/`, `/store/sand-mountain-south/` |
| 927 | `/store/927/` | Sassafras Valley Ranch | 27 | 2026-04-03 |  |
| 2083 | `/store/na-2/` | SB3 Farms | 5366 | 2027-06-10 |  |
| 2216 | `/store/2216/` | Slabach Farms | 5861 | 2027-06-30 |  |
| 3930 | `/store/smithfamilypastures/` | Smith Family Pastures | 141 | 2027-01-15 | `/store/3930/` |
| 1248 | `/store/1248/` | Smokie Pond Farm | 111 | 2026-04-08 |  |
| 1046 | `/store/1046/` | Smoky Mountain South Polls | 533 | 2027-08-07 |  |
| 1081 | `/store/1081/` | SNOWDEN CATTLE CO. | 476 | 2026-04-04 |  |
| 1337 | `/store/snyderfamilyfarmva/` | Snyder Family Farm | 2190 | 2026-04-10 | `/store/1337/` |
| 2810 | `/store/soffosranch/` | Soffos Ranch | 8454 | 2026-09-21 | `/store/2810/` |
| 1134 | `/store/solarcows/` | Solar Cows at White Horse Ranch | 317 | 2026-04-05 |  |
| 2259 | `/store/2259/` | SPADE AND TABLE FARM | 5941 | 2026-07-07 |  |
| 1315 | `/store/www-sparrowranchsc-com/` | Sparrow Ranch | 439 | 2027-04-09 |  |
| 2480 | `/store/https-stonepillarfarm-com/` | Stone Pillar Farm | 7541 | 2026-08-18 | `/store/2480/` |
| 3588 | `/store/3588/` | Sunrise Operators LLC | 8698 | 2027-09-25 |  |
| 3565 | `/store/none/` | Sweetwater South Poll Cattle Company | 1494 | 2026-04-08 | `/store/3565/` |
| 4590 | `/store/tateplace-farm/` | Tate Place Farm | 11036 | 2027-03-24 | `/store/4590/` |
| 2936 | `/store/three-belles-farm/` | Three Belles Farm | 411 | 2032-04-09 | `/store/2936/` |
| 1203 | `/store/washitavalleyfarms-com/` | Tishomingo Land &amp; Cattle Company, LLC | 534 | 2030-04-03 |  |
| 1632 | `/store/tranquilmeadowsfarm-com/` | Tranquil Meadows Farm | 3500 | 2028-10-05 |  |
| 4218 | `/store/triplebpastures-com/` | Triple B Pastures | 3765 | 2028-10-05 |  |
| 5317 | `/store/i-dont-have-one/` | Triple Oaks Farm | 3898 | 2027-06-02 |  |
| 2880 | `/store/triplesfarmtn/` | Triple S Farm | 8696 | 2027-09-25 | `/store/2880/` |
| 2447 | `/store/turntimefarmsellersliega/` | Turntime Farms | 7403 | 2027-08-12 | `/store/2447/` |
| 1678 | `/store/tysonranch/` | Tyson Ranch | 3659 | 2027-05-09 |  |
| 2182 | `/store/2182/` | Underwood Farm LLC | 3081 | 2026-06-24 |  |
| 2846 | `/store/2846/` | Unity Farms | 310 | 2027-09-25 |  |
| 3288 | `/store/vahlfarmsllc/` | Vahl Farms LLC | 504 | 2026-11-11 |  |
| 1896 | `/store/1896/` | Victory Hills Farm | 4389 | 2026-05-24 |  |
| 5014 | `/store/5014/` | Waldrop Family Farms | 11263 | 2027-05-04 |  |
| 1035 | `/store/1035/` | Wandering Brook Farm | 352 | 2027-04-04 |  |
| 1360 | `/store/1360/` | Washam Farm | 514 | 2027-04-03 |  |
| 1711 | `/store/wholistic-hills-farm/` | Wholistic Hills Farm | 213 | 2026-05-12 | `/store/1711/` |
| 3343 | `/store/wilbers-farm/` | Wilbers Farm | 31 | 2027-09-01 |  |
| 1869 | `/store/wildrunpastures/` | Wild Run Pastures | 4326 | 2026-05-23 |  |
| 2538 | `/store/2538/` | Wildwood Run Farm | 7923 | 2026-08-28 |  |
| 4217 | `/store/n-a-2/` | Wolgast Farms | 10959 | 2027-02-16 |  |
| 2046 | `/store/woods/` | Woods Fork Cattle Company LLC | 30 | 2026-06-08 |  |
| 3481 | `/store/yoderfamilyfarm/` | Yoder Family Farm | 10791 | 2026-11-24 |  |
| 1184 | `/store/https-www-youngbloodfarm-com/` | Youngblood Grassfed Farm | 35 | 2027-04-06 | `/store/1184/` |
| 4481 | `/store/4481/` | Zahn Farms | 78 | 2026-12-12 |  |

## 9. API and machine endpoints

| Path | Purpose | Notes |
|---|---|---|
| `/?rtcl-api=rtcl_gateway_stripe` | Stripe webhook receiver (`rtcl_api_rtcl_gateway_stripe`) | Also `/rtcl-api/rtcl_gateway_stripe/`. Must be replaced in the Stripe Dashboard by the new plugin's signed webhook URL; keep a 410/redirect so stray events do not 404. |
| `/?rtcl-api=rtcl_gateway_stripe_webhook` | Alias of the above |  |
| `/?rtcl-api=rtcl_gateway_stripe_confirm_payment_intent` | Stripe PaymentIntent confirmation return | Used mid-checkout only |
| `/?rtcl-api=rtcl_gateway_paypal` | PayPal IPN (gateway not enabled) |  |
| `/rtcl-api/{request}/` | Pretty form of the API endpoint (`add_rewrite_endpoint('rtcl-api')`) | Appears on every rewrite base (`/rtcl_listing/{slug}/rtcl-api/...`, `/category/.../rtcl-api/...`) |
| `/wp-json/rtcl/v1/*` | Pro REST API (listings, auth, orders, subscriptions, push, reviews, webhooks, my, privacy, store) | Disabled by setting `rtcl_app_settings.allow_rest_api = no`; no mobile app in use |
| `/wp-admin/admin-ajax.php?action=rtcl_*` | All front-end AJAX (filters, gallery upload, form builder, checkout, inline search, store save) | Not URLs to redirect; listed for completeness |
| `/listings/feed/` | RSS of listings | rtcl_general_settings.rss_feed_number = 10 |

REST routes registered in source (60):

- V1_AuthApi: `/wp-json/rtcl/v1login`
- V1_AuthApi: `/wp-json/rtcl/v1logout`
- V1_AuthApi: `/wp-json/rtcl/v1account-delete`
- V1_AuthApi: `/wp-json/rtcl/v1social-login`
- V1_AuthApi: `/wp-json/rtcl/v1signup`
- V1_AuthApi: `/wp-json/rtcl/v1reset-password`
- V1_CommonApi: `/wp-json/rtcl/v1listing-types`
- V1_CommonApi: `/wp-json/rtcl/v1search-fields`
- V1_CommonApi: `/wp-json/rtcl/v1form/categories`
- V1_CommonApi: `/wp-json/rtcl/v1categories`
- V1_CommonApi: `/wp-json/rtcl/v1terms`
- V1_CommonApi: `/wp-json/rtcl/v1locations`
- V1_CommonApi: `/wp-json/rtcl/v1contact`
- V1_CommonApi: `/wp-json/rtcl/v1config`
- V1_CommonApi: `/wp-json/rtcl/v1config-new-listing`
- V1_CommonApi: `/wp-json/rtcl/v1tags/new`
- V1_ListingApi: `/wp-json/rtcl/v1listings`
- V1_ListingApi: `/wp-json/rtcl/v1/listings/(?P<listing_id>[\d]+)`
- V1_ListingApi: `/wp-json/rtcl/v1/listing/form`
- V1_ListingApi: `/wp-json/rtcl/v1/listing/fb`
- V1_ListingApi: `/wp-json/rtcl/v1/listing/fb/image`
- V1_ListingApi: `/wp-json/rtcl/v1/listing/fb/image/sort`
- V1_ListingApi: `/wp-json/rtcl/v1/listing/fb/file`
- V1_ListingApi: `/wp-json/rtcl/v1/listing/report`
- V1_ListingApi: `/wp-json/rtcl/v1/listing/email-seller`
- V1_MyApi: `/wp-json/rtcl/v1my`
- V1_MyApi: `/wp-json/rtcl/v1my/profile-image`
- V1_MyApi: `/wp-json/rtcl/v1my/listings`
- V1_MyApi: `/wp-json/rtcl/v1my/listing/renew`
- V1_MyApi: `/wp-json/rtcl/v1my/favourites`
- V1_MyApi: `/wp-json/rtcl/v1my/mark-as-sold`
- V1_MyApi: `/wp-json/rtcl/v1my/chat`
- V1_MyApi: `/wp-json/rtcl/v1my/chat/check`
- V1_MyApi: `/wp-json/rtcl/v1my/chat/conversation`
- V1_MyApi: `/wp-json/rtcl/v1my/chat/message`
- V1_MyApi: `/wp-json/rtcl/v1my/manage/listings`
- V1_MyApi: `/wp-json/rtcl/v1my/manage/listings/change-status`
- V1_MyApi: `/wp-json/rtcl/v1my/manage/orders`
- V1_MyApi: `/wp-json/rtcl/v1my/manage/orders/change-status`
- V1_OrderApi: `/wp-json/rtcl/v1plans`
- V1_OrderApi: `/wp-json/rtcl/v1payment-gateways`
- V1_OrderApi: `/wp-json/rtcl/v1get-checkout-data`
- V1_OrderApi: `/wp-json/rtcl/v1checkout`
- V1_OrderApi: `/wp-json/rtcl/v1payments`
- V1_OrderApi: `/wp-json/rtcl/v1payments/(?P<payment_id>[\d]+)`
- V1_OrderApi: `/wp-json/rtcl/v1orders`
- V1_OrderApi: `/wp-json/rtcl/v1orders/(?P<order_id>[\d]+)`
- V1_PrivacyApi: `/wp-json/rtcl/v1privacy/user/block`
- V1_PrivacyApi: `/wp-json/rtcl/v1privacy/listing/block`
- V1_PushNotificationApi: `/wp-json/rtcl/v1push-notification/register`
- V1_PushNotificationApi: `/wp-json/rtcl/v1push-notification/add-device`
- V1_SubscriptionApi: `/wp-json/rtcl/v1subscription/(?P<id>[\d]+)`
- V1_SubscriptionApi: `/wp-json/rtcl/v1subscription/update-pm`
- V1_StoreApi: `/wp-json/rtcl/v1stores`
- V1_StoreApi: `/wp-json/rtcl/v1/stores/(?P<store_id>[\d]+)`
- V1_StoreApi: `/wp-json/rtcl/v1store/listings`
- V1_StoreApi: `/wp-json/rtcl/v1store/email-owner`
- V1_StoreApi: `/wp-json/rtcl/v1my/store`
- V1_StoreApi: `/wp-json/rtcl/v1my/store/logo`
- V1_StoreApi: `/wp-json/rtcl/v1my/store/banner`

## 10. Rewrite rules cached in `rewrite_rules` (175 RTCL-related of 472)

These are the exact regexes WordPress matched while the plugins were active. Grouped by prefix; full list in the collapsed block.

| Prefix | Rules | Example |
|---|---|---|
| `listings` | 4 | `listings/?$` |
| `stores` | 4 | `stores/?$` |
| `submit-a-listing` | 1 | `submit-a-listing/([^/]+)/([0-9]{1,})/?$` |
| `my-account` | 2 | `my-account/listings/page/?([0-9]{1,})/?$` |
| `listing-category` | 13 | `/listing-category/([^/]*)/?$` |
| `listing-location` | 9 | `/listing-location/([^/]*)/?$` |
| `category` | 1 | `category/(.+?)/rtcl-api(/(.*))?/?$` |
| `tag` | 1 | `tag/([^/]+)/rtcl-api(/(.*))?/?$` |
| `listing-tag` | 5 | `listing-tag/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` |
| `rtcl_listing` | 22 | `rtcl_listing/[^/]+/attachment/([^/]+)/?$` |
| `rtcl_payment` | 20 | `rtcl_payment/[^/]+/attachment/([^/]+)/?$` |
| `rtcl_pricing` | 20 | `rtcl_pricing/[^/]+/attachment/([^/]+)/?$` |
| `store-category` | 5 | `store-category/(.+?)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` |
| `store` | 22 | `store/[^/]+/attachment/([^/]+)/?$` |
| `cs_template` | 3 | `cs_template/([^/]+)/rtcl-api(/(.*))?/?$` |
| `cs_user_templates` | 3 | `cs_user_templates/([^/]+)/rtcl-api(/(.*))?/?$` |
| `cs_global_block` | 3 | `cs_global_block/([^/]+)/rtcl-api(/(.*))?/?$` |
| `cs_header` | 3 | `cs_header/([^/]+)/rtcl-api(/(.*))?/?$` |
| `cs_footer` | 3 | `cs_footer/([^/]+)/rtcl-api(/(.*))?/?$` |
| `cs_layout_single` | 3 | `cs_layout_single/([^/]+)/rtcl-api(/(.*))?/?$` |
| `cs_layout_archive` | 3 | `cs_layout_archive/([^/]+)/rtcl-api(/(.*))?/?$` |
| `cs_layout` | 3 | `cs_layout/([^/]+)/rtcl-api(/(.*))?/?$` |
| `portfolio-item` | 3 | `portfolio-item/([^/]+)/rtcl-api(/(.*))?/?$` |
| `envira` | 3 | `envira/([^/]+)/rtcl-api(/(.*))?/?$` |
| `rtcl-api` | 1 | `rtcl-api(/(.*))?/?$` |
| `comments` | 1 | `comments/rtcl-api(/(.*))?/?$` |
| `search` | 1 | `search/(.+)/rtcl-api(/(.*))?/?$` |
| `author` | 1 | `author/([^/]+)/rtcl-api(/(.*))?/?$` |
| `([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/rtcl-api(/(.*))?/?$` | 1 | `([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/rtcl-api(/(.*))?/?$` |
| `([0-9]{4})/([0-9]{1,2})/rtcl-api(/(.*))?/?$` | 1 | `([0-9]{4})/([0-9]{1,2})/rtcl-api(/(.*))?/?$` |
| `([0-9]{4})/rtcl-api(/(.*))?/?$` | 1 | `([0-9]{4})/rtcl-api(/(.*))?/?$` |
| `(.?.+?)/rtcl-api(/(.*))?/?$` | 1 | `(.?.+?)/rtcl-api(/(.*))?/?$` |
| `(.?.+?)/listings(/(.*))?/?$` | 1 | `(.?.+?)/listings(/(.*))?/?$` |
| `(.?.+?)/https://south-poll-grass-cattle-association.local/submit-a-listing/(/(.*))?/?$` | 1 | `(.?.+?)/https://south-poll-grass-cattle-association.local/submit-a-listing/(/(.*))?/?$` |
| `(.?.+?)/my-store(/(.*))?/?$` | 1 | `(.?.+?)/my-store(/(.*))?/?$` |
| `.?.+?` | 2 | `.?.+?/([^/]+)/rtcl-api(/(.*))?/?$` |
| `([^/]+)/rtcl-api(/(.*))?/?$` | 1 | `([^/]+)/rtcl-api(/(.*))?/?$` |
| `[^` | 2 | `[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` |

<details><summary>All 175 rules</summary>

- `listings/?$` → `index.php?post_type=rtcl_listing`
- `listings/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?post_type=rtcl_listing&feed=$matches[1]`
- `listings/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?post_type=rtcl_listing&feed=$matches[1]`
- `listings/page/([0-9]{1,})/?$` → `index.php?post_type=rtcl_listing&paged=$matches[1]`
- `stores/?$` → `index.php?post_type=store`
- `stores/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?post_type=store&feed=$matches[1]`
- `stores/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?post_type=store&feed=$matches[1]`
- `stores/page/([0-9]{1,})/?$` → `index.php?post_type=store&paged=$matches[1]`
- `submit-a-listing/([^/]+)/([0-9]{1,})/?$` → `index.php?page_id=646&rtcl_action=$matches[1]&rtcl_listing_id=$matches[2]`
- `my-account/listings/page/?([0-9]{1,})/?$` → `index.php?listings=&page_id=337&paged=$matches[1]`
- `my-account/payments/page/?([0-9]{1,})/?$` → `index.php?payments=&page_id=337&paged=$matches[1]`
- `/listing-category/([^/]*)/?$` → `index.php?page_id=-1&__cat=$matches[1]`
- `/listing-category/([^/]*)/page/([^/]*)` → `index.php?page_id=-1&__cat=$matches[1]&__page=$matches[2]`
- `/listing-location/([^/]*)/?$` → `index.php?page_id=-1&__loc=$matches[1]`
- `/listing-location/([^/]*)/page/([^/]*)` → `index.php?page_id=-1&__loc=$matches[1]&__page=$matches[2]`
- `/listing-category/([^/]*)/tag/([^/]*)` → `index.php?page_id=-1&__cat=$matches[1]&__tag=$matches[2]`
- `/listing-category/([^/]*)/tag/([^/]*)/page/([^/]*)` → `index.php?page_id=-1&__cat=$matches[1]&__tag=$matches[2]&__page=$matches[3]`
- `/listing-location/([^/]*)/tag/([^/]*)` → `index.php?page_id=-1&__loc=$matches[1]&__tag=$matches[2]`
- `/listing-location/([^/]*)/tag/([^/]*)/page/([^/]*)` → `index.php?page_id=-1&__loc=$matches[1]&__tag=$matches[2]&__page=$matches[3]`
- `/listing-category/([^/]+)/listing-location/([^/]+)/tag/([^/]*)` → `index.php?page_id=-1&__cat=$matches[1]&__loc=$matches[2]&__tag=$matches[3]`
- `/listing-category/([^/]+)/listing-location/([^/]+)/tag/([^/]*)/page/([^/]*)` → `index.php?page_id=-1&__cat=$matches[1]&__loc=$matches[2]&__tag=$matches[3]&__page=$matches[4]`
- `/listing-category/([^/]*)/listing-location/([^/]*)/page/([^/]*)` → `index.php?page_id=-1&__cat=$matches[1]&__loc=$matches[2]&__page=$matches[3]`
- `/listing-category/([^/]*)/listing-location/([^/]*)` → `index.php?page_id=-1&__cat=$matches[1]&__loc=$matches[2]`
- `category/(.+?)/rtcl-api(/(.*))?/?$` → `index.php?category_name=$matches[1]&rtcl-api=$matches[3]`
- `tag/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?tag=$matches[1]&rtcl-api=$matches[3]`
- `listing-category/(.+?)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?rtcl_category=$matches[1]&feed=$matches[2]`
- `listing-category/(.+?)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?rtcl_category=$matches[1]&feed=$matches[2]`
- `listing-category/(.+?)/embed/?$` → `index.php?rtcl_category=$matches[1]&embed=true`
- `listing-category/(.+?)/page/?([0-9]{1,})/?$` → `index.php?rtcl_category=$matches[1]&paged=$matches[2]`
- `listing-category/(.+?)/?$` → `index.php?rtcl_category=$matches[1]`
- `listing-location/(.+?)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?rtcl_location=$matches[1]&feed=$matches[2]`
- `listing-location/(.+?)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?rtcl_location=$matches[1]&feed=$matches[2]`
- `listing-location/(.+?)/embed/?$` → `index.php?rtcl_location=$matches[1]&embed=true`
- `listing-location/(.+?)/page/?([0-9]{1,})/?$` → `index.php?rtcl_location=$matches[1]&paged=$matches[2]`
- `listing-location/(.+?)/?$` → `index.php?rtcl_location=$matches[1]`
- `listing-tag/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?rtcl_tag=$matches[1]&feed=$matches[2]`
- `listing-tag/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?rtcl_tag=$matches[1]&feed=$matches[2]`
- `listing-tag/([^/]+)/embed/?$` → `index.php?rtcl_tag=$matches[1]&embed=true`
- `listing-tag/([^/]+)/page/?([0-9]{1,})/?$` → `index.php?rtcl_tag=$matches[1]&paged=$matches[2]`
- `listing-tag/([^/]+)/?$` → `index.php?rtcl_tag=$matches[1]`
- `rtcl_listing/[^/]+/attachment/([^/]+)/?$` → `index.php?attachment=$matches[1]`
- `rtcl_listing/[^/]+/attachment/([^/]+)/trackback/?$` → `index.php?attachment=$matches[1]&tb=1`
- `rtcl_listing/[^/]+/attachment/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_listing/[^/]+/attachment/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_listing/[^/]+/attachment/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?attachment=$matches[1]&cpage=$matches[2]`
- `rtcl_listing/[^/]+/attachment/([^/]+)/embed/?$` → `index.php?attachment=$matches[1]&embed=true`
- `rtcl_listing/([^/]+)/embed/?$` → `index.php?rtcl_listing=$matches[1]&embed=true`
- `rtcl_listing/([^/]+)/trackback/?$` → `index.php?rtcl_listing=$matches[1]&tb=1`
- `rtcl_listing/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?rtcl_listing=$matches[1]&feed=$matches[2]`
- `rtcl_listing/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?rtcl_listing=$matches[1]&feed=$matches[2]`
- `rtcl_listing/([^/]+)/page/?([0-9]{1,})/?$` → `index.php?rtcl_listing=$matches[1]&paged=$matches[2]`
- `rtcl_listing/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?rtcl_listing=$matches[1]&cpage=$matches[2]`
- `rtcl_listing/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?rtcl_listing=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_listing/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_listing/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_listing/([^/]+)(?:/([0-9]+))?/?$` → `index.php?rtcl_listing=$matches[1]&page=$matches[2]`
- `rtcl_listing/[^/]+/([^/]+)/?$` → `index.php?attachment=$matches[1]`
- `rtcl_listing/[^/]+/([^/]+)/trackback/?$` → `index.php?attachment=$matches[1]&tb=1`
- `rtcl_listing/[^/]+/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_listing/[^/]+/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_listing/[^/]+/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?attachment=$matches[1]&cpage=$matches[2]`
- `rtcl_listing/[^/]+/([^/]+)/embed/?$` → `index.php?attachment=$matches[1]&embed=true`
- `rtcl_payment/[^/]+/attachment/([^/]+)/?$` → `index.php?attachment=$matches[1]`
- `rtcl_payment/[^/]+/attachment/([^/]+)/trackback/?$` → `index.php?attachment=$matches[1]&tb=1`
- `rtcl_payment/[^/]+/attachment/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_payment/[^/]+/attachment/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_payment/[^/]+/attachment/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?attachment=$matches[1]&cpage=$matches[2]`
- `rtcl_payment/[^/]+/attachment/([^/]+)/embed/?$` → `index.php?attachment=$matches[1]&embed=true`
- `rtcl_payment/([^/]+)/embed/?$` → `index.php?rtcl_payment=$matches[1]&embed=true`
- `rtcl_payment/([^/]+)/trackback/?$` → `index.php?rtcl_payment=$matches[1]&tb=1`
- `rtcl_payment/([^/]+)/page/?([0-9]{1,})/?$` → `index.php?rtcl_payment=$matches[1]&paged=$matches[2]`
- `rtcl_payment/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?rtcl_payment=$matches[1]&cpage=$matches[2]`
- `rtcl_payment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?rtcl_payment=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_payment/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_payment/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_payment/([^/]+)(?:/([0-9]+))?/?$` → `index.php?rtcl_payment=$matches[1]&page=$matches[2]`
- `rtcl_payment/[^/]+/([^/]+)/?$` → `index.php?attachment=$matches[1]`
- `rtcl_payment/[^/]+/([^/]+)/trackback/?$` → `index.php?attachment=$matches[1]&tb=1`
- `rtcl_payment/[^/]+/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_payment/[^/]+/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_payment/[^/]+/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?attachment=$matches[1]&cpage=$matches[2]`
- `rtcl_payment/[^/]+/([^/]+)/embed/?$` → `index.php?attachment=$matches[1]&embed=true`
- `rtcl_pricing/[^/]+/attachment/([^/]+)/?$` → `index.php?attachment=$matches[1]`
- `rtcl_pricing/[^/]+/attachment/([^/]+)/trackback/?$` → `index.php?attachment=$matches[1]&tb=1`
- `rtcl_pricing/[^/]+/attachment/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_pricing/[^/]+/attachment/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_pricing/[^/]+/attachment/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?attachment=$matches[1]&cpage=$matches[2]`
- `rtcl_pricing/[^/]+/attachment/([^/]+)/embed/?$` → `index.php?attachment=$matches[1]&embed=true`
- `rtcl_pricing/([^/]+)/embed/?$` → `index.php?rtcl_pricing=$matches[1]&embed=true`
- `rtcl_pricing/([^/]+)/trackback/?$` → `index.php?rtcl_pricing=$matches[1]&tb=1`
- `rtcl_pricing/([^/]+)/page/?([0-9]{1,})/?$` → `index.php?rtcl_pricing=$matches[1]&paged=$matches[2]`
- `rtcl_pricing/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?rtcl_pricing=$matches[1]&cpage=$matches[2]`
- `rtcl_pricing/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?rtcl_pricing=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_pricing/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_pricing/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `rtcl_pricing/([^/]+)(?:/([0-9]+))?/?$` → `index.php?rtcl_pricing=$matches[1]&page=$matches[2]`
- `rtcl_pricing/[^/]+/([^/]+)/?$` → `index.php?attachment=$matches[1]`
- `rtcl_pricing/[^/]+/([^/]+)/trackback/?$` → `index.php?attachment=$matches[1]&tb=1`
- `rtcl_pricing/[^/]+/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_pricing/[^/]+/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `rtcl_pricing/[^/]+/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?attachment=$matches[1]&cpage=$matches[2]`
- `rtcl_pricing/[^/]+/([^/]+)/embed/?$` → `index.php?attachment=$matches[1]&embed=true`
- `store-category/(.+?)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?store_category=$matches[1]&feed=$matches[2]`
- `store-category/(.+?)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?store_category=$matches[1]&feed=$matches[2]`
- `store-category/(.+?)/embed/?$` → `index.php?store_category=$matches[1]&embed=true`
- `store-category/(.+?)/page/?([0-9]{1,})/?$` → `index.php?store_category=$matches[1]&paged=$matches[2]`
- `store-category/(.+?)/?$` → `index.php?store_category=$matches[1]`
- `store/[^/]+/attachment/([^/]+)/?$` → `index.php?attachment=$matches[1]`
- `store/[^/]+/attachment/([^/]+)/trackback/?$` → `index.php?attachment=$matches[1]&tb=1`
- `store/[^/]+/attachment/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `store/[^/]+/attachment/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `store/[^/]+/attachment/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?attachment=$matches[1]&cpage=$matches[2]`
- `store/[^/]+/attachment/([^/]+)/embed/?$` → `index.php?attachment=$matches[1]&embed=true`
- `store/([^/]+)/embed/?$` → `index.php?store=$matches[1]&embed=true`
- `store/([^/]+)/trackback/?$` → `index.php?store=$matches[1]&tb=1`
- `store/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?store=$matches[1]&feed=$matches[2]`
- `store/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?store=$matches[1]&feed=$matches[2]`
- `store/([^/]+)/page/?([0-9]{1,})/?$` → `index.php?store=$matches[1]&paged=$matches[2]`
- `store/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?store=$matches[1]&cpage=$matches[2]`
- `store/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?store=$matches[1]&rtcl-api=$matches[3]`
- `store/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `store/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `store/([^/]+)(?:/([0-9]+))?/?$` → `index.php?store=$matches[1]&page=$matches[2]`
- `store/[^/]+/([^/]+)/?$` → `index.php?attachment=$matches[1]`
- `store/[^/]+/([^/]+)/trackback/?$` → `index.php?attachment=$matches[1]&tb=1`
- `store/[^/]+/([^/]+)/feed/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `store/[^/]+/([^/]+)/(feed\|rdf\|rss\|rss2\|atom)/?$` → `index.php?attachment=$matches[1]&feed=$matches[2]`
- `store/[^/]+/([^/]+)/comment-page-([0-9]{1,})/?$` → `index.php?attachment=$matches[1]&cpage=$matches[2]`
- `store/[^/]+/([^/]+)/embed/?$` → `index.php?attachment=$matches[1]&embed=true`
- `cs_template/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?cs_template=$matches[1]&rtcl-api=$matches[3]`
- `cs_template/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_template/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_user_templates/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?cs_user_templates=$matches[1]&rtcl-api=$matches[3]`
- `cs_user_templates/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_user_templates/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_global_block/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?cs_global_block=$matches[1]&rtcl-api=$matches[3]`
- `cs_global_block/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_global_block/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_header/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?cs_header=$matches[1]&rtcl-api=$matches[3]`
- `cs_header/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_header/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_footer/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?cs_footer=$matches[1]&rtcl-api=$matches[3]`
- `cs_footer/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_footer/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout_single/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?cs_layout_single=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout_single/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout_single/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout_archive/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?cs_layout_archive=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout_archive/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout_archive/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?cs_layout=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `cs_layout/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `portfolio-item/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?x-portfolio=$matches[1]&rtcl-api=$matches[3]`
- `portfolio-item/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `portfolio-item/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `envira/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?envira=$matches[1]&rtcl-api=$matches[3]`
- `envira/[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `envira/[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `rtcl-api(/(.*))?/?$` → `index.php?&rtcl-api=$matches[2]`
- `comments/rtcl-api(/(.*))?/?$` → `index.php?&rtcl-api=$matches[2]`
- `search/(.+)/rtcl-api(/(.*))?/?$` → `index.php?s=$matches[1]&rtcl-api=$matches[3]`
- `author/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?author_name=$matches[1]&rtcl-api=$matches[3]`
- `([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/rtcl-api(/(.*))?/?$` → `index.php?year=$matches[1]&monthnum=$matches[2]&day=$matches[3]&rtcl-api=$matches[5]`
- `([0-9]{4})/([0-9]{1,2})/rtcl-api(/(.*))?/?$` → `index.php?year=$matches[1]&monthnum=$matches[2]&rtcl-api=$matches[4]`
- `([0-9]{4})/rtcl-api(/(.*))?/?$` → `index.php?year=$matches[1]&rtcl-api=$matches[3]`
- `(.?.+?)/rtcl-api(/(.*))?/?$` → `index.php?pagename=$matches[1]&rtcl-api=$matches[3]`
- `(.?.+?)/listings(/(.*))?/?$` → `index.php?pagename=$matches[1]&listings=$matches[3]`
- `(.?.+?)/https://south-poll-grass-cattle-association.local/submit-a-listing/(/(.*))?/?$` → `index.php?pagename=$matches[1]&https://south-poll-grass-cattle-association.local/submit-a-listing/=$matches[3]`
- `(.?.+?)/my-store(/(.*))?/?$` → `index.php?pagename=$matches[1]&my-store=$matches[3]`
- `.?.+?/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `.?.+?/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?name=$matches[1]&rtcl-api=$matches[3]`
- `[^/]+/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`
- `[^/]+/attachment/([^/]+)/rtcl-api(/(.*))?/?$` → `index.php?attachment=$matches[1]&rtcl-api=$matches[3]`

</details>

## 11. Stale URLs members and bots still request (from `wp-content/southpoll-debug.log`)

| Requested path | 404 count | Likely cause / redirect suggestion |
|---|---|---|
| `/website-faq/my-account/` | 120 | Relative links on the FAQ page → redirect to `/my-account/` |
| `/website-faq/my-account/lost-password/` | 120 | Relative links on the FAQ page → redirect to `/my-account/lost-password/` |
| `/website-faq/my-account/store/` | 117 | Relative links on the FAQ page → redirect to `/my-account/store/` |
| `/website-faq/my-account/listings/` | 60 | Relative links on the FAQ page → redirect to `/my-account/listings/` |
| `/website-faq/my-account/edit-account/` | 56 | Relative links on the FAQ page → redirect to `/my-account/edit-account/` |
| `/store/.env` | 34 | Bot probe / source map → none |
| `/wp-content/plugins/classified-listing-store/assets/js/store.js.map` | 31 | Bot probe / source map → none |
| `/store/` | 29 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store` | 24 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/my-account/my-store/` | 12 | POST 404s from the broken store save (fixed by mu-plugin) |
| `/store/brother-joseph/` | 9 |  |
| `/store/membership` | 9 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/product/richardson-ball-cap-red-white-blue-corner-logo` | 7 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/product/navy-boonie-hat` | 7 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/product/spgca-membership` | 7 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/%252eenv` | 5 |  |
| `/store/product/richardson-ball-cap` | 4 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/klp/tel:8166298010` | 4 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/product/richardson-red-white-blue-large-logo` | 3 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/books` | 3 |  |
| `/store/product/ladies-ball-cap-with-ponytail-back-blue` | 3 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/apparel` | 3 |  |
| `/store/5067/tel:8166298010` | 3 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/5070/tel:8166298010` | 3 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/5590/tel:2569968355` | 3 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/product/ball-cap-with-south-poll-logo-solid-khaki` | 3 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/restore.php` | 3 |  |
| `/store/product/spgca-ball-cap-solid-dark-green` | 3 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/support-farmers/tel:8166298010` | 3 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/support-farmers/tel:2569968355` | 3 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/kunert-cattle-company/tel:2569968355` | 3 | tel: link rendered relative on farm page → none (fix markup) |
| `/store-category/south/tel:2569968355` | 3 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/5014/tel:8166298010` | 3 | tel: link rendered relative on farm page → none (fix markup) |
| `/my-account/store/settings/` | 2 | Pre-rename farm editor URL → redirect to new farm editor |
| `/store/3975/` | 2 |  |
| `/store/product/green-boonie-hat` | 2 | Old WooCommerce-era shop URLs → redirect `/store/`→ `/members/` or itemorder shop; `/store/membership` → join page |
| `/store/1659/tel:2569968355/` | 2 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/rafters4cattleco/tel:2569968355` | 2 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/4229/tel:8166298010` | 2 | tel: link rendered relative on farm page → none (fix markup) |
| `/store/5192/tel:2569968355` | 2 | tel: link rendered relative on farm page → none (fix markup) |

## 12. Existing 301 rules (301 Redirects plugin, `wp_redirects`)

- `/my-account/https:/southpoll.com/submit-a-listing/` → page #646 (`https://south-poll-grass-cattle-association.local/submit-a-listing/`) (301, 927 hits) — retire after launch

## 13. URLs embedded in RTCL emails (members may click these months later)

- Listing published / expired / renewal emails → `/rtcl_listing/{slug}/` and `/checkout/promote/{id}/`
- Order created / completed emails → `/checkout/payment-receipt/{order_id}/`
- New registration / reset password → `/my-account/` and `/my-account/lost-password/?key=…&login=…`
- Custom admin membership email → `{site_url}` only
