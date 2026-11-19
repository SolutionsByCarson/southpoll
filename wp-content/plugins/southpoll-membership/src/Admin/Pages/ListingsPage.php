<?php
/**
 * Admin → SouthPoll → Listings: server-side table with bulk publish/pause/expire/delete/status, per-row edit (the
 * member editor, which staff may open for any listing) and delete. Data: /sp/v1/admin/listings.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Listings\ListingTypes;

final class ListingsPage extends AdminPage {

	public const SLUG = 'southpoll-listings';

	public function __construct( private ListingTypes $types, private FarmRepository $farms ) {}

	public function render(): void {
		$this->guard();
		$farm_id = (int) self::get( 'farm_id' );
		$farm    = $farm_id ? $this->farms->find( $farm_id ) : null;
		$cats    = [];
		foreach ( $this->types->categories() as $cat ) {
			$cats[ (string) $cat->slug ] = (string) $cat->name;
		}
		$statuses = ListingTypes::status_labels();
		$config   = [
			'endpoint'       => 'admin/listings',
			'bulk'           => 'admin/listings/bulk',
			'deleteEndpoint' => 'admin/listings',
			'filters'        => [ 'visibility' => '', 'status' => '', 'category' => '', 'farm_id' => $farm ? (string) $farm_id : '' ],
			'sort'           => 'modified',
			'dir'            => 'desc',
			'exportUrl'      => self::export_url( 'listings' ),
		];
		$this->open( __( 'Listings', 'southpoll-membership' ), 'spAdminTable', $config, '<a class="sp-btn sp-btn--outline sp-btn--sm" :href="exportUrl()">' . esc_html__( 'Export CSV', 'southpoll-membership' ) . '</a>' );
		?>
		<p class="description sp-admin__hint"><?php esc_html_e( 'Members create and edit their own listings from My Listings; staff can open any listing in that editor from the Edit links below. To add listings in bulk, use Import / Export.', 'southpoll-membership' ); ?></p>
		<div class="sp-admin__toolbar">
			<?php $this->search_box( __( 'Search title, farm, owner or listing #…', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'visibility', __( 'Visibility', 'southpoll-membership' ), [ 'live' => __( 'Live', 'southpoll-membership' ), 'draft' => __( 'Draft / paused', 'southpoll-membership' ), 'expired' => __( 'Expired', 'southpoll-membership' ) ], __( 'Any visibility', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'status', __( 'Status', 'southpoll-membership' ), $statuses, __( 'Any status', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'category', __( 'Category', 'southpoll-membership' ), $cats, __( 'Any category', 'southpoll-membership' ) ); ?>
			<?php if ( $farm ) : ?>
				<span class="sp-pill sp-pill--active" x-show="filters.farm_id"><?php echo esc_html( sprintf( /* translators: %s: farm */ __( 'Farm: %s', 'southpoll-membership' ), (string) $farm->name ) ); ?> <button type="button" class="sp-pill__x" @click="filters.farm_id = ''" aria-label="<?php esc_attr_e( 'Clear farm filter', 'southpoll-membership' ); ?>">&times;</button></span>
			<?php endif; ?>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="isFiltered" x-cloak @click="resetFilters()"><?php esc_html_e( 'Clear', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-admin__bulk" x-show="selected.length" x-cloak>
			<span class="sp-admin__bulk-count" x-text="selected.length + ' <?php echo esc_js( __( 'selected', 'southpoll-membership' ) ); ?>'"></span>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulk('publish')"><?php esc_html_e( 'Publish', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulk('pause')"><?php esc_html_e( 'Pause (set inactive)', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulk('expire', {}, '<?php echo esc_js( __( 'Expire the selected listings now? Owners can renew them from My Listings.', 'southpoll-membership' ) ); ?>')"><?php esc_html_e( 'Expire', 'southpoll-membership' ); ?></button>
			<label class="sp-admin__inline"><span class="sp-sr-only"><?php esc_html_e( 'Set status', 'southpoll-membership' ); ?></span><select class="sp-select" x-ref="bulkStatus"><option value=""><?php esc_html_e( 'Set status…', 'southpoll-membership' ); ?></option><?php foreach ( $statuses as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></label>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="$refs.bulkStatus.value && bulk('status', { status: $refs.bulkStatus.value })"><?php esc_html_e( 'Apply', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--danger sp-btn--sm" :disabled="busy" @click="bulk('delete', {}, '<?php echo esc_js( __( 'Permanently delete the selected listings and their photos?', 'southpoll-membership' ) ); ?>')"><?php esc_html_e( 'Delete', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="clearSelection()"><?php esc_html_e( 'Clear selection', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-table-wrap"><table class="sp-table sp-admin__table">
			<thead><tr>
				<th class="sp-admin__check"><input type="checkbox" :checked="allSelected" @change="toggleAll()" aria-label="<?php esc_attr_e( 'Select all on this page', 'southpoll-membership' ); ?>"></th>
				<?php $this->th( 'title', __( 'Listing', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'farm', __( 'Farm', 'southpoll-membership' ) ); ?>
				<th><?php esc_html_e( 'Categories', 'southpoll-membership' ); ?></th>
				<?php $this->th( 'status', __( 'Status', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'price', __( 'Price', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'published', __( 'Published', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'expires', __( 'Expires', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'views', __( 'Views', 'southpoll-membership' ) ); ?>
				<th><span class="sp-sr-only"><?php esc_html_e( 'Actions', 'southpoll-membership' ); ?></span></th>
			</tr></thead>
			<tbody>
				<?php $this->table_states( 10 ); ?>
				<template x-for="r in rows" :key="r.id">
					<tr :class="{ 'is-selected': isSelected(r.id) }">
						<td><input type="checkbox" :checked="isSelected(r.id)" @change="toggle(r.id)" :aria-label="r.title"></td>
						<td class="sp-admin__listing"><img :src="r.image" alt="" x-show="r.image" class="sp-admin__thumb"><div><a :href="r.url" target="_blank" rel="noopener" class="sp-admin__primary" x-text="r.title"></a><div class="sp-text-small sp-text-muted" x-text="'#' + r.id + (r.renewed_count ? ' · renewed ' + r.renewed_count + '×' : '')"></div></div></td>
						<td><template x-if="r.farm"><span><a :href="r.farm.url" target="_blank" rel="noopener" x-text="r.farm.name"></a><div class="sp-text-small sp-text-muted" x-text="r.farm.location"></div></span></template></td>
						<td x-text="r.category_names"></td>
						<td><span :class="badge(r.status)" x-text="r.status_label"></span> <span :class="badge(r.visibility)" x-text="label(r.visibility)"></span></td>
						<td x-text="r.price"></td>
						<td x-text="r.published_date || '—'"></td>
						<td><span x-text="r.expires_date || '—'"></span><div class="sp-text-small sp-text-muted" x-show="r.days_left !== null && r.post_status === 'publish'" x-text="r.days_left + ' <?php echo esc_js( __( 'days left', 'southpoll-membership' ) ); ?>'"></div></td>
						<td x-text="r.view_count"></td>
						<td class="sp-admin__rowactions"><a class="sp-btn sp-btn--ghost sp-btn--sm" :href="r.edit_url"><?php esc_html_e( 'Edit', 'southpoll-membership' ); ?></a> <button type="button" class="sp-btn sp-btn--ghost sp-btn--sm sp-admin__danger" @click="remove(r.id, r.title)"><?php esc_html_e( 'Delete', 'southpoll-membership' ); ?></button></td>
					</tr>
				</template>
			</tbody>
		</table></div>
		<?php
		$this->table_foot( false );
		$this->close();
	}
}
