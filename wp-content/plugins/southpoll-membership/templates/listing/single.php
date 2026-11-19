<?php
/**
 * Single classified listing.
 *
 * @var \SouthPoll\Membership\Listings\Listing $listing
 * @var array  $card
 * @var array  $gallery
 * @var array  $groups        [{title, rows:[{label,value}]}]
 * @var object|null $farm
 * @var array|null $farm_card
 * @var array  $farm_meta
 * @var array  $farm_hours
 * @var array  $social
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var bool   $preview
 * @var bool   $is_mine
 * @var bool   $see_contact
 * @var array  $contact
 * @var bool   $form_on
 * @var array  $form
 * @var string $directions
 * @var array|null $map
 * @var array  $share
 * @var array  $related
 * @var string $login_url
 * @var string $register_url
 * @var bool   $count_view
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_cfg = [
	'listingId' => $listing->id,
	'countView' => $count_view,
	'map'       => $map,
	'name'      => $farm ? (string) $farm->name : '',
	'recaptcha' => (bool) $form['recaptcha'],
	'action'    => (string) $form['action'],
	'maxChars'  => (int) $form['max_chars'],
	'user'      => $form['user'] ? [ 'name' => trim( $form['user']->first_name . ' ' . $form['user']->last_name ) ?: $form['user']->display_name, 'email' => $form['user']->user_email ] : null,
	'title'     => $listing->title,
];
?>
<div class="sp-listing" x-data="spListingPage(<?php echo esc_attr( (string) wp_json_encode( $sp_cfg ) ); ?>)">

	<?php if ( $preview ) : ?>
		<div class="sp-alert sp-alert--warning"><strong><?php esc_html_e( 'Only you can see this listing right now.', 'southpoll-membership' ); ?></strong> <?php echo esc_html( 'expired' === $card['status'] ? __( 'It has expired — renew it from My Listings to put it back in the classifieds.', 'southpoll-membership' ) : __( 'It is not showing in the classifieds (inactive, sold more than two weeks ago, or the farm membership is not active).', 'southpoll-membership' ) ); ?> <a href="<?php echo esc_url( $card['edit_url'] ); ?>"><?php esc_html_e( 'Edit listing', 'southpoll-membership' ); ?></a></div>
	<?php elseif ( $is_mine ) : ?>
		<div class="sp-alert sp-alert--info"><?php esc_html_e( 'This is how buyers see your listing.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $card['edit_url'] ); ?>"><?php esc_html_e( 'Edit listing', 'southpoll-membership' ); ?></a></div>
	<?php endif; ?>

	<nav class="sp-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'southpoll-membership' ); ?>">
		<a href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Classifieds', 'southpoll-membership' ); ?></a>
		<?php foreach ( array_slice( $card['categories'], 0, 1 ) as $c ) : ?> &rsaquo; <a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a><?php endforeach; ?>
	</nav>

	<header class="sp-listing__header">
		<div class="sp-listing__heading">
			<h1 class="sp-page__title"><?php echo esc_html( $listing->title ); ?></h1>
			<div class="sp-listing__meta">
				<?php if ( 'available' !== $card['status'] ) : ?><span class="sp-badge <?php echo esc_attr( $card['status_class'] ); ?>"><?php echo esc_html( $card['status_label'] ); ?></span><?php endif; ?>
				<?php if ( $card['is_new'] ) : ?><span class="sp-badge sp-badge--new"><?php esc_html_e( 'New listing', 'southpoll-membership' ); ?></span><?php endif; ?>
				<?php foreach ( $card['categories'] as $c ) : ?><a class="sp-pill sp-pill--link" href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a><?php endforeach; ?>
				<span class="sp-text-muted sp-text-small"><?php echo esc_html( $card['published_ago'] ); ?><?php if ( $card['published_date'] ) : ?> (<?php echo esc_html( $card['published_date'] ); ?>)<?php endif; ?> &middot; <?php echo esc_html( sprintf( /* translators: %d: views */ _n( '%d view', '%d views', (int) $card['view_count'], 'southpoll-membership' ), (int) $card['view_count'] ) ); ?></span>
			</div>
		</div>
		<div class="sp-listing__price-box">
			<div class="sp-listing__price"><?php echo esc_html( $card['price'] ); ?></div>
			<div class="sp-cluster" style="gap:8px">
				<button type="button" class="sp-btn sp-btn--outline sp-btn--sm sp-fav sp-fav--btn<?php echo $card['favorited'] ? ' is-active' : ''; ?>" data-sp-favorite="listing" data-id="<?php echo esc_attr( (string) $listing->id ); ?>" aria-pressed="<?php echo $card['favorited'] ? 'true' : 'false'; ?>"><?php echo Icons::svg( 'star', 16, 'sp-fav__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span data-fav-label><?php echo esc_html( $card['favorited'] ? __( 'Favorited', 'southpoll-membership' ) : __( 'Favorite', 'southpoll-membership' ) ); ?></span></button>
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" data-sp-share="<?php echo esc_url( $share['url'] ); ?>" data-sp-share-title="<?php echo esc_attr( $share['title'] ); ?>" data-sp-share-text="<?php echo esc_attr( $share['text'] ); ?>"><?php echo Icons::svg( 'share', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Share', 'southpoll-membership' ); ?></button>
			</div>
		</div>
	</header>

	<div class="sp-farm__layout">
		<main class="sp-farm__main">
			<?php if ( ! empty( $gallery ) ) : ?>
				<section class="sp-farm__section sp-gallery" x-ref="gallery">
					<?php $sp_first = $gallery[0]; ?>
					<div class="sp-gallery__featured">
						<button type="button" class="sp-gallery__stage" data-gallery-featured data-index="0" aria-label="<?php esc_attr_e( 'Open gallery', 'southpoll-membership' ); ?>">
							<?php if ( 'video' === $sp_first['type'] ) : ?>
								<div class="sp-gallery__video-cover"<?php echo $sp_first['thumb'] ? ' style="background-image:url(' . esc_url( $sp_first['thumb'] ) . ')"' : ''; ?>><?php echo Icons::svg( 'play', 56 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<?php else : ?>
								<img src="<?php echo esc_url( $sp_first['full'] ); ?>" alt="<?php echo esc_attr( $sp_first['caption'] ?: $listing->title ); ?>">
							<?php endif; ?>
							<?php if ( $card['is_new'] ) : ?><span class="sp-badge sp-badge--new sp-badge--float"><?php esc_html_e( 'New listing', 'southpoll-membership' ); ?></span><?php endif; ?>
						</button>
						<div class="sp-gallery__caption" data-gallery-caption><?php echo esc_html( $sp_first['caption'] ); ?></div>
					</div>
					<?php if ( count( $gallery ) > 1 ) : ?>
						<div class="sp-gallery__thumbs" role="list">
							<?php foreach ( $gallery as $i => $m ) : ?>
								<button type="button" role="listitem" class="sp-gallery__thumb<?php echo 0 === $i ? ' is-active' : ''; ?>" data-gallery-item data-index="<?php echo esc_attr( (string) $i ); ?>" data-type="<?php echo esc_attr( $m['type'] ); ?>" data-full="<?php echo esc_url( $m['full'] ); ?>" data-embed="<?php echo esc_url( $m['embed'] ?? '' ); ?>" data-thumb="<?php echo esc_url( $m['thumb'] ); ?>" data-caption="<?php echo esc_attr( $m['caption'] ); ?>" aria-label="<?php echo esc_attr( $m['caption'] ?: sprintf( /* translators: %d: index */ __( 'Gallery item %d', 'southpoll-membership' ), $i + 1 ) ); ?>">
									<?php if ( 'video' === $m['type'] ) : ?><span class="sp-gallery__thumb-video"<?php echo $m['thumb'] ? ' style="background-image:url(' . esc_url( $m['thumb'] ) . ')"' : ''; ?>><?php echo Icons::svg( 'play', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php else : ?><img src="<?php echo esc_url( $m['thumb'] ); ?>" alt="" loading="lazy"><?php endif; ?>
								</button>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<button type="button" class="sp-sr-only" data-gallery-item data-index="0" data-type="<?php echo esc_attr( $sp_first['type'] ); ?>" data-full="<?php echo esc_url( $sp_first['full'] ); ?>" data-embed="<?php echo esc_url( $sp_first['embed'] ?? '' ); ?>" data-caption="<?php echo esc_attr( $sp_first['caption'] ); ?>" tabindex="-1" aria-hidden="true"></button>
					<?php endif; ?>
				</section>
			<?php elseif ( $card['image'] ) : ?>
				<section class="sp-farm__section"><img class="sp-listing__fallback" src="<?php echo esc_url( $card['image_full'] ?: $card['image'] ); ?>" alt=""></section>
			<?php endif; ?>

			<?php foreach ( $groups as $g ) : ?>
				<section class="sp-farm__section">
					<h2 class="sp-h3"><?php echo esc_html( $g['title'] ); ?></h2>
					<table class="sp-meta-table"><tbody><?php foreach ( $g['rows'] as $row ) : ?><tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo nl2br( esc_html( $row['value'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr><?php endforeach; ?></tbody></table>
				</section>
			<?php endforeach; ?>

			<?php if ( '' !== trim( wp_strip_all_tags( $listing->content ) ) ) : ?>
				<section class="sp-farm__section">
					<h2 class="sp-h3"><?php esc_html_e( 'Details', 'southpoll-membership' ); ?></h2>
					<div class="sp-prose"><?php echo wp_kses_post( wpautop( $listing->content ) ); ?></div>
				</section>
			<?php elseif ( '' !== $listing->excerpt ) : ?>
				<section class="sp-farm__section"><div class="sp-prose"><p><?php echo esc_html( $listing->excerpt ); ?></p></div></section>
			<?php endif; ?>

			<?php if ( $farm && $farm_card ) : ?>
				<section class="sp-farm__section sp-listing__farm">
					<h2 class="sp-h3"><?php esc_html_e( 'About the farm', 'southpoll-membership' ); ?></h2>
					<div class="sp-listing__farm-card">
						<a class="sp-listing__farm-logo" href="<?php echo esc_url( $farm_card['url'] ); ?>"><?php if ( $farm_card['logo'] ) : ?><img src="<?php echo esc_url( $farm_card['logo'] ); ?>" alt=""><?php else : ?><span><?php echo esc_html( $farm_card['initials'] ); ?></span><?php endif; ?></a>
						<div>
							<h3 class="sp-h3" style="margin:0 0 4px"><a href="<?php echo esc_url( $farm_card['url'] ); ?>"><?php echo esc_html( $farm_card['name'] ); ?></a></h3>
							<div class="sp-text-muted sp-text-small"><?php if ( $farm_card['owner_name'] ) : ?><?php echo esc_html( $farm_card['owner_name'] ); ?> &middot; <?php endif; ?><?php echo esc_html( $farm_card['location'] ); ?> &middot; <span class="sp-light sp-light--<?php echo esc_attr( $farm_card['light'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d active listing', '%d active listings', (int) $farm_card['active_listings'], 'southpoll-membership' ), (int) $farm_card['active_listings'] ) ); ?></div>
							<?php if ( $farm->slogan ) : ?><p style="margin:6px 0 0;font-style:italic"><?php echo esc_html( (string) $farm->slogan ); ?></p><?php endif; ?>
							<?php if ( ! empty( $farm_card['offerings'] ) ) : ?><div style="margin-top:8px"><?php foreach ( $farm_card['offerings'] as $o ) : ?><span class="sp-pill"><?php echo esc_html( $o['name'] ); ?></span><?php endforeach; ?></div><?php endif; ?>
						</div>
					</div>
					<?php if ( ! empty( $farm_meta ) ) : ?>
						<table class="sp-meta-table" style="margin-top:14px"><tbody><?php foreach ( $farm_meta as $row ) : ?><tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo nl2br( esc_html( $row['value'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr><?php endforeach; ?></tbody></table>
					<?php endif; ?>
					<p style="margin-top:12px"><a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $farm_card['url'] ); ?>"><?php esc_html_e( 'View farm page', 'southpoll-membership' ); ?></a></p>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $related ) ) : ?>
				<section class="sp-farm__section">
					<h2 class="sp-h3"><?php echo esc_html( sprintf( /* translators: %s: farm */ __( 'More from %s', 'southpoll-membership' ), $farm ? (string) $farm->name : '' ) ); ?></h2>
					<div class="sp-listing-list sp-listing-list--compact"><?php foreach ( $related as $r ) : ?><?php echo $r['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endforeach; ?></div>
				</section>
			<?php endif; ?>
		</main>

		<aside class="sp-farm__side">
			<div class="sp-card sp-farm__contact">
				<h2 class="sp-h3"><?php esc_html_e( 'Contact the seller', 'southpoll-membership' ); ?></h2>
				<?php if ( $see_contact ) : ?>
					<ul class="sp-contact-list">
						<?php if ( ! empty( $contact['owner_name'] ) ) : ?><li><?php echo Icons::svg( 'user', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $contact['owner_name'] ); ?></span></li><?php endif; ?>
						<?php if ( ! empty( $contact['phone'] ) ) : ?><li><?php echo Icons::svg( 'phone', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $contact['phone'] ) ); ?>"><?php echo esc_html( $contact['phone'] ); ?></a></li><?php endif; ?>
						<?php if ( ! empty( $contact['email'] ) ) : ?><li><?php echo Icons::svg( 'mail', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="mailto:<?php echo esc_attr( $contact['email'] ); ?>?subject=<?php echo rawurlencode( 'SouthPoll.com: ' . $listing->title ); ?>"><?php echo esc_html( $contact['email'] ); ?></a></li><?php endif; ?>
						<?php if ( ! empty( $contact['website'] ) ) : ?><li><?php echo Icons::svg( 'globe', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="<?php echo esc_url( $contact['website'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', $contact['website'] ) ); ?></a></li><?php endif; ?>
						<?php if ( ! empty( $contact['address'] ) ) : ?><li><?php echo Icons::svg( 'pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $contact['address'] ); ?></span></li><?php endif; ?>
					</ul>
				<?php else : ?>
					<?php if ( ! empty( $contact['address'] ) ) : ?><p class="sp-text-muted"><?php echo Icons::svg( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $contact['address'] ); ?></p><?php endif; ?>
					<?php if ( $context->is_logged_in() ) : ?>
						<div class="sp-alert sp-alert--warning"><?php esc_html_e( 'Verify your email address to see the seller’s phone number and email.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->dashboard( 'account-details' ) ); ?>"><?php esc_html_e( 'Resend verification', 'southpoll-membership' ); ?></a></div>
					<?php else : ?>
						<p class="sp-text-small"><?php echo Icons::svg( 'lock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Seller phone and email are shown to logged-in SouthPoll.com accounts. A free account takes a minute.', 'southpoll-membership' ); ?></p>
						<div class="sp-cluster"><a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'southpoll-membership' ); ?></a><a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Create account', 'southpoll-membership' ); ?></a></div>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $social ) ) : ?><div class="sp-social"><?php foreach ( $social as $s ) : ?><a class="sp-social__link" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener nofollow" aria-label="<?php echo esc_attr( $s['label'] ); ?>" title="<?php echo esc_attr( $s['label'] ); ?>"><?php echo Icons::svg( $s['key'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a><?php endforeach; ?></div><?php endif; ?>
				<?php if ( $directions ) : ?><a class="sp-btn sp-btn--secondary sp-btn--block" style="margin-top:14px" href="<?php echo esc_url( $directions ); ?>" target="_blank" rel="noopener"><?php echo Icons::svg( 'directions', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Get directions', 'southpoll-membership' ); ?></a><?php endif; ?>
			</div>

			<?php if ( $form_on ) : ?>
				<div class="sp-card sp-farm__inquiry" id="contact">
					<h2 class="sp-h3"><?php esc_html_e( 'Ask about this listing', 'southpoll-membership' ); ?></h2>
					<template x-if="sent"><div class="sp-alert sp-alert--success" role="status"><?php esc_html_e( 'Your message is on its way. The seller will reply to your email address.', 'southpoll-membership' ); ?></div></template>
					<form x-show="!sent" @submit.prevent="send()" novalidate>
						<div class="sp-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;"><label>Website <input type="text" name="<?php echo esc_attr( $form['honeypot'] ); ?>" x-model="hp" tabindex="-1" autocomplete="off"></label></div>
						<div class="sp-field" :class="{ 'sp-field--error': errors.name }"><label class="sp-label" for="inq-name"><?php esc_html_e( 'Your name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="inq-name" class="sp-input" x-model="fields.name" required autocomplete="name"><div class="sp-error" x-show="errors.name" x-text="errors.name"></div></div>
						<div class="sp-field" :class="{ 'sp-field--error': errors.email }"><label class="sp-label" for="inq-email"><?php esc_html_e( 'Your email', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="inq-email" class="sp-input" type="email" x-model="fields.email" required autocomplete="email"><div class="sp-error" x-show="errors.email" x-text="errors.email"></div></div>
						<div class="sp-field"><label class="sp-label" for="inq-phone"><?php esc_html_e( 'Phone (optional)', 'southpoll-membership' ); ?></label><input id="inq-phone" class="sp-input" type="tel" x-model="fields.phone" autocomplete="tel"></div>
						<div class="sp-field" :class="{ 'sp-field--error': errors.message }"><label class="sp-label" for="inq-msg"><?php esc_html_e( 'Message', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><textarea id="inq-msg" class="sp-textarea" x-model="fields.message" rows="5" required :maxlength="maxChars"></textarea><div class="sp-help" x-text="fields.message.length + ' / ' + maxChars"></div><div class="sp-error" x-show="errors.message" x-text="errors.message"></div></div>
						<button type="submit" class="sp-btn sp-btn--primary sp-btn--block" :class="{ 'is-loading': busy }" :disabled="busy"><?php esc_html_e( 'Send message', 'southpoll-membership' ); ?></button>
						<?php if ( $form['recaptcha'] ) : ?><p class="sp-help" style="margin-top:10px"><?php echo wp_kses_post( __( 'Protected by reCAPTCHA. Google <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Privacy</a> &amp; <a href="https://policies.google.com/terms" target="_blank" rel="noopener">Terms</a> apply.', 'southpoll-membership' ) ); ?></p><?php endif; ?>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( $map ) : ?>
				<div class="sp-card sp-farm__map-card"><div class="sp-map sp-farm__map" x-ref="map" role="region" aria-label="<?php esc_attr_e( 'Farm location map', 'southpoll-membership' ); ?>"></div><?php if ( ! $map['exact'] ) : ?><p class="sp-help" style="margin-top:8px"><?php esc_html_e( 'Approximate location. Log in for the exact pin.', 'southpoll-membership' ); ?></p><?php endif; ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $farm_hours ) ) : ?>
				<div class="sp-card"><h2 class="sp-h3"><?php echo Icons::svg( 'clock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Visiting hours', 'southpoll-membership' ); ?></h2><table class="sp-hours-table"><tbody><?php foreach ( $farm_hours as $r ) : ?><tr><th scope="row"><?php echo esc_html( $r['days'] ); ?></th><td><?php echo esc_html( $r['hours'] ); ?></td></tr><?php endforeach; ?></tbody></table></div>
			<?php endif; ?>

			<p class="sp-text-small"><a href="<?php echo esc_url( $urls->classifieds() ); ?>">&larr; <?php esc_html_e( 'Back to the classifieds', 'southpoll-membership' ); ?></a></p>
		</aside>
	</div>
</div>
