<?php
/**
 * Admin → SouthPoll → Memberships. Tabs:
 *   memberships — server table + drawer (extend / set end date / comp / record payment / cancel / auto-renew off / notes)
 *   payments    — server table with running total, note + refund-marker actions
 *   claims      — offline-claim verification queue + manual "record a payment" (classic forms, work without JS)
 *   tiers       — membership tiers (price, term, listing limit, default/active)
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Memberships\MembershipRepository;
use SouthPoll\Membership\Memberships\StatusEngine;
use SouthPoll\Membership\Payments\OfflineClaims;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

final class MembershipsPage extends AdminPage {

	public const SLUG   = 'southpoll-memberships';
	private const NONCE = 'sp_claims_action';
	private const TABS  = [ 'memberships', 'payments', 'claims', 'tiers' ];

	public function __construct(
		private PaymentRepository $payments,
		private OfflineClaims $claims,
		private FarmRepository $farms,
		private MembershipRepository $memberships,
		private StatusEngine $engine
	) {}

	/** Claims tab classic forms (approve / reject / record). */
	public function handle_actions(): void {
		if ( empty( $_POST['sp_claims_action'] ) || ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		$action = sanitize_key( wp_unslash( $_POST['sp_claims_action'] ) );
		$id     = isset( $_POST['payment_id'] ) ? (int) $_POST['payment_id'] : 0;
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$paid   = isset( $_POST['paid_at'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_at'] ) ) : '';
		$res    = null;
		$done   = $action;
		switch ( $action ) {
			case 'approve':
				$res  = $this->claims->approve( $id, get_current_user_id(), $paid, $note );
				$done = 'claim_approved';
				break;
			case 'reject':
				$res  = $this->claims->reject( $id, get_current_user_id(), $note );
				$done = 'claim_rejected';
				break;
			case 'record':
				$farm_id = isset( $_POST['farm_id'] ) ? (int) $_POST['farm_id'] : 0;
				$amount  = isset( $_POST['amount'] ) ? (int) round( (float) str_replace( [ '$', ',' ], '', sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) * 100 ) : 0;
				$res     = $this->claims->record( $farm_id, isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'check', $amount, $paid, get_current_user_id(), $note );
				$done    = 'payment_recorded';
				break;
		}
		$query = [ 'tab' => 'claims', 'sp_done' => $done ];
		if ( is_wp_error( $res ) ) {
			$query = [ 'tab' => 'claims', 'sp_done' => 'error', 'sp_msg' => $res->get_error_message() ];
		}
		wp_safe_redirect( self::url( $query ) );
		exit;
	}

	public function render(): void {
		$this->guard();
		$tab = self::get( 'tab' );
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'memberships';
		match ( $tab ) {
			'payments' => $this->render_payments(),
			'claims'   => $this->render_claims(),
			'tiers'    => $this->render_tiers(),
			default    => $this->render_memberships(),
		};
	}

	private function tab_nav( string $active ): void {
		$pending = count( $this->payments->pending_claims() );
		$this->tabs( [
			'memberships' => __( 'Memberships', 'southpoll-membership' ),
			'payments'    => __( 'Payments', 'southpoll-membership' ),
			'claims'      => __( 'Claims to verify', 'southpoll-membership' ) . ( $pending ? ' (' . $pending . ')' : '' ),
			'tiers'       => __( 'Tiers', 'southpoll-membership' ),
		], $active );
	}

	/* ------------------------------------------------------------ memberships */

	private function render_memberships(): void {
		global $wpdb;
		$sources = $wpdb->get_col( 'SELECT DISTINCT source FROM ' . Schema::table( 'sp_memberships' ) . " WHERE source <> '' ORDER BY source" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$config  = [
			'endpoint'  => 'admin/memberships',
			'drawer'    => 'admin/memberships',
			'title'     => __( 'Membership', 'southpoll-membership' ),
			'filters'   => [ 'status' => '', 'source' => '', 'auto_renew' => '' ],
			'sort'      => 'ends_at',
			'dir'       => 'asc',
			'exportUrl' => self::export_url( 'memberships' ),
			'openParam' => 'farm',
		];
		$this->open( __( 'Memberships', 'southpoll-membership' ), 'spAdminTable', $config, '<a class="sp-btn sp-btn--outline sp-btn--sm" :href="exportUrl()">' . esc_html__( 'Export CSV', 'southpoll-membership' ) . '</a>' );
		$this->tab_nav( 'memberships' );
		?>
		<div class="sp-admin__toolbar">
			<?php $this->search_box( __( 'Search farm, owner, email, breeder #, Stripe id, notes…', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'status', __( 'Status', 'southpoll-membership' ), [ 'active' => __( 'Active', 'southpoll-membership' ), 'expiring' => __( 'Expiring (30 days)', 'southpoll-membership' ), 'expired' => __( 'Expired', 'southpoll-membership' ), 'pending' => __( 'Pending claim', 'southpoll-membership' ), 'cancelled' => __( 'Cancelled', 'southpoll-membership' ), 'none' => __( 'Never activated', 'southpoll-membership' ) ], __( 'Any status', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'source', __( 'Source', 'southpoll-membership' ), array_combine( $sources, array_map( 'ucfirst', $sources ) ), __( 'Any source', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'auto_renew', __( 'Auto-renew', 'southpoll-membership' ), [ '1' => __( 'Auto-renew on', 'southpoll-membership' ), '0' => __( 'Auto-renew off', 'southpoll-membership' ) ], __( 'Auto-renew: any', 'southpoll-membership' ) ); ?>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="isFiltered" x-cloak @click="resetFilters()"><?php esc_html_e( 'Clear', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-table-wrap"><table class="sp-table sp-admin__table">
			<thead><tr>
				<?php $this->th( 'farm', __( 'Farm', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'owner', __( 'Owner', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'status', __( 'Status', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'starts_at', __( 'Started', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'ends_at', __( 'Ends', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'auto_renew', __( 'Auto-renew', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'source', __( 'Source', 'southpoll-membership' ) ); ?>
				<th><?php esc_html_e( 'Tier', 'southpoll-membership' ); ?></th>
				<?php $this->th( 'price', __( 'Paid', 'southpoll-membership' ) ); ?>
			</tr></thead>
			<tbody>
				<?php $this->table_states( 9 ); ?>
				<template x-for="r in rows" :key="r.id">
					<tr :class="{ 'is-open': drawer.id === r.id }">
						<td><a href="#" class="sp-admin__primary" @click.prevent="open(r.id, r.farm)" x-text="r.farm"></a><div class="sp-text-small sp-text-muted" x-text="'#' + r.id + (r.breeder_number ? ' · ' + r.breeder_number : '')"></div></td>
						<td><span x-text="r.owner"></span><div class="sp-text-small"><a :href="'mailto:' + r.owner_email" x-text="r.owner_email"></a></div></td>
						<td><span :class="badge(r.status)" x-text="label(r.status)"></span></td>
						<td x-text="r.starts_at || '—'"></td>
						<td><span x-text="r.ends_at || '—'"></span><div class="sp-text-small sp-text-muted" x-show="r.days_left !== null && r.status === 'active'" x-text="r.days_left + ' <?php echo esc_js( __( 'days left', 'southpoll-membership' ) ); ?>'"></div></td>
						<td><span class="sp-badge" :class="r.auto_renew ? 'sp-badge--success' : 'sp-badge--muted'" x-text="r.auto_renew ? '<?php echo esc_js( __( 'On', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Off', 'southpoll-membership' ) ); ?>'"></span></td>
						<td><span x-text="label(r.source)"></span><span class="sp-text-small sp-text-muted" x-show="r.stripe"> · Stripe</span></td>
						<td x-text="r.tier"></td>
						<td x-text="r.price_paid || '—'"></td>
					</tr>
				</template>
			</tbody>
		</table></div>
		<?php
		$this->table_foot();
		$this->close();
	}

	/* --------------------------------------------------------------- payments */

	private function render_payments(): void {
		$farm_id = (int) self::get( 'farm_id' );
		$farm    = $farm_id ? $this->farms->find( $farm_id ) : null;
		$config  = [
			'endpoint' => 'admin/payments',
			'filters'  => [ 'status' => '', 'method' => '', 'from' => self::get( 'from' ), 'to' => self::get( 'to' ), 'farm_id' => $farm ? (string) $farm_id : '' ],
			'sort'     => 'created',
			'dir'      => 'desc',
		];
		$this->open( __( 'Payments', 'southpoll-membership' ), 'spAdminTable', $config );
		$this->tab_nav( 'payments' );
		?>
		<div class="sp-admin__toolbar">
			<?php $this->search_box( __( 'Search farm, owner, note, Stripe id or payment #…', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'status', __( 'Status', 'southpoll-membership' ), [ 'succeeded' => __( 'Succeeded', 'southpoll-membership' ), 'pending' => __( 'Pending', 'southpoll-membership' ), 'received' => __( 'Claimed (unverified)', 'southpoll-membership' ), 'failed' => __( 'Failed', 'southpoll-membership' ), 'rejected' => __( 'Rejected', 'southpoll-membership' ), 'refunded' => __( 'Refunded', 'southpoll-membership' ) ], __( 'Any status', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'method', __( 'Method', 'southpoll-membership' ), [ 'stripe' => __( 'Card (Stripe)', 'southpoll-membership' ), 'check' => __( 'Check', 'southpoll-membership' ), 'cash' => __( 'Cash', 'southpoll-membership' ), 'comp' => __( 'Complimentary', 'southpoll-membership' ), 'other' => __( 'Other', 'southpoll-membership' ) ], __( 'Any method', 'southpoll-membership' ) ); ?>
			<label class="sp-admin__filter"><span class="sp-sr-only"><?php esc_html_e( 'From date', 'southpoll-membership' ); ?></span><input type="date" class="sp-input" x-model="filters.from" aria-label="<?php esc_attr_e( 'From date', 'southpoll-membership' ); ?>"></label>
			<label class="sp-admin__filter"><span class="sp-sr-only"><?php esc_html_e( 'To date', 'southpoll-membership' ); ?></span><input type="date" class="sp-input" x-model="filters.to" aria-label="<?php esc_attr_e( 'To date', 'southpoll-membership' ); ?>"></label>
			<?php if ( $farm ) : ?>
				<span class="sp-pill sp-pill--active" x-show="filters.farm_id"><?php echo esc_html( sprintf( /* translators: %s: farm */ __( 'Farm: %s', 'southpoll-membership' ), (string) $farm->name ) ); ?> <button type="button" class="sp-pill__x" @click="filters.farm_id = ''" aria-label="<?php esc_attr_e( 'Clear farm filter', 'southpoll-membership' ); ?>">&times;</button></span>
			<?php endif; ?>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="isFiltered" x-cloak @click="resetFilters()"><?php esc_html_e( 'Clear', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-table-wrap"><table class="sp-table sp-admin__table">
			<thead><tr>
				<?php $this->th( 'paid_at', __( 'Paid', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'farm', __( 'Farm', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'amount', __( 'Amount', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'method', __( 'Method', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'status', __( 'Status', 'southpoll-membership' ) ); ?>
				<th><?php esc_html_e( 'Term', 'southpoll-membership' ); ?></th>
				<th><?php esc_html_e( 'Notes', 'southpoll-membership' ); ?></th>
				<th><span class="sp-sr-only"><?php esc_html_e( 'Actions', 'southpoll-membership' ); ?></span></th>
			</tr></thead>
			<tbody>
				<?php $this->table_states( 8 ); ?>
				<template x-for="r in rows" :key="r.id">
					<tr>
						<td><span x-text="r.paid_at || r.created"></span><div class="sp-text-small sp-text-muted" x-text="'#' + r.id"></div></td>
						<td><a :href="r.farm_admin_url" x-text="r.farm"></a><div class="sp-text-small sp-text-muted" x-text="r.owner"></div></td>
						<td><strong x-text="r.amount"></strong><span class="sp-badge sp-badge--muted" x-show="r.recurring" title="<?php esc_attr_e( 'Recurring charge', 'southpoll-membership' ); ?>">↻</span></td>
						<td><span x-text="r.method"></span><div class="sp-text-small sp-text-muted" x-text="r.stripe_id"></div></td>
						<td><span :class="badge(r.status)" x-text="label(r.status)"></span><div class="sp-text-small" x-show="r.receipt_url"><a :href="r.receipt_url" target="_blank" rel="noopener"><?php esc_html_e( 'Receipt', 'southpoll-membership' ); ?></a></div></td>
						<td x-text="r.term || '—'"></td>
						<td class="sp-admin__notes"><span x-text="r.notes" :title="r.notes"></span></td>
						<td class="sp-admin__rowactions">
							<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="rowAction({ path: 'admin/payments/' + r.id + '/action', action: 'note', title: '<?php echo esc_js( __( 'Add a note', 'southpoll-membership' ) ); ?>', confirmLabel: '<?php echo esc_js( __( 'Add note', 'southpoll-membership' ) ); ?>', fields: [{ name: 'note', label: '<?php echo esc_js( __( 'Note', 'southpoll-membership' ) ); ?>', type: 'textarea', rows: 3, required: true }] })"><?php esc_html_e( 'Note', 'southpoll-membership' ); ?></button>
							<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="r.status === 'succeeded'" @click="rowAction({ path: 'admin/payments/' + r.id + '/action', action: 'refunded', title: '<?php echo esc_js( __( 'Mark as refunded', 'southpoll-membership' ) ); ?>', message: '<?php echo esc_js( __( 'This only records the refund here. Refund the card in the Stripe Dashboard (or return the check) separately. The membership is not changed; cancel it from the Memberships tab if needed.', 'southpoll-membership' ) ); ?>', confirmLabel: '<?php echo esc_js( __( 'Mark refunded', 'southpoll-membership' ) ); ?>', danger: true, fields: [{ name: 'note', label: '<?php echo esc_js( __( 'Reason (optional)', 'southpoll-membership' ) ); ?>' }] })"><?php esc_html_e( 'Refunded', 'southpoll-membership' ); ?></button>
						</td>
					</tr>
				</template>
			</tbody>
		</table></div>
		<?php
		$this->table_foot( false, ' <span class="sp-results-bar__sum" x-show="meta.sum" x-cloak>' . esc_html__( 'Succeeded total:', 'southpoll-membership' ) . ' <strong x-text="meta.sum"></strong></span>' );
		$this->close();
	}

	/* ----------------------------------------------------------------- claims */

	private function render_claims(): void {
		$claims = $this->payments->pending_claims();
		$counts = $this->memberships->counts_by_status();
		$this->open( __( 'Memberships', 'southpoll-membership' ) );
		$this->tab_nav( 'claims' );
		?>
		<div class="sp-grid sp-grid--stats sp-admin__stats">
			<?php foreach ( [ 'active' => __( 'Active', 'southpoll-membership' ), 'pending' => __( 'Pending', 'southpoll-membership' ), 'expired' => __( 'Expired', 'southpoll-membership' ), 'none' => __( 'Never activated', 'southpoll-membership' ) ] as $k => $label ) : ?>
				<div class="sp-stat<?php echo 'pending' === $k ? ' sp-stat--accent' : ''; ?>"><span class="sp-stat__value" data-countup="<?php echo esc_attr( (string) ( $counts[ $k ] ?? 0 ) ); ?>">0</span><span class="sp-stat__label"><?php echo esc_html( $label ); ?></span></div>
			<?php endforeach; ?>
		</div>

		<h2><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Offline payment claims awaiting verification (%d)', 'southpoll-membership' ), count( $claims ) ) ); ?></h2>
		<?php if ( empty( $claims ) ) : ?>
			<p><?php esc_html_e( 'No claims waiting. Members who pay by check or cash will appear here when they submit a claim.', 'southpoll-membership' ); ?></p>
		<?php else : ?>
			<div class="sp-table-wrap"><table class="sp-table sp-admin__table">
				<thead><tr><th><?php esc_html_e( 'Submitted', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Farm / owner', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Claimed', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Member note', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Current status', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Action', 'southpoll-membership' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $claims as $cl ) : ?>
					<?php
					$farm  = $this->farms->find( (int) $cl->farm_id );
					$owner = $farm ? get_userdata( (int) $farm->owner_user_id ) : null;
					$m     = $this->memberships->for_farm( (int) $cl->farm_id );
					?>
					<tr>
						<td><?php echo esc_html( Dates::display_datetime( (string) $cl->created_at ) ); ?></td>
						<td><strong><a href="<?php echo esc_url( self::url( [ 'farm' => (int) $cl->farm_id ] ) ); ?>"><?php echo esc_html( $farm ? (string) $farm->name : '#' . $cl->farm_id ); ?></a></strong><br><?php echo $owner ? esc_html( trim( $owner->first_name . ' ' . $owner->last_name ) . ' · ' ) . '<a href="mailto:' . esc_attr( $owner->user_email ) . '">' . esc_html( $owner->user_email ) . '</a>' : ''; ?><?php if ( $farm && $farm->breeder_number ) : ?><br><small>Breeder # <?php echo esc_html( (string) $farm->breeder_number ); ?></small><?php endif; ?></td>
						<td><?php echo esc_html( Str::money( (int) $cl->amount_cents, (string) $cl->currency ) . ' · ' . PaymentRepository::method_label( (string) $cl->method ) ); ?></td>
						<td class="sp-admin__notes"><small><?php echo esc_html( (string) $cl->notes ); ?></small></td>
						<td><span class="sp-badge sp-badge--<?php echo 'active' === $this->engine->effective_status( $m ) ? 'success' : 'warning'; ?>"><?php echo esc_html( $this->engine->effective_status( $m ) ); ?></span><?php if ( $m && $m->ends_at ) : ?><br><small><?php echo esc_html( 'ends ' . Dates::display( (string) $m->ends_at ) ); ?></small><?php endif; ?></td>
						<td>
							<form method="post" class="sp-admin__claimform">
								<?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="payment_id" value="<?php echo esc_attr( (string) $cl->id ); ?>">
								<input type="date" class="sp-input" name="paid_at" title="<?php esc_attr_e( 'Activation date (defaults to today)', 'southpoll-membership' ); ?>" aria-label="<?php esc_attr_e( 'Activation date', 'southpoll-membership' ); ?>">
								<input type="text" class="sp-input" name="note" placeholder="<?php esc_attr_e( 'Internal note / reason', 'southpoll-membership' ); ?>" aria-label="<?php esc_attr_e( 'Note', 'southpoll-membership' ); ?>">
								<div class="sp-cluster"><button class="sp-btn sp-btn--primary sp-btn--sm" name="sp_claims_action" value="approve"><?php esc_html_e( 'Approve', 'southpoll-membership' ); ?></button><button class="sp-btn sp-btn--outline sp-btn--sm" name="sp_claims_action" value="reject" onclick="return confirm('<?php echo esc_js( __( 'Reject this claim? The member will be emailed.', 'southpoll-membership' ) ); ?>')"><?php esc_html_e( 'Reject', 'southpoll-membership' ); ?></button></div>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Record an offline payment', 'southpoll-membership' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Use this when a member pays by check or cash without submitting a claim. The membership activates (or extends) immediately and the member gets the activation email and receipt. You can also do this from a farm’s membership drawer.', 'southpoll-membership' ); ?></p>
		<form method="post" class="sp-admin__recordform">
			<?php wp_nonce_field( self::NONCE ); ?>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Farm ID', 'southpoll-membership' ); ?></span><input type="number" class="sp-input" name="farm_id" required></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Method', 'southpoll-membership' ); ?></span><select class="sp-select" name="method"><option value="check"><?php esc_html_e( 'Check', 'southpoll-membership' ); ?></option><option value="cash"><?php esc_html_e( 'Cash', 'southpoll-membership' ); ?></option><option value="other"><?php esc_html_e( 'Other', 'southpoll-membership' ); ?></option><option value="comp"><?php esc_html_e( 'Complimentary', 'southpoll-membership' ); ?></option></select></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Amount', 'southpoll-membership' ); ?></span><input type="text" class="sp-input" name="amount" value="100.00"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Paid / activation date', 'southpoll-membership' ); ?></span><input type="date" class="sp-input" name="paid_at"></label>
			<label class="sp-field sp-admin__grow"><span class="sp-label"><?php esc_html_e( 'Note', 'southpoll-membership' ); ?></span><input type="text" class="sp-input" name="note"></label>
			<button class="sp-btn sp-btn--primary" name="sp_claims_action" value="record"><?php esc_html_e( 'Record & activate', 'southpoll-membership' ); ?></button>
		</form>
		<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: URL */ __( 'Farm IDs are shown under each farm name on the <a href="%s">Farms</a> screen.', 'southpoll-membership' ), esc_url( FarmsPage::url() ) ), [ 'a' => [ 'href' => [] ] ] ); ?></p>
		<?php
		$this->close();
	}

	/* ------------------------------------------------------------------ tiers */

	private function render_tiers(): void {
		$this->open( __( 'Membership tiers', 'southpoll-membership' ), 'spAdminTiers', [] );
		$this->tab_nav( 'tiers' );
		?>
		<p class="description sp-admin__hint"><?php esc_html_e( 'Members see a tier picker at checkout only when more than one tier is active. The default tier is used for offline payments, complimentary terms and imports. Changing a price creates a new Stripe price automatically the next time someone checks out; existing subscriptions keep their old price.', 'southpoll-membership' ); ?></p>
		<div class="sp-table-wrap"><table class="sp-table sp-admin__table">
			<thead><tr><th><?php esc_html_e( 'Tier', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Price', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Term', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Listing limit', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Default', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Active', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Stripe', 'southpoll-membership' ); ?></th><th><span class="sp-sr-only"><?php esc_html_e( 'Actions', 'southpoll-membership' ); ?></span></th></tr></thead>
			<tbody>
				<template x-if="loading"><tr><td colspan="8" class="sp-admin__state sp-loading"><?php esc_html_e( 'Loading…', 'southpoll-membership' ); ?></td></tr></template>
				<template x-for="t in items" :key="t.id">
					<tr :class="{ 'is-open': editing === t.id }">
						<td><strong x-text="t.name"></strong><div class="sp-text-small sp-text-muted" x-text="t.slug + (t.description ? ' · ' + t.description : '')"></div></td>
						<td x-text="'$' + t.price"></td>
						<td x-text="t.duration_days + ' <?php echo esc_js( __( 'days', 'southpoll-membership' ) ); ?>'"></td>
						<td x-text="t.listing_limit === '' ? '<?php echo esc_js( __( 'Global default', 'southpoll-membership' ) ); ?>' : t.listing_limit"></td>
						<td><span class="sp-badge sp-badge--success" x-show="t.is_default"><?php esc_html_e( 'Default', 'southpoll-membership' ); ?></span></td>
						<td><span class="sp-badge" :class="t.active ? 'sp-badge--success' : 'sp-badge--muted'" x-text="t.active ? '<?php echo esc_js( __( 'Active', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Off', 'southpoll-membership' ) ); ?>'"></span></td>
						<td><span class="sp-text-small" x-text="t.stripe_synced ? '<?php echo esc_js( __( 'Price synced', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Not yet synced', 'southpoll-membership' ) ); ?>'"></span></td>
						<td class="sp-admin__rowactions"><button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="edit(t)"><?php esc_html_e( 'Edit', 'southpoll-membership' ); ?></button></td>
					</tr>
				</template>
			</tbody>
		</table></div>
		<p><button type="button" class="sp-btn sp-btn--outline sp-btn--sm" @click="add()" x-show="!adding"><?php esc_html_e( 'Add a tier', 'southpoll-membership' ); ?></button></p>
		<form class="sp-card sp-admin__form" x-show="editing || adding" x-cloak @submit.prevent="save()">
			<h2 class="sp-card__title" x-text="adding ? '<?php echo esc_js( __( 'New tier', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Edit tier', 'southpoll-membership' ) ); ?>'"></h2>
			<div class="sp-form__row sp-form__row--2">
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></span><input class="sp-input" x-model="form.name" required></label>
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Price (USD)', 'southpoll-membership' ); ?></span><input class="sp-input" x-model="form.price" inputmode="decimal"></label>
			</div>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Description (shown at checkout)', 'southpoll-membership' ); ?></span><textarea class="sp-textarea" rows="2" x-model="form.description"></textarea></label>
			<div class="sp-form__row sp-form__row--3">
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Term (days)', 'southpoll-membership' ); ?></span><input class="sp-input" type="number" min="1" max="3650" x-model="form.duration_days"></label>
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Active listings allowed', 'southpoll-membership' ); ?></span><input class="sp-input" type="number" min="0" x-model="form.listing_limit" placeholder="<?php esc_attr_e( 'blank = global default', 'southpoll-membership' ); ?>"></label>
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Sort order', 'southpoll-membership' ); ?></span><input class="sp-input" type="number" x-model="form.sort_order"></label>
			</div>
			<div class="sp-cluster">
				<label class="sp-check"><input type="checkbox" x-model="form.is_default"> <span><?php esc_html_e( 'Default tier', 'southpoll-membership' ); ?></span></label>
				<label class="sp-check"><input type="checkbox" x-model="form.active"> <span><?php esc_html_e( 'Active (offered at checkout)', 'southpoll-membership' ); ?></span></label>
			</div>
			<div class="sp-form__actions"><button class="sp-btn sp-btn--primary" :disabled="busy"><?php esc_html_e( 'Save tier', 'southpoll-membership' ); ?></button> <button type="button" class="sp-btn sp-btn--ghost" @click="cancel()"><?php esc_html_e( 'Cancel', 'southpoll-membership' ); ?></button></div>
		</form>
		<?php
		$this->close();
	}
}
