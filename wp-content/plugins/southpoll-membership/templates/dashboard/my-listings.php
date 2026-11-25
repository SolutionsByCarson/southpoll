<?php
/**
 * My Listings: count cards, live table, quick actions.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var object $farm
 * @var array  $membership
 * @var array  $payload   items, summary{limit,active,remaining}, can_publish, drafts, expired
 * @var array  $statuses  status => label
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_cfg = $payload + [ 'statuses' => $statuses, 'editBase' => $urls->edit_listing( 0 ) ];
?>
<?php $view->display( 'dashboard/partials/membership-banner', compact( 'membership', 'context', 'urls' ) ); ?>

<div class="sp-my-listings" x-data="spMyListings(<?php echo esc_attr( (string) wp_json_encode( $sp_cfg ) ); ?>)">
	<div class="sp-grid sp-grid--stats">
		<div class="sp-stat"><span class="sp-stat__value" x-text="summary.active"><?php echo esc_html( (string) $payload['summary']['active'] ); ?></span><span class="sp-stat__label"><?php esc_html_e( 'Active listings', 'southpoll-membership' ); ?></span></div>
		<div class="sp-stat sp-stat--accent"><span class="sp-stat__value" x-text="summary.remaining"><?php echo esc_html( (string) $payload['summary']['remaining'] ); ?></span><span class="sp-stat__label"><?php echo esc_html( sprintf( /* translators: %d: limit */ __( 'Remaining of %d', 'southpoll-membership' ), (int) $payload['summary']['limit'] ) ); ?></span></div>
		<div class="sp-stat"><span class="sp-stat__value" x-text="drafts"><?php echo esc_html( (string) $payload['drafts'] ); ?></span><span class="sp-stat__label"><?php esc_html_e( 'Drafts / paused', 'southpoll-membership' ); ?></span></div>
		<div class="sp-stat"><span class="sp-stat__value" x-text="expired"><?php echo esc_html( (string) $payload['expired'] ); ?></span><span class="sp-stat__label"><?php esc_html_e( 'Expired', 'southpoll-membership' ); ?></span></div>
	</div>

	<div class="sp-card">
		<div class="sp-card__header">
			<h2 class="sp-card__title" style="margin:0"><?php esc_html_e( 'Your listings', 'southpoll-membership' ); ?></h2>
			<form method="post" style="margin:0">
				<?php wp_nonce_field( 'sp_listing_create', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="create">
				<button class="sp-btn sp-btn--primary" type="submit"><?php echo Icons::svg( 'tag', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'New listing', 'southpoll-membership' ); ?></button>
			</form>
		</div>
		<?php if ( ! $payload['can_publish'] ) : ?>
			<div class="sp-alert sp-alert--warning"><?php esc_html_e( 'You can write drafts, but publishing needs an active farm membership.', 'southpoll-membership' ); ?> <?php if ( $context->is_owner() ) : ?><a href="<?php echo esc_url( $urls->dashboard( 'manage-membership' ) ); ?>"><?php esc_html_e( 'Manage membership', 'southpoll-membership' ); ?></a><?php endif; ?></div>
		<?php elseif ( 0 === (int) $payload['summary']['remaining'] ) : ?>
			<div class="sp-alert sp-alert--info"><?php echo esc_html( sprintf( /* translators: %d: limit */ __( 'You have reached your limit of %d active listings. Mark one sold or pause it to publish another; drafts are always allowed.', 'southpoll-membership' ), (int) $payload['summary']['limit'] ) ); ?></div>
		<?php endif; ?>

		<div class="sp-table-tools">
			<label class="sp-table-search"><span class="sp-sr-only"><?php esc_html_e( 'Search listings', 'southpoll-membership' ); ?></span><input class="sp-input" type="search" x-model="query" placeholder="<?php esc_attr_e( 'Search your listings…', 'southpoll-membership' ); ?>"></label>
			<div class="sp-cluster">
				<select class="sp-select" x-model="filter" aria-label="<?php esc_attr_e( 'Show', 'southpoll-membership' ); ?>" style="width:auto">
					<option value="all"><?php esc_html_e( 'All', 'southpoll-membership' ); ?></option>
					<option value="live"><?php esc_html_e( 'Live', 'southpoll-membership' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Drafts / paused', 'southpoll-membership' ); ?></option>
					<option value="expired"><?php esc_html_e( 'Expired', 'southpoll-membership' ); ?></option>
				</select>
				<label class="sp-table-perpage"><span class="sp-text-small sp-text-muted"><?php esc_html_e( 'per page', 'southpoll-membership' ); ?></span><select class="sp-select" x-model.number="perPage"><template x-for="o in perPageOptions" :key="o"><option :value="o === 'all' ? 9999 : o" x-text="o === 'all' ? '<?php echo esc_js( __( 'All', 'southpoll-membership' ) ); ?>' : o"></option></template></select></label>
			</div>
		</div>
		<div class="sp-table-wrap">
			<table class="sp-table sp-table--listings">
				<thead><tr>
					<th @click="setSort('title')" :class="{ 'is-sorted': sortKey === 'title' }"><?php esc_html_e( 'Listing', 'southpoll-membership' ); ?><span class="sp-table__dir" x-text="sortKey === 'title' ? (sortDir === 'asc' ? '▲' : '▼') : ''"></span></th>
					<th @click="setSort('status')" :class="{ 'is-sorted': sortKey === 'status' }"><?php esc_html_e( 'Status', 'southpoll-membership' ); ?><span class="sp-table__dir" x-text="sortKey === 'status' ? (sortDir === 'asc' ? '▲' : '▼') : ''"></span></th>
					<th @click="setSort('price_cents')" :class="{ 'is-sorted': sortKey === 'price_cents' }"><?php esc_html_e( 'Price', 'southpoll-membership' ); ?><span class="sp-table__dir" x-text="sortKey === 'price_cents' ? (sortDir === 'asc' ? '▲' : '▼') : ''"></span></th>
					<th @click="setSort('expires_at')" :class="{ 'is-sorted': sortKey === 'expires_at' }"><?php esc_html_e( 'Runs until', 'southpoll-membership' ); ?><span class="sp-table__dir" x-text="sortKey === 'expires_at' ? (sortDir === 'asc' ? '▲' : '▼') : ''"></span></th>
					<th @click="setSort('view_count')" :class="{ 'is-sorted': sortKey === 'view_count' }"><?php esc_html_e( 'Views', 'southpoll-membership' ); ?><span class="sp-table__dir" x-text="sortKey === 'view_count' ? (sortDir === 'asc' ? '▲' : '▼') : ''"></span></th>
					<th class="sp-table__actions"></th>
				</tr></thead>
				<tbody>
					<template x-for="l in paged" :key="l.id">
						<tr :class="{ 'is-pending': !l.is_public }">
							<td>
								<div class="sp-table__listing">
									<a class="sp-table__thumb" :href="l.edit_url"><template x-if="l.image"><img :src="l.image" alt=""></template></a>
									<div><a class="sp-table__title" :href="l.edit_url" x-text="(l.untitled || !l.title) ? '<?php echo esc_js( __( '(untitled draft)', 'southpoll-membership' ) ); ?>' : l.title"></a><div class="sp-text-muted sp-text-small" x-text="l.categories.map(c => c.name).join(', ')"></div></div>
								</div>
							</td>
							<td>
								<span class="sp-badge" :class="l.status_class" x-text="l.status_label"></span>
								<template x-if="l.post_status === 'publish'">
									<select class="sp-select sp-select--inline" :value="l.status" @change="setStatus(l, $event.target.value)" aria-label="<?php esc_attr_e( 'Change status', 'southpoll-membership' ); ?>">
										<template x-for="(label, key) in statuses" :key="key"><option :value="key" x-text="label" :selected="key === l.status"></option></template>
									</select>
								</template>
							</td>
							<td x-text="l.price"></td>
							<td><span x-text="l.expires_date || '—'"></span><div class="sp-text-muted sp-text-small" x-show="l.days_left !== null && l.post_status === 'publish'" x-text="l.days_left + ' <?php echo esc_js( __( 'days left', 'southpoll-membership' ) ); ?>'"></div></td>
							<td x-text="l.view_count"></td>
							<td class="sp-table__actions">
								<a class="sp-btn sp-btn--ghost sp-btn--sm" :href="l.edit_url"><?php esc_html_e( 'Edit', 'southpoll-membership' ); ?></a>
								<a class="sp-btn sp-btn--ghost sp-btn--sm" :href="l.url" target="_blank" rel="noopener" x-show="l.post_status === 'publish'"><?php esc_html_e( 'View', 'southpoll-membership' ); ?></a>
								<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="toggle(l)" x-text="l.post_status === 'publish' ? '<?php echo esc_js( __( 'Pause', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Publish', 'southpoll-membership' ) ); ?>'" x-show="l.post_status !== 'sp_expired'"></button>
								<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" @click="renew(l)" x-show="l.post_status === 'sp_expired' || (l.days_left !== null && l.days_left <= 7 && l.post_status === 'publish')"><?php esc_html_e( 'Renew', 'southpoll-membership' ); ?></button>
								<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="duplicate(l)"><?php esc_html_e( 'Copy', 'southpoll-membership' ); ?></button>
								<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="remove(l)"><?php esc_html_e( 'Delete', 'southpoll-membership' ); ?></button>
							</td>
						</tr>
					</template>
					<tr x-show="!paged.length"><td colspan="6" class="sp-text-muted" style="text-align:center;padding:28px"><?php esc_html_e( 'No listings yet. Create your first one with the button above.', 'southpoll-membership' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<div class="sp-pagination" x-show="pageCount > 1">
			<span class="sp-text-muted sp-text-small" x-text="summaryText"></span>
			<div class="sp-pagination__pages"><template x-for="p in pageNumbers" :key="p"><button type="button" class="sp-btn sp-btn--sm" :class="p === page ? 'sp-btn--primary' : 'sp-btn--ghost'" @click="goTo(p)" x-text="p"></button></template></div>
		</div>
	</div>

	<?php if ( $context->is_owner() ) : ?>
		<div class="sp-card">
			<h3 class="sp-h3"><?php esc_html_e( 'Contact form for visitors', 'southpoll-membership' ); ?></h3>
			<p class="sp-text-small"><?php echo esc_html( (int) $farm->contact_form_enabled ? __( 'Visitors who are not logged in can message you from your farm page and your listings.', 'southpoll-membership' ) : __( 'The contact form is turned off: visitors who are not logged in cannot message you from your farm page or listings.', 'southpoll-membership' ) ); ?> <a href="<?php echo esc_url( $urls->my_farm( 'contact-form' ) ); ?>"><?php esc_html_e( 'Change this on My Farm', 'southpoll-membership' ); ?></a></p>
		</div>
	<?php endif; ?>
</div>
