<?php
/**
 * Admin → SouthPoll (Dashboard): count-up stats, the offline-claim queue, recent activity, quick links.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Rest\Admin\AdminStatsController;
use SouthPoll\Membership\Support\Str;

final class DashboardPage extends AdminPage {

	public const SLUG = 'southpoll';

	public function __construct( private AdminStatsController $stats, private FarmRepository $farms ) {}

	public function render(): void {
		$this->guard();
		$s = $this->stats->stats();
		$c = $s['counts'];
		$tiles = [
			[ 'label' => __( 'Member accounts', 'southpoll-membership' ), 'value' => $c['accounts'], 'href' => UsersPage::url( [ 'role' => 'members' ] ) ],
			[ 'label' => __( 'Farm owners', 'southpoll-membership' ), 'value' => $c['owners'], 'href' => UsersPage::url( [ 'role' => 'owner' ] ) ],
			[ 'label' => __( 'Public farms', 'southpoll-membership' ), 'value' => $c['farms_public'], 'sub' => sprintf( /* translators: %d: total */ __( 'of %d farms', 'southpoll-membership' ), $c['farms_total'] ), 'href' => FarmsPage::url() ],
			[ 'label' => __( 'Active memberships', 'southpoll-membership' ), 'value' => $c['memberships_active'], 'sub' => sprintf( /* translators: %d: count */ __( '%d on auto-renew', 'southpoll-membership' ), $c['auto_renew'] ), 'href' => MembershipsPage::url( [ 'status' => 'active' ] ) ],
			[ 'label' => __( 'Expiring in 30 days', 'southpoll-membership' ), 'value' => $c['memberships_expiring'], 'href' => MembershipsPage::url( [ 'status' => 'expiring' ] ), 'accent' => $c['memberships_expiring'] > 0 ],
			[ 'label' => __( 'Claims to verify', 'southpoll-membership' ), 'value' => $c['claims_pending'], 'href' => MembershipsPage::url( [ 'tab' => 'claims' ] ), 'accent' => $c['claims_pending'] > 0 ],
			[ 'label' => __( 'Live listings', 'southpoll-membership' ), 'value' => $c['listings_live'], 'sub' => sprintf( /* translators: %d: count */ __( '%d new this week', 'southpoll-membership' ), $c['listings_7d'] ), 'href' => ListingsPage::url( [ 'visibility' => 'live' ] ) ],
			[ 'label' => __( 'Inquiries (30 days)', 'southpoll-membership' ), 'value' => $c['inquiries_30d'] ],
			[ 'label' => __( 'Revenue (30 days)', 'southpoll-membership' ), 'value' => $c['revenue_30d_cents'] / 100, 'prefix' => '$', 'sub' => sprintf( /* translators: %d: count */ __( '%d payments', 'southpoll-membership' ), $c['payments_30d'] ), 'href' => MembershipsPage::url( [ 'tab' => 'payments' ] ) ],
			[ 'label' => __( 'Revenue (year to date)', 'southpoll-membership' ), 'value' => $c['revenue_ytd_cents'] / 100, 'prefix' => '$', 'href' => MembershipsPage::url( [ 'tab' => 'payments', 'from' => gmdate( 'Y' ) . '-01-01' ] ) ],
			[ 'label' => __( 'Expired memberships', 'southpoll-membership' ), 'value' => $c['memberships_expired'], 'href' => MembershipsPage::url( [ 'status' => 'expired' ] ) ],
			[ 'label' => __( 'Unverified emails', 'southpoll-membership' ), 'value' => $c['unverified'], 'href' => UsersPage::url( [ 'role' => 'unverified' ] ) ],
			[ 'label' => __( 'Favorites / saved searches', 'southpoll-membership' ), 'value' => $c['favorites'], 'sub' => sprintf( /* translators: %d: count */ __( '%d saved searches', 'southpoll-membership' ), $c['saved_searches'] ) ],
			[ 'label' => __( 'Alerts queued', 'southpoll-membership' ), 'value' => $c['alerts_pending'], 'sub' => __( 'sent every 5 minutes', 'southpoll-membership' ), 'href' => StatusPage::url() ],
		];
		$this->open( __( 'SouthPoll Membership', 'southpoll-membership' ), '', [], '<a class="sp-btn sp-btn--outline sp-btn--sm" href="' . esc_url( ImportExportPage::url() ) . '">' . esc_html__( 'Import / Export', 'southpoll-membership' ) . '</a> <a class="sp-btn sp-btn--ghost sp-btn--sm" href="' . esc_url( StatusPage::url() ) . '">' . esc_html__( 'Status', 'southpoll-membership' ) . '</a>' );
		?>
		<div class="sp-grid sp-grid--stats sp-admin__stats">
			<?php foreach ( $tiles as $t ) : ?>
				<?php $tag = ! empty( $t['href'] ) ? 'a' : 'div'; ?>
				<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="sp-stat<?php echo ! empty( $t['accent'] ) ? ' sp-stat--accent' : ''; ?>"<?php echo ! empty( $t['href'] ) ? ' href="' . esc_url( $t['href'] ) . '"' : ''; ?>>
					<span class="sp-stat__value" data-countup="<?php echo esc_attr( (string) $t['value'] ); ?>" data-countup-prefix="<?php echo esc_attr( $t['prefix'] ?? '' ); ?>" data-countup-decimals="<?php echo isset( $t['prefix'] ) && fmod( (float) $t['value'], 1 ) > 0 ? '2' : '0'; ?>"><?php echo esc_html( ( $t['prefix'] ?? '' ) . '0' ); ?></span>
					<span class="sp-stat__label"><?php echo esc_html( $t['label'] ); ?></span>
					<?php if ( ! empty( $t['sub'] ) ) : ?><span class="sp-stat__sub"><?php echo esc_html( $t['sub'] ); ?></span><?php endif; ?>
				</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php endforeach; ?>
		</div>

		<?php if ( $s['recent']['claims'] ) : ?>
			<div class="sp-card sp-card--notice sp-admin__claims">
				<div class="sp-card__header"><h2 class="sp-card__title"><?php echo esc_html( sprintf( /* translators: %d: count */ __( '%d offline payment claim(s) waiting for verification', 'southpoll-membership' ), count( $s['recent']['claims'] ) ) ); ?></h2><a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( MembershipsPage::url( [ 'tab' => 'claims' ] ) ); ?>"><?php esc_html_e( 'Verify claims', 'southpoll-membership' ); ?></a></div>
				<ul class="sp-admin__list">
					<?php foreach ( array_slice( $s['recent']['claims'], 0, 5 ) as $cl ) : ?>
						<?php $farm = $this->farms->find( (int) $cl['farm_id'] ); ?>
						<li><strong><?php echo esc_html( $farm ? (string) $farm->name : '#' . $cl['farm_id'] ); ?></strong> — <?php echo esc_html( Str::money( (int) $cl['amount'] ) . ' · ' . PaymentRepository::method_label( (string) $cl['method'] ) . ' · ' . $cl['created'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="sp-grid sp-grid--3 sp-admin__recent">
			<div class="sp-card sp-card--flat">
				<div class="sp-card__header"><h2 class="sp-card__title"><?php esc_html_e( 'Latest listings', 'southpoll-membership' ); ?></h2><a href="<?php echo esc_url( ListingsPage::url() ); ?>"><?php esc_html_e( 'All listings', 'southpoll-membership' ); ?></a></div>
				<?php if ( $s['recent']['listings'] ) : ?><ul class="sp-admin__list"><?php foreach ( $s['recent']['listings'] as $r ) : ?><li><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r['title'] ); ?></a><span class="sp-text-muted sp-text-small"> · <?php echo esc_html( $r['farm'] . ' · ' . $r['date'] ); ?></span></li><?php endforeach; ?></ul><?php else : ?><p class="sp-text-muted"><?php esc_html_e( 'No listings yet.', 'southpoll-membership' ); ?></p><?php endif; ?>
			</div>
			<div class="sp-card sp-card--flat">
				<div class="sp-card__header"><h2 class="sp-card__title"><?php esc_html_e( 'Latest memberships', 'southpoll-membership' ); ?></h2><a href="<?php echo esc_url( MembershipsPage::url() ); ?>"><?php esc_html_e( 'All memberships', 'southpoll-membership' ); ?></a></div>
				<?php if ( $s['recent']['memberships'] ) : ?><ul class="sp-admin__list"><?php foreach ( $s['recent']['memberships'] as $r ) : ?><li><a href="<?php echo esc_url( MembershipsPage::url( [ 'farm' => $r['farm_id'] ] ) ); ?>"><?php echo esc_html( $r['farm'] ); ?></a><span class="sp-text-muted sp-text-small"> · <?php echo esc_html( $r['date'] . ' · ' . $r['source'] . ( $r['amount'] ? ' · ' . Str::money( (int) $r['amount'] ) : '' ) . ' · ' . sprintf( /* translators: %s: date */ __( 'ends %s', 'southpoll-membership' ), $r['ends'] ) ); ?></span></li><?php endforeach; ?></ul><?php else : ?><p class="sp-text-muted"><?php esc_html_e( 'No memberships activated yet.', 'southpoll-membership' ); ?></p><?php endif; ?>
			</div>
			<div class="sp-card sp-card--flat">
				<div class="sp-card__header"><h2 class="sp-card__title"><?php esc_html_e( 'Newest accounts', 'southpoll-membership' ); ?></h2><a href="<?php echo esc_url( UsersPage::url() ); ?>"><?php esc_html_e( 'All users', 'southpoll-membership' ); ?></a></div>
				<?php if ( $s['recent']['accounts'] ) : ?><ul class="sp-admin__list"><?php foreach ( $s['recent']['accounts'] as $r ) : ?><li><a href="<?php echo esc_url( UsersPage::url( [ 'user' => $r['id'] ] ) ); ?>"><?php echo esc_html( $r['name'] ?: $r['email'] ); ?></a><span class="sp-text-muted sp-text-small"> · <?php echo esc_html( $r['date'] ); ?><?php echo $r['verified'] ? '' : ' · <span class="sp-badge sp-badge--warning">' . esc_html__( 'unverified', 'southpoll-membership' ) . '</span>'; ?></span></li><?php endforeach; ?></ul><?php else : ?><p class="sp-text-muted"><?php esc_html_e( 'No member accounts yet.', 'southpoll-membership' ); ?></p><?php endif; ?>
			</div>
		</div>
		<p class="sp-text-muted sp-text-small"><?php echo esc_html( sprintf( /* translators: %s: UTC timestamp */ __( 'Figures as of %s UTC.', 'southpoll-membership' ), $s['generated_at'] ) ); ?></p>
		<?php
		$this->close();
	}
}
