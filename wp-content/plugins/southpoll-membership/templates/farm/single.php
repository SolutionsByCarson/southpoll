<?php
/**
 * Public farm page.
 *
 * @var object $farm
 * @var array  $card
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var string $state
 * @var bool   $is_public
 * @var bool   $preview
 * @var bool   $is_mine
 * @var array  $offerings
 * @var array  $meta_rows
 * @var array  $media
 * @var array  $hours_rows
 * @var array  $social
 * @var bool   $see_contact
 * @var array  $contact
 * @var bool   $form_on
 * @var array  $form
 * @var string $directions
 * @var array|null $map
 * @var array  $share
 * @var string $listings_html
 * @var string $login_url
 * @var string $register_url
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_cfg = [
	'farmId'    => (int) $farm->id,
	'map'       => $map,
	'name'      => (string) $farm->name,
	'light'     => $card['light'],
	'recaptcha' => (bool) $form['recaptcha'],
	'action'    => (string) $form['action'],
	'maxChars'  => (int) $form['max_chars'],
	'user'      => $form['user'] ? [ 'name' => trim( $form['user']->first_name . ' ' . $form['user']->last_name ) ?: $form['user']->display_name, 'email' => $form['user']->user_email ] : null,
];
$sp_light_label = $card['active_listings'] > 0
	? sprintf( /* translators: %d: count */ _n( '%d active listing', '%d active listings', (int) $card['active_listings'], 'southpoll-membership' ), (int) $card['active_listings'] )
	: __( 'No active listings', 'southpoll-membership' );
?>
<div class="sp-farm" x-data="spFarmPage(<?php echo esc_attr( (string) wp_json_encode( $sp_cfg ) ); ?>)">

	<?php if ( $preview ) : ?>
		<div class="sp-alert sp-alert--warning">
			<strong><?php esc_html_e( 'Only you can see this page right now.', 'southpoll-membership' ); ?></strong>
			<?php echo esc_html( 'pending' === $state ? __( 'It will be listed in the directory as soon as the office confirms your payment.', 'southpoll-membership' ) : __( 'It is hidden from the directory until the farm membership is active.', 'southpoll-membership' ) ); ?>
			<?php if ( $is_mine ) : ?> <a href="<?php echo esc_url( $urls->dashboard( 'manage-membership' ) ); ?>"><?php esc_html_e( 'Manage membership', 'southpoll-membership' ); ?></a><?php endif; ?>
		</div>
	<?php elseif ( $is_mine ) : ?>
		<div class="sp-alert sp-alert--info"><?php esc_html_e( 'This is how visitors see your farm page.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->my_farm() ); ?>"><?php esc_html_e( 'Edit it on My Farm', 'southpoll-membership' ); ?></a></div>
	<?php endif; ?>

	<div class="sp-farm__banner<?php echo $card['banner'] ? '' : ' sp-farm__banner--empty'; ?>"<?php echo $card['banner'] ? ' style="background-image:url(' . esc_url( $card['banner'] ) . ')"' : ''; ?> role="img" aria-label="<?php echo esc_attr( $card['banner'] ? sprintf( /* translators: %s: farm */ __( '%s banner', 'southpoll-membership' ), $farm->name ) : '' ); ?>"></div>

	<header class="sp-farm__header">
		<div class="sp-farm__logo">
			<?php if ( $card['logo'] ) : ?>
				<img src="<?php echo esc_url( $card['logo'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: farm */ __( '%s logo', 'southpoll-membership' ), $farm->name ) ); ?>" width="400" height="300">
			<?php else : ?>
				<span class="sp-farm__initials" aria-hidden="true"><?php echo esc_html( $card['initials'] ); ?></span>
			<?php endif; ?>
			<?php if ( $card['new_listing'] ) : ?><span class="sp-badge sp-badge--new sp-badge--float"><?php esc_html_e( 'New listing', 'southpoll-membership' ); ?></span><?php endif; ?>
		</div>
		<div class="sp-farm__title">
			<p class="sp-eyebrow"><?php esc_html_e( 'SPGCA member farm', 'southpoll-membership' ); ?></p>
			<h1 class="sp-page__title"><?php echo esc_html( (string) $farm->name ); ?></h1>
			<?php if ( $farm->slogan ) : ?><p class="sp-farm__slogan"><?php echo esc_html( (string) $farm->slogan ); ?></p><?php endif; ?>
			<div class="sp-farm__facts">
				<span><span class="sp-light sp-light--<?php echo esc_attr( $card['light'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $sp_light_label ); ?></span>
				<?php if ( $card['location'] ) : ?><span><?php echo Icons::svg( 'pin', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $card['location'] ); ?><?php echo $card['region'] ? ' · ' . esc_html( $card['region'] ) : ''; ?></span><?php endif; ?>
				<?php if ( $farm->owner_name ) : ?><span><?php echo Icons::svg( 'user', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( (string) $farm->owner_name ); ?></span><?php endif; ?>
			</div>
		</div>
		<div class="sp-farm__actions">
			<a class="sp-btn sp-btn--ghost sp-btn--sm sp-farm__directory-link" href="<?php echo esc_url( $urls->directory() ); ?>"><?php echo Icons::svg( 'left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Member directory', 'southpoll-membership' ); ?></a>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm sp-fav sp-fav--btn<?php echo $card['favorited'] ? ' is-active' : ''; ?>" data-sp-favorite="farm" data-id="<?php echo esc_attr( (string) $farm->id ); ?>" aria-pressed="<?php echo $card['favorited'] ? 'true' : 'false'; ?>"><?php echo Icons::svg( 'star', 16, 'sp-fav__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span data-fav-label><?php echo esc_html( $card['favorited'] ? __( 'Favorited', 'southpoll-membership' ) : __( 'Favorite', 'southpoll-membership' ) ); ?></span></button>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" data-sp-share="<?php echo esc_url( $share['url'] ); ?>" data-sp-share-title="<?php echo esc_attr( $share['title'] ); ?>" data-sp-share-text="<?php echo esc_attr( $share['text'] ); ?>"><?php echo Icons::svg( 'share', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Share', 'southpoll-membership' ); ?></button>
		</div>
	</header>

	<div class="sp-farm__layout">
		<main class="sp-farm__main">
			<?php if ( ! empty( $offerings ) ) : ?>
				<section class="sp-farm__section">
					<h2 class="sp-h3"><?php esc_html_e( 'Offerings', 'southpoll-membership' ); ?></h2>
					<div><?php foreach ( $offerings as $o ) : ?><a class="sp-pill sp-pill--link" href="<?php echo esc_url( $urls->directory( [ 'offerings' => $o->slug ] ) ); ?>"><?php echo esc_html( (string) $o->name ); ?></a><?php endforeach; ?></div>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $media ) ) : ?>
				<section class="sp-farm__section sp-gallery" x-ref="gallery">
					<h2 class="sp-h3"><?php esc_html_e( 'Gallery', 'southpoll-membership' ); ?></h2>
					<?php $sp_first = $media[0]; ?>
					<div class="sp-gallery__featured">
						<button type="button" class="sp-gallery__stage" data-gallery-featured data-index="0" aria-label="<?php esc_attr_e( 'Open gallery', 'southpoll-membership' ); ?>">
							<?php if ( 'video' === $sp_first['type'] ) : ?>
								<div class="sp-gallery__video-cover"<?php echo $sp_first['thumb'] ? ' style="background-image:url(' . esc_url( $sp_first['thumb'] ) . ')"' : ''; ?>><?php echo Icons::svg( 'play', 56 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<?php else : ?>
								<img src="<?php echo esc_url( $sp_first['full'] ); ?>" alt="<?php echo esc_attr( $sp_first['caption'] ?: $farm->name ); ?>">
							<?php endif; ?>
						</button>
						<div class="sp-gallery__caption" data-gallery-caption><?php echo esc_html( $sp_first['caption'] ); ?></div>
					</div>
					<?php if ( count( $media ) > 1 ) : ?>
						<div class="sp-gallery__thumbs" role="list">
							<?php foreach ( $media as $i => $m ) : ?>
								<button type="button" role="listitem" class="sp-gallery__thumb<?php echo 0 === $i ? ' is-active' : ''; ?>" data-gallery-item data-index="<?php echo esc_attr( (string) $i ); ?>" data-type="<?php echo esc_attr( $m['type'] ); ?>" data-full="<?php echo esc_url( $m['full'] ); ?>" data-embed="<?php echo esc_url( $m['embed'] ?? '' ); ?>" data-thumb="<?php echo esc_url( $m['thumb'] ); ?>" data-caption="<?php echo esc_attr( $m['caption'] ); ?>" aria-label="<?php echo esc_attr( $m['caption'] ?: sprintf( /* translators: %d: index */ __( 'Gallery item %d', 'southpoll-membership' ), $i + 1 ) ); ?>">
									<?php if ( 'video' === $m['type'] ) : ?>
										<span class="sp-gallery__thumb-video"<?php echo $m['thumb'] ? ' style="background-image:url(' . esc_url( $m['thumb'] ) . ')"' : ''; ?>><?php echo Icons::svg( 'play', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<?php else : ?>
										<img src="<?php echo esc_url( $m['thumb'] ); ?>" alt="" loading="lazy">
									<?php endif; ?>
								</button>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<button type="button" class="sp-sr-only" data-gallery-item data-index="0" data-type="<?php echo esc_attr( $sp_first['type'] ); ?>" data-full="<?php echo esc_url( $sp_first['full'] ); ?>" data-embed="<?php echo esc_url( $sp_first['embed'] ?? '' ); ?>" data-caption="<?php echo esc_attr( $sp_first['caption'] ); ?>" tabindex="-1" aria-hidden="true"></button>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( '' !== trim( (string) $farm->about ) ) : ?>
				<section class="sp-farm__section sp-farm__about">
					<h2 class="sp-h3"><?php esc_html_e( 'About us', 'southpoll-membership' ); ?></h2>
					<div class="sp-prose"><?php echo wp_kses_post( wpautop( (string) $farm->about ) ); ?></div>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $meta_rows ) ) : ?>
				<section class="sp-farm__section">
					<h2 class="sp-h3"><?php esc_html_e( 'Farm details', 'southpoll-membership' ); ?></h2>
					<table class="sp-meta-table">
						<tbody>
						<?php foreach ( $meta_rows as $row ) : ?>
							<tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo nl2br( esc_html( $row['value'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</section>
			<?php endif; ?>

			<?php if ( '' !== $listings_html ) : ?>
				<section class="sp-farm__section" id="listings">
					<h2 class="sp-h3"><?php esc_html_e( 'Active listings', 'southpoll-membership' ); ?></h2>
					<?php echo $listings_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
			<?php elseif ( $card['active_listings'] > 0 ) : ?>
				<section class="sp-farm__section" id="listings">
					<h2 class="sp-h3"><?php esc_html_e( 'Active listings', 'southpoll-membership' ); ?></h2>
					<p><a class="sp-btn sp-btn--outline" href="<?php echo esc_url( $urls->classifieds( [ 'farm' => $farm->id ] ) ); ?>"><?php esc_html_e( 'See this farm’s classifieds', 'southpoll-membership' ); ?></a></p>
				</section>
			<?php endif; ?>
		</main>

		<aside class="sp-farm__side">
			<div class="sp-card sp-farm__contact">
				<h2 class="sp-h3"><?php esc_html_e( 'Contact', 'southpoll-membership' ); ?></h2>
				<?php if ( $see_contact ) : ?>
					<ul class="sp-contact-list">
						<?php if ( ! empty( $contact['owner_name'] ) ) : ?><li><?php echo Icons::svg( 'user', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $contact['owner_name'] ); ?></span></li><?php endif; ?>
						<?php if ( ! empty( $contact['phone'] ) ) : ?><li><?php echo Icons::svg( 'phone', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $contact['phone'] ) ); ?>"><?php echo esc_html( $contact['phone'] ); ?></a></li><?php endif; ?>
						<?php if ( ! empty( $contact['email'] ) ) : ?><li><?php echo Icons::svg( 'mail', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="mailto:<?php echo esc_attr( $contact['email'] ); ?>"><?php echo esc_html( $contact['email'] ); ?></a></li><?php endif; ?>
						<?php if ( ! empty( $contact['website'] ) ) : ?><li><?php echo Icons::svg( 'globe', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="<?php echo esc_url( $contact['website'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', $contact['website'] ) ); ?></a></li><?php endif; ?>
						<?php if ( ! empty( $contact['address'] ) ) : ?><li><?php echo Icons::svg( 'pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $contact['address'] ); ?></span></li><?php endif; ?>
					</ul>
				<?php else : ?>
					<?php if ( ! empty( $contact['address'] ) ) : ?><p class="sp-text-muted"><?php echo Icons::svg( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $contact['address'] ); ?></p><?php endif; ?>
					<?php if ( $context->is_logged_in() ) : ?>
						<div class="sp-alert sp-alert--warning"><?php esc_html_e( 'Verify your email address to see phone numbers, email addresses and street addresses.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->dashboard( 'account-details' ) ); ?>"><?php esc_html_e( 'Resend verification', 'southpoll-membership' ); ?></a></div>
					<?php else : ?>
						<p class="sp-text-small"><?php echo Icons::svg( 'lock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Phone, email and street address are shown to logged-in SouthPoll.com accounts. A free account takes a minute.', 'southpoll-membership' ); ?></p>
						<div class="sp-cluster"><a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'southpoll-membership' ); ?></a><a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Create account', 'southpoll-membership' ); ?></a></div>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $social ) ) : ?>
					<div class="sp-social">
						<?php foreach ( $social as $s ) : ?><a class="sp-social__link" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener nofollow" aria-label="<?php echo esc_attr( $s['label'] ); ?>" title="<?php echo esc_attr( $s['label'] ); ?>"><?php echo Icons::svg( $s['key'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a><?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php if ( $directions ) : ?>
					<a class="sp-btn sp-btn--secondary sp-btn--block" style="margin-top:14px" href="<?php echo esc_url( $directions ); ?>" target="_blank" rel="noopener"><?php echo Icons::svg( 'directions', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Get directions', 'southpoll-membership' ); ?></a>
				<?php endif; ?>
			</div>

			<?php if ( $map ) : ?>
				<div class="sp-card sp-farm__map-card">
					<div class="sp-map sp-farm__map" x-ref="map" role="region" aria-label="<?php esc_attr_e( 'Farm location map', 'southpoll-membership' ); ?>"></div>
					<?php if ( ! $map['exact'] ) : ?><p class="sp-help" style="margin-top:8px"><?php esc_html_e( 'Approximate location. Log in for the exact pin.', 'southpoll-membership' ); ?></p><?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $form_on ) : ?>
				<div class="sp-card sp-farm__inquiry" id="contact">
					<h2 class="sp-h3"><?php esc_html_e( 'Send a message', 'southpoll-membership' ); ?></h2>
					<template x-if="sent"><div class="sp-alert sp-alert--success" role="status"><?php esc_html_e( 'Your message is on its way. The farm will reply to your email address.', 'southpoll-membership' ); ?></div></template>
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

			<?php if ( ! empty( $hours_rows ) ) : ?>
				<div class="sp-card">
					<h2 class="sp-h3"><?php echo Icons::svg( 'clock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Visiting hours', 'southpoll-membership' ); ?></h2>
					<table class="sp-hours-table"><tbody><?php foreach ( $hours_rows as $r ) : ?><tr><th scope="row"><?php echo esc_html( $r['days'] ); ?></th><td><?php echo esc_html( $r['hours'] ); ?></td></tr><?php endforeach; ?></tbody></table>
				</div>
			<?php endif; ?>

			<p class="sp-text-small"><a href="<?php echo esc_url( $urls->directory() ); ?>">&larr; <?php esc_html_e( 'Back to the member directory', 'southpoll-membership' ); ?></a></p>
		</aside>
	</div>
</div>
