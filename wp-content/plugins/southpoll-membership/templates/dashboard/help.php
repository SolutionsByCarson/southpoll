<?php
/**
 * Help Center (/members/help/), S10 / T6.3.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var string $tier_name
 * @var string $tier_price
 * @var int    $listing_days
 * @var int    $sold_days
 * @var int    $listing_limit
 * @var int    $renewal_days
 * @var int[]  $reminder_days
 * @var bool   $offline_claims
 * @var bool   $verify_required
 * @var bool   $feedback_enabled
 * @var string $feedback_label
 * @var string $contact_name
 * @var string $contact_email
 * @var string $contact_phone
 * @var string $lost_url
 */

defined( 'ABSPATH' ) || exit;

$sp_farm_role = $context->is_owner() || $context->is_manager() || $context->is_staff();
$sp_sections  = [
	'account'    => __( 'Account & login', 'southpoll-membership' ),
	'farm'       => __( 'Your farm page', 'southpoll-membership' ),
	'listings'   => __( 'Classified listings', 'southpoll-membership' ),
	'membership' => __( 'Membership & payment', 'southpoll-membership' ),
	'renewals'   => __( 'Renewals & cancelling', 'southpoll-membership' ),
	'favorites'  => __( 'Favorites & saved searches', 'southpoll-membership' ),
	'managers'   => __( 'Account managers', 'southpoll-membership' ),
	'contact'    => __( 'Contact & feedback', 'southpoll-membership' ),
];
$sp_qa = static function ( string $q, string $html ): void {
	echo '<details class="sp-help-item"><summary>' . esc_html( $q ) . '</summary><div class="sp-help-item__body">' . wp_kses_post( $html ) . '</div></details>';
};
$sp_link = static fn( string $href, string $label ): string => '<a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
?>
<div class="sp-help">
	<div class="sp-card sp-card--flat sp-help__intro">
		<p class="sp-eyebrow"><?php esc_html_e( 'Help Center', 'southpoll-membership' ); ?></p>
		<h2 class="sp-h2"><?php esc_html_e( 'How the member site works', 'southpoll-membership' ); ?></h2>
		<p class="sp-text-muted"><?php esc_html_e( 'Short answers to the questions we hear most. Open a heading to read the steps; every answer links to the screen it talks about.', 'southpoll-membership' ); ?></p>
		<nav class="sp-help__jump" aria-label="<?php esc_attr_e( 'Help topics', 'southpoll-membership' ); ?>">
			<?php foreach ( $sp_sections as $k => $label ) : ?><a class="sp-pill sp-pill--link" href="#help-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
		</nav>
	</div>

	<section class="sp-card sp-help__section" id="help-account">
		<h3 class="sp-card__title"><?php echo esc_html( $sp_sections['account'] ); ?></h3>
		<?php
		$sp_qa( __( 'How do I log in?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Go to %s and enter the e-mail address on your SPGCA record and your password. Your e-mail is your login; there is no separate username to remember.', 'southpoll-membership' ), $sp_link( $urls->login(), __( 'Member Login', 'southpoll-membership' ) ) ) . '</p>' );
		$sp_qa( __( 'I have never logged in to the new site. How do I set a password?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Use %s, enter the e-mail address SPGCA has for you, and follow the link in the e-mail you receive (check your spam folder if it does not arrive in a few minutes). The link lets you choose a new password; after that you log in normally.', 'southpoll-membership' ), $sp_link( $lost_url, __( 'Forgot your password?', 'southpoll-membership' ) ) ) . '</p>' );
		$sp_qa( __( 'I do not have an account yet. How do I create one?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Choose %s. A buyer account is free and lets you contact sellers, save favorites and set up search alerts. A member (farm) account adds a farm page in the directory and classified listings once the membership is paid.', 'southpoll-membership' ), $sp_link( $urls->register(), __( 'Create account', 'southpoll-membership' ) ) ) . '</p>' . ( $verify_required ? '<p>' . esc_html__( 'After signing up you receive a verification e-mail. Contact details of farms and sellers are shown only once your e-mail address is verified.', 'southpoll-membership' ) . '</p>' : '' ) );
		$sp_qa( __( 'How do I change my name, e-mail or password?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Open %s. Name, phone and address save straight away; a new e-mail address takes effect once you confirm it from the message we send to the new address; a new password needs your current one.', 'southpoll-membership' ), $sp_link( $urls->dashboard( 'account-details' ), __( 'Account Details', 'southpoll-membership' ) ) ) . '</p>' );
		$sp_qa( __( 'The site says I am locked out.', 'southpoll-membership' ), '<p>' . esc_html__( 'After several wrong passwords in a row the login is paused for a short while to protect your account. Wait a few minutes and try again, or use "Forgot your password?" to set a new one.', 'southpoll-membership' ) . '</p>' );
		?>
	</section>

	<section class="sp-card sp-help__section" id="help-farm">
		<h3 class="sp-card__title"><?php echo esc_html( $sp_sections['farm'] ); ?></h3>
		<?php
		$sp_qa( __( 'Where do I edit my farm page?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Everything about your farm lives on %s: name and slogan, the "about" text, address and map pin, phone, e-mail and website, social links, visiting hours, what you offer, logo, banner and photo gallery, plus the farm details fields (forage, hay, grazing style and so on). Press "Save farm page" at the bottom when you are done.', 'southpoll-membership' ), $sp_link( $urls->my_farm(), __( 'My Farm', 'southpoll-membership' ) ) ) . '</p>' );
		$sp_qa( __( 'Who can see my farm page?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Your farm page is listed in the %s while your membership is active. Anyone can see the page; contact details (phone, e-mail, directions) are shown only to logged-in, verified accounts. Visitors who are not logged in can still reach you through the contact form unless you turn it off on My Farm.', 'southpoll-membership' ), $sp_link( $urls->directory(), __( 'member directory', 'southpoll-membership' ) ) ) . '</p>' );
		$sp_qa( __( 'My farm is not on the map.', 'southpoll-membership' ), '<p>' . esc_html__( 'The map pin comes from the address on My Farm. Check the street, city, state and ZIP are filled in and spelled the way the post office writes them, save, and the pin appears within a few minutes. Visitors who are not logged in see pins rounded to the nearest town.', 'southpoll-membership' ) . '</p>' );
		$sp_qa( __( 'What photo sizes work best?', 'southpoll-membership' ), '<p>' . esc_html__( 'Logo: a square image, at least 400 × 400 pixels. Banner: a wide landscape image, about 1600 × 600 pixels. Gallery: JPG, PNG or WebP photos; the site resizes them for you. Drag photos in the gallery to reorder them and add a caption under each one.', 'southpoll-membership' ) . '</p>' );
		?>
	</section>

	<section class="sp-card sp-help__section" id="help-listings">
		<h3 class="sp-card__title"><?php echo esc_html( $sp_sections['listings'] ); ?></h3>
		<?php
		$sp_qa( __( 'How do I publish a classified listing?', 'southpoll-membership' ), '<ol><li>' . sprintf( esc_html__( 'Open %s and press "New listing".', 'southpoll-membership' ), $sp_link( $urls->my_listings(), __( 'My Listings', 'southpoll-membership' ) ) ) . '</li><li>' . esc_html__( 'Give it a title, tick the class (Bulls, Cows, Heifers, Steers or Semen), enter a price or leave it blank for "Call for price", and write the details.', 'southpoll-membership' ) . '</li><li>' . esc_html__( 'Add a featured photo and, if you like, more photos or a YouTube / Vimeo video.', 'southpoll-membership' ) . '</li><li><strong>' . esc_html__( 'Press Publish.', 'southpoll-membership' ) . '</strong> ' . esc_html__( '"Save draft" keeps your work but buyers cannot see a draft.', 'southpoll-membership' ) . '</li></ol>' );
		$sp_qa( __( 'My listing says "Draft" or Publish is greyed out.', 'southpoll-membership' ), '<p>' . esc_html__( 'A draft is only visible to you. Publishing needs an active farm membership and a free listing slot; when the Publish button is disabled the editor says which of the two is missing and links to the screen that fixes it. Once you publish, the listing shows "Live" at the top of the editor and in My Listings.', 'southpoll-membership' ) . '</p>' );
		$sp_qa( __( 'How long does a listing stay up?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'A listing runs for %1$d days from the day you publish it. We e-mail you %2$d days before it expires with a one-click "Renew" that starts a fresh %1$d days. Expired listings stay in My Listings so you can renew or copy them later.', 'southpoll-membership' ), $listing_days, $renewal_days ) . '</p>' );
		$sp_qa( __( 'How many listings can I have?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Up to %1$d live listings at a time. Drafts, paused and sold listings do not count. Mark a listing Sold or pause it to free a slot.', 'southpoll-membership' ), $listing_limit ) . '</p>' );
		$sp_qa( __( 'I sold the animals. What do I do with the listing?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Change the status to "Sold" (in the editor or from the status menu in My Listings). Sold listings stay visible with a Sold badge for %d days, then hide automatically. "Sale pending" and "Coming soon" work the same way while the sale is in progress.', 'southpoll-membership' ), $sold_days ) . '</p>' );
		$sp_qa( __( 'Can I pause a listing without deleting it?', 'southpoll-membership' ), '<p>' . esc_html__( 'Yes: "Pause (hide)" in the editor or "Pause" in My Listings takes it off the classifieds and keeps everything. Press Publish to show it again.', 'southpoll-membership' ) . '</p>' );
		?>
	</section>

	<section class="sp-card sp-help__section" id="help-membership">
		<h3 class="sp-card__title"><?php echo esc_html( $sp_sections['membership'] ); ?></h3>
		<?php
		$sp_qa( __( 'What does the membership cost and what does it include?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'The %1$s is %2$s per year. It keeps your farm in the member directory, lets you publish classified listings and covers your SPGCA cattle registry membership.', 'southpoll-membership' ), esc_html( $tier_name ), esc_html( $tier_price ) ) . '</p>' );
		$sp_qa( __( 'How do I pay online?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Open %s and press "Pay by card". You are taken to our secure checkout (Stripe); card details never touch this site. The membership is active the moment the payment goes through and you get a receipt by e-mail.', 'southpoll-membership' ), $sp_link( $urls->dashboard( 'manage-membership' ), __( 'My Membership', 'southpoll-membership' ) ) ) . '</p><p>' . esc_html__( 'If you pay before your current term ends, the new year is added to the end of it, so paying early never costs you time. The receipt shows the dates the payment covers.', 'southpoll-membership' ) . '</p>' );
		if ( $offline_claims ) {
			$sp_qa( __( 'I paid by check or cash. How do I activate the membership?', 'southpoll-membership' ), '<p>' . esc_html__( 'On My Membership choose "I paid by check / cash" and tell us the amount and date. The office confirms the payment and switches the membership on; you receive an e-mail when that happens.', 'southpoll-membership' ) . '</p>' );
		}
		$sp_qa( __( 'Where is my receipt?', 'southpoll-membership' ), '<p>' . esc_html__( 'Every card payment sends a receipt to your e-mail address, and the payment history on My Membership lists every payment with its date and amount.', 'southpoll-membership' ) . '</p>' );
		?>
	</section>

	<section class="sp-card sp-help__section" id="help-renewals">
		<h3 class="sp-card__title"><?php echo esc_html( $sp_sections['renewals'] ); ?></h3>
		<?php
		$sp_qa( __( 'When does my membership renew?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'My Membership shows the exact end date. We remind you %s days before it ends. With "Renew automatically" on, your card is charged on the end date and nothing changes; with it off, renew with a card or a check before the end date to keep your farm page and listings visible.', 'southpoll-membership' ), esc_html( implode( ', ', $reminder_days ) ) ) . '</p>' );
		$sp_qa( __( 'What happens if my membership lapses?', 'southpoll-membership' ), '<p>' . esc_html__( 'Your farm page leaves the directory and your live listings are hidden, but nothing is deleted. Renew at any time: the farm page returns and your listings come back with the time they had left.', 'southpoll-membership' ) . '</p>' );
		$sp_qa( __( 'How do I cancel?', 'southpoll-membership' ), '<p>' . esc_html__( 'Press "Cancel membership" on My Membership. This turns automatic renewal off; the term you already paid for runs to its end date and no further charges are made. Nothing is refunded for the remaining time.', 'southpoll-membership' ) . '</p>' );
		$sp_qa( __( 'How do I change the card on file?', 'southpoll-membership' ), '<p>' . esc_html__( '"Manage billing" on My Membership opens the secure billing portal where you can update the card, see invoices and turn automatic renewal on or off.', 'southpoll-membership' ) . '</p>' );
		?>
	</section>

	<section class="sp-card sp-help__section" id="help-favorites">
		<h3 class="sp-card__title"><?php echo esc_html( $sp_sections['favorites'] ); ?></h3>
		<?php
		$sp_qa( __( 'What are favorites?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Press the star on any farm page or listing to keep it under %s. Favorite a farm and we e-mail you when that farm publishes a new listing; favorite a listing and we tell you when its price or status changes.', 'southpoll-membership' ), $sp_link( $urls->dashboard( 'my-favorites' ), __( 'My Favorites', 'southpoll-membership' ) ) ) . '</p>' );
		$sp_qa( __( 'How do saved searches and alerts work?', 'southpoll-membership' ), '<p>' . sprintf( esc_html__( 'Set the filters you want on the %1$s (class, state, price…) and press "Save this search". Each saved search under %2$s can e-mail you when a new listing matches it, and one of them can be your default so the classifieds open filtered the way you like. Every alert e-mail has an unsubscribe link.', 'southpoll-membership' ), $sp_link( $urls->classifieds(), __( 'classifieds', 'southpoll-membership' ) ), $sp_link( $urls->dashboard( 'saved-searches' ), __( 'Saved Searches', 'southpoll-membership' ) ) ) . '</p>' );
		$sp_qa( __( 'Can I share a search or my favorites?', 'southpoll-membership' ), '<p>' . esc_html__( 'Yes. Saved searches and the favorites list both have a "Share" button that gives you a link (or sends it by e-mail or text) that opens the same view for someone else.', 'southpoll-membership' ) . '</p>' );
		?>
	</section>

	<section class="sp-card sp-help__section" id="help-managers">
		<h3 class="sp-card__title"><?php echo esc_html( $sp_sections['managers'] ); ?></h3>
		<?php
		$sp_qa( __( 'Can someone else manage my farm page and listings?', 'southpoll-membership' ), '<p>' . esc_html__( 'Yes. On My Farm, under "Users", invite an account manager by e-mail. They accept the invitation, log in with their own account and can edit the farm page and listings. Only the owner can pay for, renew or cancel the membership and remove managers.', 'southpoll-membership' ) . '</p>' );
		$sp_qa( __( 'I was invited as a manager. What do I do?', 'southpoll-membership' ), '<p>' . esc_html__( 'Open the link in the invitation e-mail, then log in or create an account with the same e-mail address the invitation was sent to. The farm appears on your dashboard right away.', 'southpoll-membership' ) . '</p>' );
		?>
	</section>

	<section class="sp-card sp-help__section" id="help-contact">
		<h3 class="sp-card__title"><?php echo esc_html( $sp_sections['contact'] ); ?></h3>
		<?php
		$sp_qa( __( 'Something is not working or looks wrong.', 'southpoll-membership' ), '<p>' . ( $feedback_enabled ? sprintf( esc_html__( 'Press the "%s" button in the bottom-right corner of any member page (or the login page). Tell us what you expected and what happened; the page you were on and your browser are sent along, and you can attach a screenshot. Every message reaches the team by e-mail.', 'southpoll-membership' ), esc_html( $feedback_label ) ) : esc_html__( 'E-mail the office using the address below and tell us what you expected, what happened and which page you were on. A screenshot helps a lot.', 'southpoll-membership' ) ) . '</p>' );
		$sp_qa( __( 'How do I reach the SPGCA office?', 'southpoll-membership' ), '<p>' . esc_html( $contact_name ) . ( $contact_email ? ' · <a href="mailto:' . esc_attr( $contact_email ) . '">' . esc_html( $contact_email ) . '</a>' : '' ) . ( $contact_phone ? ' · <a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $contact_phone ) ) . '">' . esc_html( $contact_phone ) . '</a>' : '' ) . '</p><p>' . esc_html__( 'Questions about registrations, transfers and the cattle registry itself go to the office; questions about this website can use the feedback button.', 'southpoll-membership' ) . '</p>' );
		?>
	</section>
	<?php if ( ! $sp_farm_role ) : ?>
		<p class="sp-text-small sp-text-muted"><?php esc_html_e( 'Farm-page and listing topics apply to member (farm) accounts. Buyer accounts can browse, favorite, save searches and contact sellers.', 'southpoll-membership' ); ?></p>
	<?php endif; ?>
</div>
