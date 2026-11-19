<?php
/**
 * Admin → SouthPoll → Farms: server-side table with bulk actions (activate/deactivate, extend membership, email) and a
 * drawer that edits every farm field, ownership/billing, and shows membership, people, listings and payments.
 * Data: /sp/v1/admin/farms.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Support\Str;

final class FarmsPage extends AdminPage {

	public const SLUG = 'southpoll-farms';

	public function render(): void {
		$this->guard();
		global $wpdb;
		$regions = $wpdb->get_col( 'SELECT DISTINCT region FROM ' . Schema::table( 'sp_farms' ) . " WHERE region <> '' ORDER BY region" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$config  = [
			'endpoint'  => 'admin/farms',
			'drawer'    => 'admin/farms',
			'bulk'      => 'admin/farms/bulk',
			'title'     => __( 'Farm', 'southpoll-membership' ),
			'filters'   => [ 'status' => '', 'membership' => '', 'state' => '', 'region' => '' ],
			'sort'      => 'name',
			'dir'       => 'asc',
			'exportUrl' => self::export_url( 'farms' ),
			'openParam' => 'farm',
		];
		$this->open( __( 'Farms', 'southpoll-membership' ), 'spAdminTable', $config, '<a class="sp-btn sp-btn--outline sp-btn--sm" :href="exportUrl()">' . esc_html__( 'Export CSV', 'southpoll-membership' ) . '</a>' );
		?>
		<div class="sp-admin__toolbar">
			<?php $this->search_box( __( 'Search farm, owner, email, city, breeder #…', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'membership', __( 'Membership', 'southpoll-membership' ), [ 'active' => __( 'Active', 'southpoll-membership' ), 'expiring' => __( 'Expiring (30 days)', 'southpoll-membership' ), 'expired' => __( 'Expired', 'southpoll-membership' ), 'pending' => __( 'Pending claim', 'southpoll-membership' ), 'cancelled' => __( 'Cancelled', 'southpoll-membership' ), 'none' => __( 'Never activated', 'southpoll-membership' ) ], __( 'Any membership', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'status', __( 'Farm status', 'southpoll-membership' ), [ 'active' => __( 'Active', 'southpoll-membership' ), 'inactive' => __( 'Inactive (hidden by staff)', 'southpoll-membership' ) ], __( 'Any status', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'state', __( 'State', 'southpoll-membership' ), Str::us_states(), __( 'Any state', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'region', __( 'Region', 'southpoll-membership' ), array_combine( $regions, $regions ), __( 'Any region', 'southpoll-membership' ) ); ?>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="isFiltered" x-cloak @click="resetFilters()"><?php esc_html_e( 'Clear', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-admin__bulk" x-show="selected.length" x-cloak>
			<span class="sp-admin__bulk-count" x-text="selected.length + ' <?php echo esc_js( __( 'selected', 'southpoll-membership' ) ); ?>'"></span>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulk('activate')"><?php esc_html_e( 'Set active', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulk('deactivate', {}, '<?php echo esc_js( __( 'Hide the selected farms and their listings from the public site?', 'southpoll-membership' ) ); ?>')"><?php esc_html_e( 'Set inactive', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulkPrompt('extend', { title: '<?php echo esc_js( __( 'Extend memberships', 'southpoll-membership' ) ); ?>', message: '<?php echo esc_js( __( 'Adds days to each selected farm’s membership end date (activating it if needed). No email is sent.', 'southpoll-membership' ) ); ?>', confirmLabel: '<?php echo esc_js( __( 'Extend', 'southpoll-membership' ) ); ?>', fields: [{ name: 'days', label: '<?php echo esc_js( __( 'Days to add', 'southpoll-membership' ) ); ?>', type: 'number', value: 365, min: 1, required: true }] })"><?php esc_html_e( 'Extend membership…', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulkPrompt('email', { title: '<?php echo esc_js( __( 'Email the selected farms', 'southpoll-membership' ) ); ?>', message: '<?php echo esc_js( __( 'Sent to each farm’s owner and managers in the branded template. Plain text; blank lines start a new paragraph.', 'southpoll-membership' ) ); ?>', confirmLabel: '<?php echo esc_js( __( 'Send', 'southpoll-membership' ) ); ?>', fields: [{ name: 'subject', label: '<?php echo esc_js( __( 'Subject', 'southpoll-membership' ) ); ?>', required: true }, { name: 'body', label: '<?php echo esc_js( __( 'Message', 'southpoll-membership' ) ); ?>', type: 'textarea', rows: 8, required: true }] })"><?php esc_html_e( 'Email…', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="clearSelection()"><?php esc_html_e( 'Clear selection', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-table-wrap"><table class="sp-table sp-admin__table">
			<thead><tr>
				<th class="sp-admin__check"><input type="checkbox" :checked="allSelected" @change="toggleAll()" aria-label="<?php esc_attr_e( 'Select all on this page', 'southpoll-membership' ); ?>"></th>
				<?php $this->th( 'name', __( 'Farm', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'owner', __( 'Owner', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'state', __( 'Location', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'membership', __( 'Membership', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'ends_at', __( 'Ends', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'listings', __( 'Listings', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'status', __( 'Status', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'created', __( 'Created', 'southpoll-membership' ) ); ?>
			</tr></thead>
			<tbody>
				<?php $this->table_states( 9 ); ?>
				<template x-for="r in rows" :key="r.id">
					<tr :class="{ 'is-selected': isSelected(r.id), 'is-open': drawer.id === r.id }">
						<td><input type="checkbox" :checked="isSelected(r.id)" @change="toggle(r.id)" :aria-label="r.name"></td>
						<td><a href="#" class="sp-admin__primary" @click.prevent="open(r.id, r.name)" x-text="r.name"></a><div class="sp-text-small sp-text-muted"><span x-text="'#' + r.id"></span><span x-show="r.breeder_number" x-text="' · ' + r.breeder_number"></span><span x-show="!r.geocoded"> · <?php esc_html_e( 'no map pin', 'southpoll-membership' ); ?></span></div></td>
						<td><span x-text="r.owner"></span><div class="sp-text-small"><a :href="'mailto:' + r.owner_email" x-text="r.owner_email"></a></div></td>
						<td><span x-text="r.location"></span><div class="sp-text-small sp-text-muted" x-text="r.region"></div></td>
						<td><span :class="badge(r.membership)" x-text="label(r.membership)"></span><span class="sp-badge sp-badge--muted" x-show="r.auto_renew" title="<?php esc_attr_e( 'Auto-renew on', 'southpoll-membership' ); ?>">↻</span></td>
						<td x-text="r.ends_at || '—'"></td>
						<td><span x-text="r.active_listings"></span><div class="sp-text-small sp-text-muted" x-text="r.last_listing_at ? 'last ' + r.last_listing_at : ''"></div></td>
						<td><span :class="badge(r.status)" x-text="label(r.status)"></span></td>
						<td x-text="r.created"></td>
					</tr>
				</template>
			</tbody>
		</table></div>
		<?php
		$this->table_foot();
		$this->close();
	}
}
