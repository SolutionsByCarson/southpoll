# Here is the outline for the SouthPoll Membership Plugin.  Note. This plugin is being authored by Carson Bennett with Ranch House Designs.

#  Overview:

This plugin adds functionality by which users can create an account and buy or sell cattle on the south poll website. It creates a farm membership listing page, as well as various classified listing pages where individual listings can be accessed and viewed. Contact Information is not publicly visible unless users are logged in, but they can submit contact form inquiries (with recaptcha) without creating an account. Emails will be routed from the website to that user’s email.

# Database Architecture:

* This plugin will have a custom tables for users and farms (a 1-to-many database) data that stores all data for site members, their farms, their subscription/membership data, etc. \- will all live in this table in flat files.  
* these tables is separate and distinct from WordPress user table.  
* Favorites and Saved searches should be in their own custom tables as well  
* While the data for the membership listing and farm details will be pulled from that table. Data for listings themselves and their respective taxonomies, will be integrated as a custom post type.

# Functionality/Integrations

This plugin will use the following integrations. This list may not be comprehensive.

* Google Maps: For searching classified listings by map/location, displaying maps on farm pages and generating “Get Directions” links.  
* ReCaptcha: For user registration \+ login pages, as well as non-logged-in contact forms. Contact forms should also have very discreet honeypot fields as well to give multiple layers of protection.  
* Stripe: Stripe integration for handling membership purchases.  
* SMTP: Plugin should use the wordpress sites default smtp mail delivery, but should have the option to override the from email. The plugin should have a simple formatted html template with the SouthPoll Site logo (here [https\://south-poll-grass-cattle-association.local/wp-content/uploads/2024/04/SouthPoll-HorizontalLogo.png](https://south-poll-grass-cattle-association.local/wp-content/uploads/2024/04/SouthPoll-HorizontalLogo.png)) that is used for all email correspondence. (Store the logo in the plugin files to prevent it from being overriden).

# Design and User Experience:

* This plugin should come branded to match SouthPolls colors and fonts. It should be highly mobile friendly, sleek, but simple.  
* Numbers on admin screens should be animated count up where applicable, but not too much motion.  
* On admin screens in the wp-admin area and in the member area, any table displaying records should always be ajax/real time searchable (search all fields and live update results), columns should toggle to sort, with configurable pagination (10,25,50,100,all)  
* Frontend listing pages (both membership and classifieds) should allow map views  
* Search and filters are always real-time \- no submits \- and resets should be simple. URLs should  
* All share links should use the same modal share function and include the same options \- share via email, text, social, copy/paste. Sharing via email should open an email with descriptive subject and body with the link already included.  
* Bulk Export/Import: wp admin area screens should allow bulk export and import of csvs and should include a downloadable csv import template.  
* Email Notifications: there are email notifications configured across the platform with favorites and saved searches, these should always default to being turned off.  
* Slideout Notifications: Adding favorites, saving searches, sending messages, etc. Anything that would appear inline should display in slide out notifications \- a single reusable function. If adding a favorite it should include a link to the my favorites page. If saving a search, it should include a link to the my saved searches page. If

# User Types:

* There are four user types/privilege profiles, public/nonpaid accounts have limited privileges, three privileged that require account creation/login \- free/buyer, paid/member, and free-but-not-free/account-manager (not paid individually, but requires a paid account).  
* Non-account users. Can browse the membership listing page as well as classified pages, but cannot see farmer contact information anywhere, they can reach out through the contact forms though w/ recaptcha.  
* Free users have accounts but are not members of SouthPoll, are unpaid. These are buyers. They can do everything non-account users can, but they can see farmer contact information. They can also do the following:  
  * Favorites: They can create a list of favorites for viewing later.   
  * Saved Searches: While searching classifieds, they can save a currently configured search/filter. They can configure a default search that appears when they go to the classifieds page. They can quickly swap between multiple searches. And they can configure email notifications \- i.e. notify me when a new classified is added to this search.  
* Paid users are members. In addition to what free and non-account users can do, they can create a farm page that displays on the membership listing page, and can create/publish classified listings with limits that are configured by site admins.

# Account Management Screens:

* This plugin will create many pages/endpoints for the user workflow. These pages should override whatever is displayed on those pages with/without them existing in pages.  
* Login Page: /members/login/. This page will display the account creation and password reset workflow. You are prompted to either login or create an account. While creating account, you can choose between a free membership (buyer) or a paid membership. When selecting an offline payment, you can either pay now (recurring or not-recurring, defaults to not-recurring) or you can ask for confirmation of offline payment (did you pay in person via cash or check? If so, it will send a notification to the membership admin for verification. This page should always list/detail the differences between the various membership types.  
  * If not logged in, any /members/\* endpoint will redirect here with a notification advising \- you need to be logged in to view this page.  
* Dashboard: /members/dashboard/. Only viewable by logged in users. This page has multiple pages depending on your membership status:  
  * Note: Account pages should not use the standard WordPress header and footer, and should be a standalone admin area with a sidebar \- icons \+ titles for each page. The SouthPoll Logo should be displayed in the top section of the sidebar with links to return to the site.  
  * Homepage: /members/dashboard/ \-   
    * For all users \- this page displays user name \+ email  
    * For free users \-  
      * this page shows a banner prompting them to purchase a membership.   
      * Below it, it shows a card containing a summary of their currently active favorites listings (prompts to view inactive favorites if there are favorites that have been delisted, prompts to view classifieds if there are no currently active favorites). Favorites in this list should be able to be removed.   
      * It also shows a summary of their current saved searches, with a live feed of classifieds from their saved searches and links to view them.  
    * For members \-  
      * this page will show a banner summarizing their current membership status, when it is set to expire, with special flags for when it’s 30 days away, and counting down from 7 days. If the user is not set to renew, it will offer for them to re-up right now and/or setup recurring membership.  
      * If they have recurring membership setup, once they are 30 days away, it will notify them that their membership will renew in X days.  
      * Below it, it will show a card with their currently active listings (Limit up to 10, a max number admins can configure), followed by the favorites \+ saved searches that buyers see.  
  * **My Favorites: /members/my-favorites/**  
    * All users have the same favorites page.  
    * This page displays a paginated and quick searchable list of their favorites (toggle betwee farms and listings), a numbered card selector switches between active and inactive, numbers updating with the search/query. If no favorites exist, show a prompt to view the classifieds page. If no active favorites exist, show inactive.  
    * Here they can easily remove/manage favorites.  
    * Each favorite has a share button, allowing them to share via email, text, social media, etc.  
    * Favorited farms can be updated so that the user receives an email whenever they release a new listing. (Toggle inline on the list page, as well as configurable default (always subscribe), and bulk toggle on/off)  
    * Favorited listings can be updated so that the user receives an email whenever it’s status changes (Toggle inline on the list page, as well as configurable default (always subscribe), and bulk toggle on/off)  
    * At the top, display a share my favorites list \- which opens a modal with a copyable url with same share options (email, text, social share, etc.), allowing the user to share a publicly accessible list of their favorites:  
      * Public favorites use a custom endpoint: /my-favorites/?by=\[email\]  
  * **My Saved Searches: /members/saved-searches/**  
    * All users have the Saved searches page.  
    * This page displays a simple, paginated, and searchable list of their saved searches. Here they can easily manage/remove them, set one as their default (always open classifieds with this search enabled).  
    * Saved searches can be configured to receive email notifications when a new listing is added to their search (toggle inline on the list page, as well as configurable default (always subscribe to my saved searches), and bulk toggle on/off)  
    * Each saved search should show the number of new listings (since last view or in the last week)  
    * If no saved searches exist, it shows a prompt to browse our classifieds.  
    * Each search page should be shareable via email, text, social, etc.  
  * **My Farm \+ Users: /members/my-farm/**  
    * Only members and account managers can view the My Farm Page.  
    * This page shows an editable form containing their farm data (more info in a following section).  
    * If the user is a member, this page also contains a manageable list of users, where they can invite new users via email as an account manager. If they remove an account manager, that users account will fall back to a free/buyer account. In this table, the member can configure who which users will receive listing notifications and subscription notifications.  
    * If the user is an account manager, they will see a card/notification here showing that they are an account manager, and will not see the full membership list. If they need to add a user, it will show their member’s email and suggest they forward the request to them (no form or notification, just a mailto: link).  
  * **My Listings: /members/my-listings/**  
    * Only members and account managers can view the my listings page.  
    * This page displays number cards showing how many listings they currently have active, as well as how many open listings they remaining.  
    * If no open listings remain, they are not able to create new listings.  
    * Here they can quickly search/manage listings, are able to quickly toggle listings active/inactive, duplicate or copy past listings, or delete listings entirely.  
    * Display option to enable/disable contact form on the farm page to non-logged in users.  
    * Add/Edit Listing \- /members/edit-listing/?id=\[random-int\] When creating a new listing or editing an existing listing, goes to a comprehensive form where all listing details can be edited (more info in later section).  
  * **My Account: /members/account-details/**  
    * All users have the account details page.  
    * This page displays all editable user fields, name, address, email, phone, etc.   
    * This page displays email notification options, with options to enable/disable all (favorites, saved searches, etc.) \- Note, membership emails and invoices are always sent/not configurable.  
    * If owner, display option to enable/disable contact form submissions (non logged in users) on farm page. If disabled, the contact form will not appear on their farm page.  
  * **My Membership: /members/manage-membership/**  
    * All users have access to the membership page.  
    * Free or Expired users will see the membership checkout process (with option for claiming offline payment as well)  
    * Members will see which user is the owner, and can optionally assign a billing user/email if they want invoices/receipts sent somewhere else.  
    * Members will see their current memberships status and duration, their current payment method (can change via modal), their invoice history. And their membership recurring status \- can change here.  
  * **Website How To (name this better please): /members/FAQ/**  
    * All users have access to this page, it displays extensive how tos and FAQs guiding users on how to use the membership site \+ listings.  
  * **Logout: Button to log out of their account.**

# Member Directory Functionality

Here are details for the membership directory functionality.

* **Member Directory lives at /members/ endpoint**. It should keep the WordPress header \+ footer, but override the content itself. The plugin should also create a shortcode **\[member-directory\]** and allow a url/page override in the admin area (this way if any generated links in correspondence, it automatically updates to use these endpoints.  
* The membership directory page will display a searchable and filterable list of all farms associated with active members (must have an active membership).  
* **Search & Filter:**  
  * Sort (name ascending/descending, most active)  
  * Search by text (live update search)  
  * Filter by State  
  * Filter by Region (see C:\\Users\\Carson\\Local Sites\\south-poll-grass-cattle-association\\app\\public\\wp-content\\themes\\pro-child\\functions.php for how we manage regions \< note, this field should be auto-calculated, not manually selected on farm pages).  
  * Other filters  
    * Has active listings  
    * Published in (last 24 hours, 3 days, last week, last month)  
    * Offers (Configurable taxonomy for farms indicating what they offer, starting terms will be Females, Bulls, Finishers, Feeders, Calves, Semen)  
    * Stock Available (Configurable taxonomy for Classifieds more details in classified functionality section, filter for farms that have active classifieds with these categories)  
* Card Feed should display the logo, farm name, owner, city \+ state, and a green/red light beside their number of active listings. If they have a listing that was posted in the last week, they should have a badge showing “new listing” floating in the top right corner of their feature image/logo.  
* Enable Map View  
* **Taxonomy \- Offerings**  
  * A managed/configurable taxonomy of offerings.  
* **User Data Fields** \- Users are separate from farms and can have multiple account owners/users. Here are the fields that I’d like to specify, go ahead and add any additional if needed.  
  * First Name  
  * Last Name  
  * Email  
  * Phone  
  * Role (Owner or AccountManager)  
  * Password (encrypted)  
* **Farm Data Fields (add as needed to fulfill design/ux requirements)**  
  * ID (unique ID)  
  * Name  
  * Logo  
  * Banner Image  
  * Gallery Photos (Photos, and Vimeo or Youtube videos)  
  * Open Hours (select days and times for each day)  
  * slogan  
  * slug/url \- auto-generate from farm name, don’t allow duplicates  
  * Address  
  * Region (auto-calculate, may not even need to be in db)  
  * Offerings (Configurable taxonomy, multi-select)  
  * Owner Name (defaults to member name)  
  * Email (defaults to member email)  
  * Phone (defaults to member phone)  
  * Website  
  * About Us (Wysiwyg)  
  * Social Media Sites  
  * Owner (User ID)  
  * Breeder Number  
  * Billing User (User ID)  
  * Billing Information (? \< Not sure how this will be done since we are using stripe, recommend an approach)  
  * Membership Status (not a field, but a db configuration. Maybe membership is it’s own table and status is calculated, not stored)  
  * Meta Fields (see below)  
* **Farm Page Meta**  
  * A configurable list of fields that site admins can add/remove. These become form input fields on the farm edit screen and appear in a formatted table on the listing page.  
  * Fields include:  
    * Field Label (i.e. Age)  
    * Field Type (Text, Textarea, Select, True/False)  
    * Default Value  
  * Default Fields (configured at install, but can be editted/removed later)  
    * Forage Type (text)  
    * Cool Season (t/f)  
    * Warm Seasons (t/f)  
    * Cover Crops (text)  
    * Grazing System (text)  
    * Feeding Program (text)  
    * Feeding Notes (text)  
* **Farm Page Features**  
  * Beautiful and functional pages showing their banner \+ logo, gallery, and information.  
  * Show lights by name \+ logo \- red if no active listings, green if active listings, new listing badge if a listing was published in the last 7 days.  
  * Contact information is only visible to logged in users.  
  * Non-logged in users can send a message to the owners via a contact form  
  * Star Icon allows logged in users to add farms to their favorites. If not logged in, display a slideout notification asking them to create an account. If logged in, the slideout should confirm their new favorite, display a link to their my favorites page, as well as a note that they can receive emails when they publish new listings.  
* **Note on memberships**: Memberships apply to farms, not users. And each user has an owner \- the original user who created it. I don’t want to specify the db structure for memberships/subscriptions \- use best practice and recommend an approach. I just want our admins to be able to bulk export farm data and use it to bulk modify registrations and manage users easily.

# Classifieds Functionality

Here is a summary of the classifieds.

* Main Classified Archive Page appears at /classifieds/  
* Taxonomy Archive Pages appear at /classifieds/term/ and should have configurable seo titles \+ descriptions.  
* **Listing Category \- Configurable Taxonomy**  
  * Default Options include females, bulls, finishers, feeders, calves, semen  
* **Listing Meta Fields**  
  * A configurable list of fields that site admins can add/remove. These become form input fields on the edit/create listing page and appear in a formatted table on the listing page.  
  * Can configure field groups by category (multi-select) or all. Each group appears in it’s own grouping on the edit page and listing page.  
  * Fields include:  
    * Field Label (i.e. Age)  
    * Field Type (Text, Select, True/False)  
    * Default Value  
  * Don’t include any fields by default. These will come later  
* **Classified Listing Fields/Table Data (suggested data, add what is needed to build functionality)**  
  * Ad title  
  * Optional excerpt (will pull ad copy if not configured)  
  * Price  
  * Ad Copy/Description (WYSIWYG)  
  * Categories (multi-select, noted above)  
  * Meta (noted above)  
  * Status (Available, Coming Soon, Pending, Sold, Inactive)  
  * Featured Image  
  * Gallery (Photos \+ Vimeo/YouTube Videos)  
* **Classifieds Archive Page Features**  
  * Map or Row Card View  
  * Listing Cards include featured image, ad title, post date (Published X days ago), price,seller \+ farm name, category pills, view count, description/excerpt. If the listing is less than 7 days old, show “New Listing” badge floating in top right corner of the featured image.  
  * Search & Filter  
    * Dynamic live text search (searches title, excerpt, farm, seller)  
    * Publish Date (24 hours, 3 days, week, month)  
    * Filter by Categories (taxonomy above)  
    * Filter by State or Region  
    * Filter by Farm (multiple select, text searchable list)  
  * Save Search. Search page should include the option to save this search. If not logged in, a slideout should appear saying this functionality requires an account and a link to create one. If they have an account, the slideout should confirm it has been added, display a link to the my saved searches page, and (if notification are not enabled) note that they can configure to be notified whenever a new listing is published, or (if notifications are enabled) it will note that they will receive a notice when a new listing is added.  
* **Classified Listing Page**  
  * Show all information on the page. Keep the layout in sync with farm page style.  
  * Ad title \+ price at the top  
  * Category, date, categories next  
  * Featured image \+ new listing badge beneath that. Thumbnails for gallery items below that (change out the featured image on click). Clicking on images opens them full screen. Videos open in full screen modal.  
  * Meta field values next  
  * Ad copy underneath.  
  * Farm details (including meta values) at the bottom.  
  * Non logged in users see a form to send the seller a message.  
  * Logged in users see their contact information.  
  * Relevant listings from the farmer listed at the bottom.  
  * Option to add the listing as a favorite (same workflow as the others)  
* **Listings should expire to keep feeds relevant**. Listings stay available for up to 60 days. Owner should be emailed a notification a week before with the option to renew. Renewing a listing should prompt the user to update any relevant information. Sold listings stay public briefly (14 days). Withdrawn or expired listings should not be visible on listing pages (but still viewable on favorites lists)  
* **Expired listings** \- don’t show a 404, show a “this listing is no longer available” with a link to that farmers page.

# Admin Settings:

- WordPress users can centrally manage the plugins settings, users, listings, farm pages, etc. All from within the WP admin area. They must have the role of editor or greater (admins included) to view the plugin config pages.  
- Admin pages should include (add more if needed)  
  - Plugin Settings (emails, default values, etc)  
  - Users  
  - Farms  
  - Listings  
  - Taxonomies  
  - Integrations (page where we can configure all APIs for integrations)  
  - Meta Fields  
  - Usage & Analytics (Do this last, let’s create a page showing analytics on usage, posting frequency, dollars in sales, etc.)  
- General \- do a deep dive on the features listed up until this point and ensure that anything that we should let our admins configure (i.e. set the limit of number of listings, expiration duration, etc.)  
- Configure a membership admin name, phone, and email for displaying where appropriate as well as for receiving notifications.

