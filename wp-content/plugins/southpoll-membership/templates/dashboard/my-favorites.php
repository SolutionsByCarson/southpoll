<?php
/**
 * My Favorites: farms / listings, active vs inactive, live search, remove, notify toggles, share.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array  $farms      farm cards (+ notify, active, favorite_id, html)
 * @var array  $listings   listing cards (+ notify, active, favorite_id, html)
 * @var bool   $verified
 * @var array  $prefs      default_notify_favorites, notify_all
 * @var string $share_url
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_cfg = [ 'farms' => $farms, 'listings' => $listings, 'prefs' => $prefs, 'shareUrl' => $share_url, 'classifiedsUrl' => $urls->classifieds(), 'directoryUrl' => $urls->directory() ];
?>
<div class="sp-favorites" x-data="spMyFavorites(<?php echo esc_attr( (string) wp_json_encode( $sp_cfg ) ); ?>)" @sp:favorite.window="onFavorite($event.detail)">
	<?php if ( ! $verified ) : ?><div class="sp-alert sp-alert--warning"><?php esc_html_e( 'Verify your email address to add favorites.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->dashboard( 'account-details' ) ); ?>"><?php esc_html_e( 'Resend verification', 'southpoll-membership' ); ?></a></div><?php endif; ?>

	<div class="sp-card sp-card--flat sp-farm-editor__head">
		<div class="sp-tabs" style="margin:0;border:0">
			<button type="button" class="sp-tab" :class="{ 'is-active': tab === 'farms' }" @click="tab = 'farms'"><?php esc_html_e( 'Farms', 'southpoll-membership' ); ?> <span class="sp-badge" x-text="farms.length"></span></button>
			<button type="button" class="sp-tab" :class="{ 'is-active': tab === 'listings' }" @click="tab = 'listings'"><?php esc_html_e( 'Listings', 'southpoll-membership' ); ?> <span class="sp-badge" x-text="listings.length"></span></button>
		</div>
		<div class="sp-cluster">
			<button type="button" class="sp-btn sp-btn--secondary sp-btn--sm" @click="shareList()"><?php echo Icons::svg( 'share', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Share my favorites list', 'southpoll-membership' ); ?></button>
		</div>
	</div>

	<div class="sp-grid sp-grid--stats">
		<button type="button" class="sp-stat sp-stat--btn" :class="{ 'is-active': show === 'active' }" @click="show = 'active'"><span class="sp-stat__value" x-text="counts.active"></span><span class="sp-stat__label"><?php esc_html_e( 'Active', 'southpoll-membership' ); ?></span></button>
		<button type="button" class="sp-stat sp-stat--btn sp-stat--accent" :class="{ 'is-active': show === 'inactive' }" @click="show = 'inactive'"><span class="sp-stat__value" x-text="counts.inactive"></span><span class="sp-stat__label"><?php esc_html_e( 'Inactive', 'southpoll-membership' ); ?></span></button>
	</div>

	<div class="sp-card">
		<div class="sp-table-tools">
			<label class="sp-table-search"><span class="sp-sr-only"><?php esc_html_e( 'Search favorites', 'southpoll-membership' ); ?></span><input class="sp-input" type="search" x-model="query" placeholder="<?php esc_attr_e( 'Search favorites…', 'southpoll-membership' ); ?>"></label>
			<div class="sp-cluster">
				<span class="sp-text-small sp-text-muted"><?php esc_html_e( 'Email alerts:', 'southpoll-membership' ); ?></span>
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="notifyAll(true)"><?php esc_html_e( 'All on', 'southpoll-membership' ); ?></button>
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="notifyAll(false)"><?php esc_html_e( 'All off', 'southpoll-membership' ); ?></button>
			</div>
		</div>
		<p class="sp-help" x-show="tab === 'farms'"><?php esc_html_e( 'Alerts for a farm email you whenever it publishes a new listing.', 'southpoll-membership' ); ?></p>
		<p class="sp-help" x-show="tab === 'listings'"><?php esc_html_e( 'Alerts for a listing email you whenever its status changes (sold, pending, expired…).', 'southpoll-membership' ); ?></p>

		<div class="sp-fav-grid" :class="{ 'sp-fav-grid--farms': tab === 'farms' }">
			<template x-for="item in visible" :key="item.favorite_id">
				<div class="sp-fav-item">
					<div class="sp-fav-item__card" x-html="item.html"></div>
					<div class="sp-fav-item__tools">
						<label class="sp-toggle"><input type="checkbox" :checked="item.notify" @change="setNotify(item, $event.target.checked)"><span class="sp-toggle__track"></span><span class="sp-text-small"><?php esc_html_e( 'Email me', 'southpoll-membership' ); ?></span></label>
						<span class="sp-cluster" style="gap:6px">
							<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" :data-sp-share="item.url" :data-sp-share-title="item.name || item.title"><?php echo Icons::svg( 'share', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Share', 'southpoll-membership' ); ?></button>
							<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="removeFav(item)"><?php esc_html_e( 'Remove', 'southpoll-membership' ); ?></button>
						</span>
					</div>
				</div>
			</template>
		</div>
		<div class="sp-empty" x-show="!visible.length">
			<template x-if="!(tab === 'farms' ? farms : listings).length">
				<div><span x-text="tab === 'farms' ? '<?php echo esc_js( __( 'No favorite farms yet. Tap the star on any farm in the member directory.', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'No favorite listings yet. Tap the star on any classified listing.', 'southpoll-membership' ) ); ?>'"></span><br><a class="sp-btn sp-btn--primary sp-btn--sm" :href="tab === 'farms' ? directoryUrl : classifiedsUrl" x-text="tab === 'farms' ? '<?php echo esc_js( __( 'Member directory', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Browse classifieds', 'southpoll-membership' ) ); ?>'"></a></div>
			</template>
			<template x-if="(tab === 'farms' ? farms : listings).length">
				<div><span x-text="show === 'active' ? '<?php echo esc_js( __( 'No active favorites match. Check the inactive ones.', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'No inactive favorites match.', 'southpoll-membership' ) ); ?>'"></span></div>
			</template>
		</div>
	</div>

	<div class="sp-card">
		<h3 class="sp-h3"><?php esc_html_e( 'Defaults', 'southpoll-membership' ); ?></h3>
		<label class="sp-toggle"><input type="checkbox" :checked="prefs.default_notify_favorites" @change="setPref('default_notify_favorites', $event.target.checked)"><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Always subscribe me to alerts when I add a favorite', 'southpoll-membership' ); ?></span></label>
		<p class="sp-help" style="margin-top:8px" x-show="!prefs.notify_all"><?php esc_html_e( 'Optional emails are switched off for your account, so no alerts will be sent.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->dashboard( 'account-details' ) ); ?>"><?php esc_html_e( 'Turn them on in Account Details', 'southpoll-membership' ); ?></a></p>
	</div>
</div>
