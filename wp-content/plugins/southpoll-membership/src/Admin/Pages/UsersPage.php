<?php
/**
 * Admin → SouthPoll → Users: server-side table (search / sort / paging over every account) with a detail drawer
 * (profile, farm role, membership, payments, activity counts, actions). Data: /sp/v1/admin/users.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Farms\FarmRepository;

final class UsersPage extends AdminPage {

	public const SLUG = 'southpoll-users';

	public function __construct( private FarmRepository $farms ) {}

	public function render(): void {
		$this->guard();
		$farm_id = (int) self::get( 'farm_id' );
		$farm    = $farm_id ? $this->farms->find( $farm_id ) : null;
		$config  = [
			'endpoint'  => 'admin/users',
			'drawer'    => 'admin/users',
			'title'     => __( 'User', 'southpoll-membership' ),
			'filters'   => [ 'role' => 'all', 'farm_id' => $farm ? (string) $farm_id : '' ],
			'sort'      => 'created',
			'dir'       => 'desc',
			'exportUrl' => self::export_url( 'users' ),
			'openParam' => 'user',
		];
		$this->open( __( 'Users', 'southpoll-membership' ), 'spAdminTable', $config, '<a class="sp-btn sp-btn--outline sp-btn--sm" :href="exportUrl()">' . esc_html__( 'Export CSV', 'southpoll-membership' ) . '</a> <a class="sp-btn sp-btn--ghost sp-btn--sm" href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . esc_html__( 'Add staff user', 'southpoll-membership' ) . '</a>' );
		?>
		<div class="sp-admin__toolbar">
			<?php $this->search_box( __( 'Search name, email, phone, farm, city…', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'role', __( 'Role', 'southpoll-membership' ), [ 'all' => __( 'All accounts', 'southpoll-membership' ), 'members' => __( 'Member profiles', 'southpoll-membership' ), 'buyer' => __( 'Buyers', 'southpoll-membership' ), 'owner' => __( 'Farm owners', 'southpoll-membership' ), 'manager' => __( 'Account managers', 'southpoll-membership' ), 'staff' => __( 'Staff', 'southpoll-membership' ), 'unverified' => __( 'Unverified email', 'southpoll-membership' ), 'suspended' => __( 'Suspended', 'southpoll-membership' ) ] ); ?>
			<?php if ( $farm ) : ?>
				<span class="sp-pill sp-pill--active" x-show="filters.farm_id"><?php echo esc_html( sprintf( /* translators: %s: farm */ __( 'Farm: %s', 'southpoll-membership' ), (string) $farm->name ) ); ?> <button type="button" class="sp-pill__x" @click="filters.farm_id = ''" aria-label="<?php esc_attr_e( 'Clear farm filter', 'southpoll-membership' ); ?>">&times;</button></span>
			<?php endif; ?>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="isFiltered" x-cloak @click="resetFilters()"><?php esc_html_e( 'Clear', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-table-wrap"><table class="sp-table sp-admin__table">
			<thead><tr>
				<?php $this->th( 'name', __( 'Name', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'email', __( 'Email', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'role', __( 'Role', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'farm', __( 'Farm', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'membership', __( 'Membership', 'southpoll-membership' ) ); ?>
				<th><?php esc_html_e( 'Status', 'southpoll-membership' ); ?></th>
				<th><?php esc_html_e( 'Location', 'southpoll-membership' ); ?></th>
				<?php $this->th( 'created', __( 'Joined', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'last_seen', __( 'Last seen', 'southpoll-membership' ) ); ?>
			</tr></thead>
			<tbody>
				<?php $this->table_states( 9 ); ?>
				<template x-for="r in rows" :key="r.id">
					<tr :class="{ 'is-open': drawer.id === r.id }">
						<td><a href="#" class="sp-admin__primary" @click.prevent="open(r.id, r.name)" x-text="r.name || r.email"></a><span class="sp-text-small sp-text-muted" x-show="!r.has_profile"> <?php esc_html_e( '(no member profile)', 'southpoll-membership' ); ?></span></td>
						<td><a :href="'mailto:' + r.email" x-text="r.email"></a><div class="sp-text-small sp-text-muted" x-text="r.phone"></div></td>
						<td><span class="sp-badge sp-badge--role" x-text="r.role_label"></span></td>
						<td><template x-if="r.farm"><a :href="r.farm.url" target="_blank" rel="noopener" x-text="r.farm.name"></a></template><span class="sp-text-muted" x-show="!r.farm">—</span></td>
						<td><template x-if="r.farm"><span><span :class="badge(r.membership)" x-text="label(r.membership)"></span><div class="sp-text-small sp-text-muted" x-text="r.ends_at ? 'ends ' + r.ends_at : ''"></div></span></template></td>
						<td><span class="sp-badge sp-badge--danger" x-show="r.suspended"><?php esc_html_e( 'Suspended', 'southpoll-membership' ); ?></span> <span class="sp-badge sp-badge--warning" x-show="r.has_profile && !r.verified"><?php esc_html_e( 'Unverified', 'southpoll-membership' ); ?></span> <span class="sp-badge sp-badge--success" x-show="r.has_profile && r.verified && !r.suspended"><?php esc_html_e( 'Active', 'southpoll-membership' ); ?></span></td>
						<td x-text="r.location"></td>
						<td x-text="r.created"></td>
						<td x-text="r.last_seen || '—'"></td>
					</tr>
				</template>
			</tbody>
		</table></div>
		<?php
		$this->table_foot();
		$this->close();
	}
}
