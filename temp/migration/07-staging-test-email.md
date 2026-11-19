# Email to Kathy + the South Poll team — staging test round

Written for: the SPGCA office and board testers (non-technical). Staging URL filled in (https://southpollgrstg.wpenginepowered.com); the demo password is given in chat / by phone only (not stored here). Copy/paste version with styling: `07-staging-test-email.html` (open in a browser, Select All, Copy).


---

**Subject:** New SouthPoll member site is ready for you to test (staging)

Hi Kathy and team,

The new member site is up on a private test copy ("staging"). It has a copy of the real member data in it, so what you see is
what members will see after we go live. Nothing you do there affects the live site, and no real charges happen.

**Staging site:** https://southpollgrstg.wpenginepowered.com

**Please use these two test logins** (or your own account — see "Your own account" below):

| Account | Login page | Username | Password |
|---|---|---|---|
| Farm owner (an active member with a farm page and 7 listings; membership set to expire in 2 weeks so you can see the renewal notice) | https://southpollgrstg.wpenginepowered.com/members/login/ | `demo-owner` | (sent separately) |
| Buyer (a non-member account with favorites and saved searches) | https://southpollgrstg.wpenginepowered.com/members/login/ | `demo-buyer` | (sent separately) |

**Your own account:** every existing member was carried over. Go to https://southpollgrstg.wpenginepowered.com/members/login/, click "Forgot password?",
and set a new one for the test site (this does not change your password on the live site).

## What to test

1. **Member directory** — https://southpollgrstg.wpenginepowered.com/members/
   Search by farm or owner name, filter by state and region, switch to the map, click a pin, open a farm page. Does your farm show
   the right name, address, logo, photos and contact details?
2. **Classifieds** — https://southpollgrstg.wpenginepowered.com/classifieds/
   Filter by category and state, try the map (pins show the listings at each farm), open a listing. If you had listings on the
   old site they should all be here, with photos and "sold" marks.
3. **Log in and look at your dashboard** — https://southpollgrstg.wpenginepowered.com/members/dashboard/
   Check the membership status box, days remaining, and the quick cards.
4. **My Farm** — https://southpollgrstg.wpenginepowered.com/members/my-farm/
   Edit your farm details, upload a logo / banner / gallery photos, set what you offer (bulls, females, semen …), add an
   account manager, save. Then open your public farm page and confirm the changes show.
5. **My Listings** — https://southpollgrstg.wpenginepowered.com/members/my-listings/
   Add a new listing with photos, publish it, mark one as sold, renew an expiring one. Check it appears in the classifieds.
6. **Manage Membership** — https://southpollgrstg.wpenginepowered.com/members/manage-membership/
   - Renew online: use Stripe's test card **4242 4242 4242 4242**, any future expiry date, any 3-digit code, any ZIP. No real money moves.
   - "I paid by check or cash": submit a claim, then (office) approve it under SouthPoll → Memberships → Claims in the admin.
   - Turn automatic renewal on and off, and try "Cancel membership". Cancelling just stops automatic renewal; the membership
     runs to its end date. When auto-renew is already off the button is disabled and the page tells you the expiry date.
7. **Favorites and saved searches** (log in as `demo-buyer`) — https://southpollgrstg.wpenginepowered.com/members/my-favorites/ and
   https://southpollgrstg.wpenginepowered.com/members/saved-searches/
   Favorite a farm and a listing, save a search from the classifieds, apply it from the "saved searches" dropdown, remove it.
8. **Contact forms** — on a farm page and on a listing, send a message as a visitor (logged out). The farm owner's email receives
   it; test emails on staging may land in spam folders.
9. **Old links** — bookmarks and emails with old addresses should land on the right new page, e.g.
   https://southpollgrstg.wpenginepowered.com/my-account/, https://southpollgrstg.wpenginepowered.com/my-account/my-store/, https://southpollgrstg.wpenginepowered.com/listings/, an old `/store/…` or `/rtcl_listing/…` address.
10. **Join page** — https://southpollgrstg.wpenginepowered.com/join-spgca/ → "Join Today Via Online" → create a new account → activate a membership with the
    test card. (Use a real email address you can read; the verification email goes there.)

## For the office (Kathy): the admin side

Log in to https://southpollgrstg.wpenginepowered.com/wp-admin/ with your usual staff account, then open the **SouthPoll** menu:

- **Users / Farms / Listings** — search, filter, open a record, export CSV. Spot-check a few members you know.
- **Memberships** — the Claims tab (approve a check payment), extend a term, record an offline payment, cancel.
- **Settings** — notification addresses, renewal reminder days, and the new **Cattle registry digest**: the Monday and Thursday
  8 am email listing who to add to and remove from the registry. Press "Send registry digest now" on the **Status** page to get one
  immediately and check the format and the attached CSV.
- **Status** — shows the scheduled jobs and lets you send a test email.

## How to report what you find

Reply to this email (or email Carson directly) with: the page address, what you did, what you expected, what happened, and a
screenshot if you can. Please test on a phone as well as a computer. Nothing is final — this round is exactly for catching
wording, missing data and anything that feels wrong.

Thank you!
Carson
