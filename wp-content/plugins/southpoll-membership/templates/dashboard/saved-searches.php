<?php
/**
 * Saved Searches.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array  $items     SavedSearchService::serialize() rows
 * @var bool   $verified
 * @var array  $prefs     default_notify_searches, notify_all
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_cfg = [ 'items' => $items, 'prefs' => $prefs, 'classifiedsUrl' => $urls->classifieds() ];
?>
<div class="sp-saved-searches" x-data="spSavedSearches(<?php echo esc_attr( (string) wp_json_encode( $sp_cfg ) ); ?>)">
	<?php if ( ! $verified ) : ?><div class="sp-alert sp-alert--warning"><?php esc_html_e( 'Verify your email address to save searches.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->dashboard( 'account-details' ) ); ?>"><?php esc_html_e( 'Resend verification', 'southpoll-membership' ); ?></a></div><?php endif; ?>

	<div class="sp-card">
		<div class="sp-card__header">
			<h2 class="sp-card__title" style="margin:0"><?php esc_html_e( 'Saved searches', 'southpoll-membership' ); ?> <span class="sp-badge" x-text="items.length"></span></h2>
			<a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( $urls->classifieds( [ 'all' => 1 ] ) ); ?>"><?php esc_html_e( 'New search', 'southpoll-membership' ); ?></a>
		</div>
		<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Open a saved search from here or from the quick-apply menu on the classifieds page. Turn on alerts to get an email when a new listing matches.', 'southpoll-membership' ); ?></p>
		<div class="sp-table-tools">
			<label class="sp-table-search"><span class="sp-sr-only"><?php esc_html_e( 'Search', 'southpoll-membership' ); ?></span><input class="sp-input" type="search" x-model="query" placeholder="<?php esc_attr_e( 'Find a saved search…', 'southpoll-membership' ); ?>"></label>
			<div class="sp-cluster">
				<span class="sp-text-small sp-text-muted"><?php esc_html_e( 'Email alerts:', 'southpoll-membership' ); ?></span>
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="notifyAll(true)"><?php esc_html_e( 'All on', 'southpoll-membership' ); ?></button>
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="notifyAll(false)"><?php esc_html_e( 'All off', 'southpoll-membership' ); ?></button>
			</div>
		</div>
		<div class="sp-table-wrap">
			<table class="sp-table">
				<thead><tr><th><?php esc_html_e( 'Search', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'New', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Alerts', 'southpoll-membership' ); ?></th><th class="sp-table__actions"></th></tr></thead>
				<tbody>
					<template x-for="s in filtered" :key="s.id">
						<tr>
							<td>
								<a class="sp-table__title" :href="s.url" x-text="s.name"></a>
								<div class="sp-text-muted sp-text-small" x-text="s.summary"></div>
								<div class="sp-text-muted sp-text-small" x-text="'<?php echo esc_js( __( 'Saved', 'southpoll-membership' ) ); ?> ' + s.created_date"></div>
							</td>
							<td>
								<span class="sp-badge" :class="s.new_count ? 'sp-badge--new' : 'sp-badge--muted'" x-text="s.new_count + ' <?php echo esc_js( __( 'since your last visit', 'southpoll-membership' ) ); ?>'"></span>
								<div class="sp-text-muted sp-text-small" x-text="s.week_count + ' <?php echo esc_js( __( 'in the last week', 'southpoll-membership' ) ); ?>'"></div>
							</td>
							<td><label class="sp-toggle"><input type="checkbox" :checked="s.notify" @change="setNotify(s, $event.target.checked)"><span class="sp-toggle__track"></span><span class="sp-sr-only"><?php esc_html_e( 'Email alerts', 'southpoll-membership' ); ?></span></label></td>
							<td class="sp-table__actions">
								<a class="sp-btn sp-btn--outline sp-btn--sm" :href="s.url"><?php esc_html_e( 'Open', 'southpoll-membership' ); ?></a>
								<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="share(s)"><?php echo Icons::svg( 'share', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
								<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="rename(s)"><?php esc_html_e( 'Rename', 'southpoll-membership' ); ?></button>
								<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="remove(s)"><?php esc_html_e( 'Remove', 'southpoll-membership' ); ?></button>
							</td>
						</tr>
					</template>
					<tr x-show="!filtered.length"><td colspan="4" class="sp-text-muted" style="text-align:center;padding:28px"><template x-if="!items.length"><span><?php esc_html_e( 'No saved searches yet. Filter the classifieds and tap "Save this search".', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Browse our classifieds', 'southpoll-membership' ); ?></a></span></template><template x-if="items.length"><span><?php esc_html_e( 'Nothing matches.', 'southpoll-membership' ); ?></span></template></td></tr>
				</tbody>
			</table>
		</div>
	</div>

	<div class="sp-card">
		<h3 class="sp-h3"><?php esc_html_e( 'Defaults', 'southpoll-membership' ); ?></h3>
		<label class="sp-toggle"><input type="checkbox" :checked="prefs.default_notify_searches" @change="setPref('default_notify_searches', $event.target.checked)"><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Always turn on alerts for searches I save', 'southpoll-membership' ); ?></span></label>
		<p class="sp-help" style="margin-top:8px" x-show="!prefs.notify_all"><?php esc_html_e( 'Optional emails are switched off for your account, so no alerts will be sent.', 'southpoll-membership' ); ?> <a href="<?php echo esc_url( $urls->dashboard( 'account-details' ) ); ?>"><?php esc_html_e( 'Turn them on in Account Details', 'southpoll-membership' ); ?></a></p>
	</div>
</div>
