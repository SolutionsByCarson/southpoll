<?php
/**
 * Admin drawer: one farm's membership. Rendered by AdminMembershipsController::detail().
 *
 * @var array<string, mixed> $m
 * @package SouthPoll\Membership
 */

defined( 'ABSPATH' ) || exit;

$ms    = $m['membership'];
$farm  = $m['farm'];
$badge = static fn( string $s ): string => 'sp-badge sp-badge--' . match ( $s ) {
	'active', 'succeeded' => 'success',
	'expiring', 'pending', 'received' => 'warning',
	'expired', 'cancelled', 'failed', 'rejected' => 'danger',
	default => 'muted',
};
$pay_endpoint = static fn( int $id ): string => 'admin/payments/' . $id . '/action';
?>
<div class="sp-drawer__head">
	<div class="sp-drawer__headtext">
		<h2 class="sp-drawer__title"><?php echo esc_html( $farm['name'] ); ?></h2>
		<div class="sp-drawer__sub"><span class="<?php echo esc_attr( $badge( $ms['status'] ) ); ?>"><?php echo esc_html( ucfirst( $ms['status'] ) ); ?></span>
			<?php if ( $ms['ends_at'] ) : ?><span><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'ends %s', 'southpoll-membership' ), $ms['ends_at'] ) ); ?><?php echo null !== $ms['days_left'] && 'active' === $ms['status'] ? esc_html( ' (' . sprintf( /* translators: %d: days */ _n( '%d day left', '%d days left', (int) $ms['days_left'], 'southpoll-membership' ), (int) $ms['days_left'] ) . ')' ) : ''; ?></span><?php endif; ?>
			<?php if ( $ms['auto_renew'] ) : ?><span class="sp-badge sp-badge--success"><?php esc_html_e( 'Auto-renew', 'southpoll-membership' ); ?></span><?php endif; ?>
			<?php if ( $m['pending_claim'] ) : ?><span class="sp-badge sp-badge--warning"><?php esc_html_e( 'Claim waiting', 'southpoll-membership' ); ?></span><?php endif; ?>
		</div>
		<div class="sp-text-small sp-text-muted"><?php echo esc_html( $farm['owner'] ); ?> · <a href="mailto:<?php echo esc_attr( $farm['owner_email'] ); ?>"><?php echo esc_html( $farm['owner_email'] ); ?></a><?php echo $farm['breeder_number'] ? esc_html( ' · ' . sprintf( /* translators: %s: number */ __( 'Breeder #%s', 'southpoll-membership' ), $farm['breeder_number'] ) ) : ''; ?> · <a href="<?php echo esc_url( $farm['admin_url'] ); ?>"><?php esc_html_e( 'Farm record', 'southpoll-membership' ); ?></a> · <a href="<?php echo esc_url( $farm['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Public page', 'southpoll-membership' ); ?></a></div>
	</div>
</div>

<nav class="sp-drawer__tabs" role="tablist">
	<button type="button" class="sp-drawer__tab is-active" data-sp-tab="overview"><?php esc_html_e( 'Overview', 'southpoll-membership' ); ?></button>
	<button type="button" class="sp-drawer__tab" data-sp-tab="adjust"><?php esc_html_e( 'Adjust', 'southpoll-membership' ); ?></button>
	<button type="button" class="sp-drawer__tab" data-sp-tab="payments"><?php esc_html_e( 'Payments', 'southpoll-membership' ); ?> (<?php echo count( $m['payments'] ); ?>)</button>
	<button type="button" class="sp-drawer__tab" data-sp-tab="notes"><?php esc_html_e( 'Notes', 'southpoll-membership' ); ?></button>
</nav>

<section class="sp-drawer__panel" data-sp-panel="overview">
	<?php if ( $m['pending_claim'] ) : ?><div class="sp-alert sp-alert--warning"><?php echo wp_kses( sprintf( /* translators: %s: URL */ __( 'An offline payment claim is waiting. Verify it on the <a href="%s">Claims tab</a>.', 'southpoll-membership' ), esc_url( admin_url( 'admin.php?page=southpoll-memberships&tab=claims' ) ) ), [ 'a' => [ 'href' => [] ] ] ); ?></div><?php endif; ?>
	<dl class="sp-dl">
		<dt><?php esc_html_e( 'Status', 'southpoll-membership' ); ?></dt><dd><span class="<?php echo esc_attr( $badge( $ms['status'] ) ); ?>"><?php echo esc_html( ucfirst( $ms['status'] ) ); ?></span><?php echo $ms['stored'] !== $ms['status'] ? ' <span class="sp-text-small sp-text-muted">(' . esc_html( sprintf( /* translators: %s: status */ __( 'stored as %s; the cron job will mark it', 'southpoll-membership' ), $ms['stored'] ) ) . ')</span>' : ''; ?></dd>
		<dt><?php esc_html_e( 'Term', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $ms['starts_at'] || $ms['ends_at'] ? ( $ms['starts_at'] ?: '?' ) . ' – ' . ( $ms['ends_at'] ?: '?' ) : __( 'No term yet', 'southpoll-membership' ) ); ?></dd>
		<dt><?php esc_html_e( 'Tier', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $ms['tier'] . ' · ' . sprintf( /* translators: %d: days */ __( '%d-day term', 'southpoll-membership' ), (int) $ms['tier_days'] ) . ( $ms['price_paid'] ? ' · ' . sprintf( /* translators: %s: amount */ __( 'last paid %s', 'southpoll-membership' ), $ms['price_paid'] ) : '' ) ); ?></dd>
		<dt><?php esc_html_e( 'Source', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( ucfirst( $ms['source'] ) . ( $ms['activated_at'] ? ' · ' . sprintf( /* translators: %s: datetime */ __( 'activated %s', 'southpoll-membership' ), $ms['activated_at'] ) : '' ) ); ?></dd>
		<dt><?php esc_html_e( 'Auto-renew', 'southpoll-membership' ); ?></dt><dd><?php echo $ms['auto_renew'] ? esc_html__( 'On (Stripe subscription)', 'southpoll-membership' ) : esc_html__( 'Off', 'southpoll-membership' ); ?></dd>
		<?php if ( $ms['stripe_customer_id'] || $ms['stripe_subscription_id'] ) : ?><dt><?php esc_html_e( 'Stripe', 'southpoll-membership' ); ?></dt><dd><code><?php echo esc_html( $ms['stripe_customer_id'] ); ?></code> <code><?php echo esc_html( $ms['stripe_subscription_id'] ); ?></code></dd><?php endif; ?>
	</dl>
	<div class="sp-drawer__actions">
		<form data-sp-action="extend"><input type="hidden" name="days" value="<?php echo (int) $ms['tier_days']; ?>"><button class="sp-btn sp-btn--outline sp-btn--sm"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Extend %d days', 'southpoll-membership' ), (int) $ms['tier_days'] ) ); ?></button></form>
		<?php if ( $ms['auto_renew'] ) : ?><form data-sp-action="auto_renew_off" data-sp-confirm="<?php esc_attr_e( 'Turn auto-renew off? The Stripe subscription ends at the period end and the member will get expiry reminders instead.', 'southpoll-membership' ); ?>"><button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Turn auto-renew off', 'southpoll-membership' ); ?></button></form><?php endif; ?>
	</div>
</section>

<section class="sp-drawer__panel" data-sp-panel="adjust" hidden>
	<form data-sp-action="extend" class="sp-drawer__form sp-drawer__box">
		<h3 class="sp-drawer__h"><?php esc_html_e( 'Extend', 'southpoll-membership' ); ?></h3>
		<div class="sp-form__row sp-form__row--2">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Days to add', 'southpoll-membership' ); ?></span><input class="sp-input sp-input--short" type="number" name="days" min="1" max="3650" value="<?php echo (int) $ms['tier_days']; ?>" required></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Note', 'southpoll-membership' ); ?></span><input class="sp-input" name="note" placeholder="<?php esc_attr_e( 'why (internal)', 'southpoll-membership' ); ?>"></label>
		</div>
		<p class="sp-help"><?php esc_html_e( 'Adds to the current end date (or starts from today if lapsed). Activates a lapsed membership; no email is sent.', 'southpoll-membership' ); ?></p>
		<button class="sp-btn sp-btn--primary sp-btn--sm"><?php esc_html_e( 'Extend', 'southpoll-membership' ); ?></button>
	</form>
	<form data-sp-action="set_end" class="sp-drawer__form sp-drawer__box">
		<h3 class="sp-drawer__h"><?php esc_html_e( 'Set end date', 'southpoll-membership' ); ?></h3>
		<div class="sp-form__row sp-form__row--2">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Ends on', 'southpoll-membership' ); ?></span><input class="sp-input" type="date" name="ends_at" value="<?php echo esc_attr( $ms['ends_at_raw'] ); ?>" required></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Note', 'southpoll-membership' ); ?></span><input class="sp-input" name="note"></label>
		</div>
		<p class="sp-help"><?php esc_html_e( 'A future date makes the membership active; a past date expires it (farm and listings hide at the next cron run or immediately on page load).', 'southpoll-membership' ); ?></p>
		<button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Set end date', 'southpoll-membership' ); ?></button>
	</form>
	<form data-sp-action="record" class="sp-drawer__form sp-drawer__box">
		<h3 class="sp-drawer__h"><?php esc_html_e( 'Record an offline payment', 'southpoll-membership' ); ?></h3>
		<div class="sp-form__row sp-form__row--3">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Method', 'southpoll-membership' ); ?></span><select class="sp-select" name="method"><option value="check"><?php esc_html_e( 'Check', 'southpoll-membership' ); ?></option><option value="cash"><?php esc_html_e( 'Cash', 'southpoll-membership' ); ?></option><option value="other"><?php esc_html_e( 'Other', 'southpoll-membership' ); ?></option></select></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Amount (USD)', 'southpoll-membership' ); ?></span><input class="sp-input" name="amount" value="<?php echo esc_attr( $ms['tier_price'] ); ?>" inputmode="decimal"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Paid on', 'southpoll-membership' ); ?></span><input class="sp-input" type="date" name="paid_at"></label>
		</div>
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Note', 'southpoll-membership' ); ?></span><input class="sp-input" name="note" placeholder="<?php esc_attr_e( 'check number, etc.', 'southpoll-membership' ); ?>"></label>
		<p class="sp-help"><?php esc_html_e( 'Adds a full tier term (from the current end date if still active) and emails the activation notice + receipt.', 'southpoll-membership' ); ?></p>
		<button class="sp-btn sp-btn--primary sp-btn--sm"><?php esc_html_e( 'Record & activate', 'southpoll-membership' ); ?></button>
	</form>
	<form data-sp-action="comp" class="sp-drawer__form sp-drawer__box" data-sp-confirm="<?php esc_attr_e( 'Add a complimentary term (no charge)? The member is emailed the activation notice.', 'southpoll-membership' ); ?>">
		<h3 class="sp-drawer__h"><?php esc_html_e( 'Complimentary term', 'southpoll-membership' ); ?></h3>
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Reason', 'southpoll-membership' ); ?></span><input class="sp-input" name="note" placeholder="<?php esc_attr_e( 'e.g. board member, sponsor', 'southpoll-membership' ); ?>"></label>
		<button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Add complimentary term', 'southpoll-membership' ); ?></button>
	</form>
	<?php if ( 'cancelled' !== $ms['stored'] ) : ?>
		<form data-sp-action="cancel" class="sp-drawer__form sp-drawer__box sp-drawer__box--danger" data-sp-confirm="<?php esc_attr_e( 'Cancel this membership now? The farm page and listings are hidden immediately, any Stripe subscription is cancelled, and the owner is emailed. Nothing is deleted.', 'southpoll-membership' ); ?>" data-sp-danger>
			<h3 class="sp-drawer__h"><?php esc_html_e( 'Cancel membership', 'southpoll-membership' ); ?></h3>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Reason (included in the email)', 'southpoll-membership' ); ?></span><input class="sp-input" name="reason"></label>
			<button class="sp-btn sp-btn--danger sp-btn--sm"><?php esc_html_e( 'Cancel membership', 'southpoll-membership' ); ?></button>
		</form>
	<?php endif; ?>
</section>

<section class="sp-drawer__panel" data-sp-panel="payments" hidden>
	<?php if ( ! $m['payments'] ) : ?><p class="sp-text-muted"><?php esc_html_e( 'No payments recorded for this farm.', 'southpoll-membership' ); ?></p><?php endif; ?>
	<?php foreach ( $m['payments'] as $p ) : ?>
		<div class="sp-drawer__box sp-drawer__payment">
			<div class="sp-drawer__payment-head"><strong><?php echo esc_html( $p['amount'] ); ?></strong> · <?php echo esc_html( $p['method'] ); ?> · <span class="<?php echo esc_attr( $badge( $p['status'] ) ); ?>"><?php echo esc_html( $p['status'] ); ?></span> · <?php echo esc_html( $p['paid_at'] ); ?><?php echo $p['term'] ? esc_html( ' · ' . $p['term'] ) : ''; ?><?php echo $p['receipt_url'] ? ' · <a href="' . esc_url( $p['receipt_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'receipt', 'southpoll-membership' ) . '</a>' : ''; ?> <span class="sp-text-muted">#<?php echo (int) $p['id']; ?></span></div>
			<?php if ( $p['notes'] ) : ?><pre class="sp-drawer__notes"><?php echo esc_html( $p['notes'] ); ?></pre><?php endif; ?>
			<div class="sp-drawer__payment-actions">
				<form data-sp-endpoint="<?php echo esc_attr( $pay_endpoint( (int) $p['id'] ) ); ?>" data-sp-action="note" class="sp-cluster"><input class="sp-input sp-input--sm" name="note" placeholder="<?php esc_attr_e( 'Add a note', 'southpoll-membership' ); ?>" required><button class="sp-btn sp-btn--ghost sp-btn--sm"><?php esc_html_e( 'Add', 'southpoll-membership' ); ?></button></form>
				<?php if ( 'succeeded' === $p['status'] ) : ?><form data-sp-endpoint="<?php echo esc_attr( $pay_endpoint( (int) $p['id'] ) ); ?>" data-sp-action="refunded" data-sp-confirm="<?php esc_attr_e( 'Mark this payment as refunded? Refund the card in the Stripe Dashboard (or return the check) separately; the membership is not changed.', 'southpoll-membership' ); ?>" data-sp-danger><button class="sp-btn sp-btn--ghost sp-btn--sm sp-admin__danger"><?php esc_html_e( 'Mark refunded', 'southpoll-membership' ); ?></button></form><?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
</section>

<section class="sp-drawer__panel" data-sp-panel="notes" hidden>
	<?php if ( $ms['notes'] ) : ?><pre class="sp-drawer__notes sp-drawer__notes--tall"><?php echo esc_html( $ms['notes'] ); ?></pre><?php else : ?><p class="sp-text-muted"><?php esc_html_e( 'No notes yet. Staff actions are logged here automatically.', 'southpoll-membership' ); ?></p><?php endif; ?>
	<form data-sp-action="note" class="sp-drawer__form">
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Add a note', 'southpoll-membership' ); ?></span><textarea class="sp-textarea" name="note" rows="3" required></textarea></label>
		<button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Add note', 'southpoll-membership' ); ?></button>
	</form>
</section>
