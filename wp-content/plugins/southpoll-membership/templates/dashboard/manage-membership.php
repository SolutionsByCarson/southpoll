<?php
/**
 * My Membership.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var array  $summary
 * @var array  $tiers
 * @var object|null $default_tier
 * @var array  $history
 * @var string $card_label
 * @var bool   $stripe_ready
 * @var bool   $claims_enabled
 * @var array  $farm_users
 * @var object|null $owner
 * @var WP_Error|null $error
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array  $states
 * @var \SouthPoll\Membership\Core\Settings $settings
 */

defined( 'ABSPATH' ) || exit;

use SouthPoll\Membership\Payments\PaymentRepository;
use SouthPoll\Membership\Support\Dates;
use SouthPoll\Membership\Support\Str;

$sp_state   = (string) $summary['state'];
$sp_can_buy = $context->is_owner() && in_array( $sp_state, [ 'none', 'expired', 'cancelled', 'active' ], true ) && ! $summary['pending_claim'];
$sp_tier    = $default_tier;
$sp_price   = $sp_tier ? Str::money( (int) $sp_tier->price_cents, (string) $sp_tier->currency ) : '';
?>
<?php if ( $error ) : ?><div class="sp-alert sp-alert--error" role="alert"><span class="sp-alert__title"><?php esc_html_e( 'Something needs your attention', 'southpoll-membership' ); ?></span><?php echo esc_html( $error->get_error_message() ); ?></div><?php endif; ?>

<?php if ( 'buyer' === $sp_state ) : ?>
	<div class="sp-grid sp-grid--2">
		<div class="sp-card">
			<p class="sp-eyebrow"><?php esc_html_e( 'SPGCA membership', 'southpoll-membership' ); ?></p>
			<h2 class="sp-h2"><?php echo esc_html( $sp_tier ? (string) $sp_tier->name : __( 'Membership', 'southpoll-membership' ) ); ?> <span class="sp-text-muted" style="font-size:.6em"><?php echo esc_html( $sp_price ); ?> / <?php esc_html_e( 'year', 'southpoll-membership' ); ?></span></h2>
			<ul class="sp-list">
				<li><?php esc_html_e( 'Your own farm page in the member directory', 'southpoll-membership' ); ?></li>
				<li><?php esc_html_e( 'Publish cattle classified listings', 'southpoll-membership' ); ?></li>
				<li><?php esc_html_e( 'Invite account managers to help run your page', 'southpoll-membership' ); ?></li>
				<li><?php esc_html_e( 'Registry discounts, newsletters and member events', 'southpoll-membership' ); ?></li>
			</ul>
		</div>
		<form method="post" class="sp-card sp-form">
			<?php wp_nonce_field( 'sp_membership_create_farm', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="create_farm">
			<h3 class="sp-h3"><?php esc_html_e( 'Step 1 · Name your farm', 'southpoll-membership' ); ?></h3>
			<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Memberships belong to a farm or ranch. You can add details, photos and managers afterwards.', 'southpoll-membership' ); ?></p>
			<div class="sp-field"><label class="sp-label" for="mm-farm"><?php esc_html_e( 'Farm / ranch name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="mm-farm" class="sp-input" name="farm_name" required></div>
			<div class="sp-field"><label class="sp-label" for="mm-state"><?php esc_html_e( 'State', 'southpoll-membership' ); ?></label><select id="mm-state" class="sp-select" name="state"><option value=""><?php esc_html_e( 'Select…', 'southpoll-membership' ); ?></option><?php foreach ( $states as $abbr => $sname ) : ?><option value="<?php echo esc_attr( $abbr ); ?>"><?php echo esc_html( $sname ); ?></option><?php endforeach; ?></select></div>
			<div class="sp-field"><label class="sp-label" for="mm-breeder"><?php esc_html_e( 'SPGCA breeder number', 'southpoll-membership' ); ?></label><input id="mm-breeder" class="sp-input" name="breeder_number" autocomplete="off"><div class="sp-help"><?php esc_html_e( 'Optional, if you already have a registry breeder number for this farm.', 'southpoll-membership' ); ?></div></div>
			<button class="sp-btn sp-btn--primary" type="submit"><?php esc_html_e( 'Continue to payment', 'southpoll-membership' ); ?></button>
		</form>
	</div>
<?php else : ?>

	<div class="sp-grid sp-grid--2 sp-mm">
		<div class="sp-card">
			<div class="sp-card__header">
				<h2 class="sp-card__title" style="margin:0"><?php esc_html_e( 'Membership status', 'southpoll-membership' ); ?></h2>
				<span class="sp-badge sp-badge--<?php echo esc_attr( (string) $summary['badge'] ); ?>"><?php echo esc_html( (string) $summary['label'] ); ?></span>
			</div>
			<dl class="sp-dl">
				<dt><?php esc_html_e( 'Farm', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( (string) $context->farm->name ); ?></dd>
				<dt><?php esc_html_e( 'Owner', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $owner ? (string) $owner->display_name : '' ); ?><?php if ( $owner && $context->is_manager() ) : ?> &middot; <a href="mailto:<?php echo esc_attr( (string) $owner->email ); ?>"><?php echo esc_html( (string) $owner->email ); ?></a><?php endif; ?></dd>
				<?php if ( $summary['tier'] ) : ?><dt><?php esc_html_e( 'Plan', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( (string) $summary['tier']->name ); ?> &middot; <?php echo esc_html( $summary['price'] ); ?>/<?php esc_html_e( 'year', 'southpoll-membership' ); ?></dd><?php endif; ?>
				<?php if ( $context->membership && ! empty( $context->membership->starts_at ) ) : ?><dt><?php esc_html_e( 'Current term', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( Dates::display( $context->membership->starts_at ) . ' – ' . Dates::display( $context->membership->ends_at ) ); ?></dd><?php endif; ?>
				<?php if ( 'active' === $sp_state ) : ?><dt><?php esc_html_e( 'Days remaining', 'southpoll-membership' ); ?></dt><dd><strong><?php echo esc_html( (string) (int) $summary['days'] ); ?></strong></dd><?php endif; ?>
				<dt><?php esc_html_e( 'Automatic renewal', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $summary['auto_renew'] ? __( 'On', 'southpoll-membership' ) : __( 'Off', 'southpoll-membership' ) ); ?><?php if ( $card_label ) : ?> &middot; <?php echo esc_html( $card_label ); ?><?php endif; ?></dd>
			</dl>
			<?php if ( $summary['pending_claim'] ) : ?>
				<div class="sp-alert sp-alert--info"><?php esc_html_e( 'An offline payment claim is waiting for verification by the office.', 'southpoll-membership' ); ?></div>
			<?php endif; ?>

			<?php if ( $context->is_owner() && 'active' === $sp_state && ! $summary['pending_claim'] ) : ?>
				<div class="sp-cluster" style="margin-top:12px">
					<form method="post"><?php wp_nonce_field( 'sp_membership_auto_renew', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="auto_renew"><input type="hidden" name="on" value="<?php echo $summary['auto_renew'] ? '0' : '1'; ?>">
						<button class="sp-btn <?php echo $summary['auto_renew'] ? 'sp-btn--outline' : 'sp-btn--secondary'; ?>" type="submit"<?php echo ( ! $stripe_ready ) ? ' disabled' : ''; ?>><?php echo esc_html( $summary['auto_renew'] ? __( 'Turn off auto-renew', 'southpoll-membership' ) : __( 'Set up automatic renewal', 'southpoll-membership' ) ); ?></button>
					</form>
					<?php if ( ! empty( $context->membership->stripe_customer_id ) && $stripe_ready ) : ?>
						<form method="post"><?php wp_nonce_field( 'sp_membership_portal', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="portal"><button class="sp-btn sp-btn--ghost" type="submit"><?php esc_html_e( 'Update card / view Stripe invoices', 'southpoll-membership' ); ?></button></form>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $context->is_owner() && 'active' === $sp_state && ! $summary['pending_claim'] ) : ?>
				<div class="sp-mm__cancel" x-data>
					<h3 class="sp-h3" style="margin-top:20px"><?php esc_html_e( 'Cancel membership', 'southpoll-membership' ); ?></h3>
					<?php if ( $summary['auto_renew'] ) : ?>
						<p class="sp-text-small"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Cancelling turns automatic renewal off. Your membership stays fully active until %s, then expires. Nothing is refunded and nothing more is charged.', 'southpoll-membership' ), $summary['ends_display'] ) ); ?></p>
						<form method="post" @submit.prevent="SP.confirm({ title: <?php echo wp_json_encode( __( 'Cancel membership?', 'southpoll-membership' ) ); ?>, message: <?php echo wp_json_encode( sprintf( /* translators: %s: date */ __( 'Automatic renewal will be turned off. You keep full access until %s; after that the farm page and listings are hidden until you rejoin.', 'southpoll-membership' ), $summary['ends_display'] ) ); ?>, confirmLabel: <?php echo wp_json_encode( __( 'Yes, cancel my membership', 'southpoll-membership' ) ); ?>, cancelLabel: <?php echo wp_json_encode( __( 'Keep it', 'southpoll-membership' ) ); ?>, danger: true }).then(function (ok) { if (ok) $el.submit(); })">
							<?php wp_nonce_field( 'sp_membership_cancel', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="cancel">
							<button class="sp-btn sp-btn--danger" type="submit"><?php esc_html_e( 'Cancel membership (stop automatic renewal)', 'southpoll-membership' ); ?></button>
						</form>
					<?php else : ?>
						<div class="sp-alert sp-alert--warning sp-mm__cancel-note" role="status">
							<p style="margin:0 0 6px"><strong><?php esc_html_e( 'There is nothing to cancel.', 'southpoll-membership' ); ?></strong></p>
							<p style="margin:0"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Your membership is NOT set to renew automatically. It will simply expire on %s and you will not be charged. To keep your membership past that date, renew it above (or turn automatic renewal on).', 'southpoll-membership' ), $summary['ends_display'] ) ); ?></p>
						</div>
						<button class="sp-btn sp-btn--danger" type="button" disabled aria-disabled="true" title="<?php esc_attr_e( 'Automatic renewal is already off', 'southpoll-membership' ); ?>"><?php esc_html_e( 'Cancel membership', 'southpoll-membership' ); ?></button>
						<p class="sp-text-muted sp-text-small" style="margin-top:6px"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Expires on %s · automatic renewal: off', 'southpoll-membership' ), $summary['ends_display'] ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $sp_can_buy ) : ?>
		<div class="sp-card sp-mm__buy" x-data="{ claim: false, tier: '<?php echo esc_js( (string) ( $sp_tier->id ?? '' ) ); ?>' }">
			<h2 class="sp-card__title"><?php echo esc_html( 'active' === $sp_state ? __( 'Renew early', 'southpoll-membership' ) : __( 'Activate your membership', 'southpoll-membership' ) ); ?></h2>
			<?php if ( 'active' === $sp_state ) : ?><p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Renewing now adds a full year to the end of your current term; you never lose days.', 'southpoll-membership' ); ?></p><?php endif; ?>

			<?php if ( count( $tiers ) > 1 ) : ?>
				<div class="sp-field"><label class="sp-label"><?php esc_html_e( 'Choose a plan', 'southpoll-membership' ); ?></label>
					<?php foreach ( $tiers as $t ) : ?>
						<label class="sp-check" style="display:flex;margin-bottom:6px"><input type="radio" name="tier_pick" value="<?php echo esc_attr( (string) $t->id ); ?>" x-model="tier"> <span><strong><?php echo esc_html( (string) $t->name ); ?></strong> — <?php echo esc_html( Str::money( (int) $t->price_cents, (string) $t->currency ) ); ?> / <?php echo esc_html( (int) $t->duration_days ); ?> <?php esc_html_e( 'days', 'southpoll-membership' ); ?></span></label>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p><strong><?php echo esc_html( (string) ( $sp_tier->name ?? '' ) ); ?></strong> — <span class="sp-mm__price"><?php echo esc_html( $sp_price ); ?></span> <span class="sp-text-muted"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'for %d days', 'southpoll-membership' ), (int) ( $sp_tier->duration_days ?? 365 ) ) ); ?></span></p>
			<?php endif; ?>

			<?php if ( $stripe_ready ) : ?>
				<form method="post" class="sp-cluster" style="margin-bottom:10px">
					<?php wp_nonce_field( 'sp_membership_checkout', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="checkout"><input type="hidden" name="tier" :value="tier">
					<button class="sp-btn sp-btn--primary" type="submit" name="recurring" value="0"><?php esc_html_e( 'Pay online now', 'southpoll-membership' ); ?></button>
					<button class="sp-btn sp-btn--secondary" type="submit" name="recurring" value="1"><?php esc_html_e( 'Pay & auto-renew yearly', 'southpoll-membership' ); ?></button>
				</form>
				<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Secure card payment through Stripe. Auto-renew can be turned off at any time.', 'southpoll-membership' ); ?></p>
			<?php else : ?>
				<div class="sp-alert sp-alert--warning"><?php esc_html_e( 'Online card payments are not configured yet. You can still tell us about a check or cash payment below.', 'southpoll-membership' ); ?></div>
			<?php endif; ?>

			<?php if ( $claims_enabled ) : ?>
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="claim = !claim" :aria-expanded="claim"><?php esc_html_e( 'I paid by check or cash', 'southpoll-membership' ); ?></button>
				<form method="post" class="sp-form sp-mm__claim" x-show="claim" x-collapse style="display:none">
					<?php wp_nonce_field( 'sp_membership_claim', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="claim"><input type="hidden" name="tier" :value="tier">
					<p class="sp-text-small"><?php echo esc_html( sprintf( /* translators: 1: office name, 2: phone */ __( 'Tell us about your offline payment and %1$s will verify it (%2$s). Your membership activates once it is confirmed.', 'southpoll-membership' ), (string) $settings->get( 'admin_contact.name' ), (string) $settings->get( 'admin_contact.phone' ) ) ); ?></p>
					<div class="sp-form__row sp-form__row--3">
						<div class="sp-field"><label class="sp-label" for="cl-method"><?php esc_html_e( 'Paid by', 'southpoll-membership' ); ?></label><select id="cl-method" class="sp-select" name="method"><option value="check"><?php esc_html_e( 'Check', 'southpoll-membership' ); ?></option><option value="cash"><?php esc_html_e( 'Cash', 'southpoll-membership' ); ?></option><option value="other"><?php esc_html_e( 'Other', 'southpoll-membership' ); ?></option></select></div>
						<div class="sp-field"><label class="sp-label" for="cl-amount"><?php esc_html_e( 'Amount', 'southpoll-membership' ); ?></label><input id="cl-amount" class="sp-input" name="amount" value="<?php echo esc_attr( $sp_tier ? number_format( ( (int) $sp_tier->price_cents ) / 100, 2, '.', '' ) : '' ); ?>"></div>
						<div class="sp-field"><label class="sp-label" for="cl-date"><?php esc_html_e( 'Date paid', 'southpoll-membership' ); ?></label><input id="cl-date" class="sp-input" type="date" name="paid_on"></div>
					</div>
					<div class="sp-field"><label class="sp-label" for="cl-note"><?php esc_html_e( 'Note for the office (check number, event, etc.)', 'southpoll-membership' ); ?></label><textarea id="cl-note" class="sp-textarea" name="note" rows="2"></textarea></div>
					<button class="sp-btn sp-btn--outline" type="submit"><?php esc_html_e( 'Submit for verification', 'southpoll-membership' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php elseif ( $context->is_manager() ) : ?>
		<div class="sp-card sp-card--notice">
			<h3 class="sp-h3"><?php esc_html_e( 'Billing is handled by the owner', 'southpoll-membership' ); ?></h3>
			<p><?php esc_html_e( 'Only the farm owner can renew the membership, change renewal settings, or update the card on file.', 'southpoll-membership' ); ?></p>
		</div>
		<?php endif; ?>
	</div>

	<?php if ( $context->is_owner() && count( $farm_users ) > 1 ) : ?>
	<form method="post" class="sp-card sp-form">
		<?php wp_nonce_field( 'sp_membership_billing_user', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="billing_user">
		<h3 class="sp-h3"><?php esc_html_e( 'Billing contact', 'southpoll-membership' ); ?></h3>
		<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Receipts and renewal emails also go to this person.', 'southpoll-membership' ); ?></p>
		<div class="sp-cluster">
			<select class="sp-select" name="billing_user_id" style="max-width:360px">
				<option value="0"><?php esc_html_e( 'Owner only', 'southpoll-membership' ); ?></option>
				<?php foreach ( $farm_users as $fu ) : ?><?php if ( 'owner' === $fu->role ) { continue; } ?><option value="<?php echo esc_attr( (string) $fu->user_id ); ?>"<?php selected( (int) ( $context->farm->billing_user_id ?? 0 ), (int) $fu->user_id ); ?>><?php echo esc_html( $fu->display_name . ' (' . $fu->email . ')' ); ?></option><?php endforeach; ?>
			</select>
			<button class="sp-btn sp-btn--outline" type="submit"><?php esc_html_e( 'Save', 'southpoll-membership' ); ?></button>
		</div>
	</form>
	<?php endif; ?>

	<div class="sp-card">
		<h3 class="sp-h3"><?php esc_html_e( 'Payment history', 'southpoll-membership' ); ?></h3>
		<?php if ( empty( $history ) ) : ?>
			<p class="sp-text-muted"><?php esc_html_e( 'No payments yet.', 'southpoll-membership' ); ?></p>
		<?php else : ?>
			<div class="sp-table-wrap"><table class="sp-table">
				<thead><tr><th><?php esc_html_e( 'Date', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Amount', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Method', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Term', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Status', 'southpoll-membership' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $history as $p ) : ?>
					<tr>
						<td><?php echo esc_html( Dates::display( $p->paid_at ?: $p->created_at ) ); ?></td>
						<td><?php echo esc_html( Str::money( (int) $p->amount_cents, (string) $p->currency ) ); ?></td>
						<td><?php echo esc_html( PaymentRepository::method_label( (string) $p->method ) ); ?><?php if ( (int) $p->recurring ) : ?> <span class="sp-text-muted sp-text-small"><?php esc_html_e( '(auto)', 'southpoll-membership' ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( $p->term_start ? Dates::display( $p->term_start ) . ' – ' . Dates::display( $p->term_end ) : '—' ); ?></td>
						<td><span class="sp-badge sp-badge--<?php echo esc_attr( match ( (string) $p->status ) { 'succeeded' => 'success', 'pending' => 'warning', 'refunded', 'failed', 'rejected' => 'danger', default => 'muted' } ); ?>"><?php echo esc_html( PaymentRepository::status_label( (string) $p->status ) ); ?></span></td>
						<td class="sp-table__actions"><?php if ( ! empty( $p->receipt_url ) ) : ?><a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( (string) $p->receipt_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Receipt', 'southpoll-membership' ); ?></a><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
	</div>
<?php endif; ?>
<style>
	.sp-list { margin: 0 0 8px; padding-left: 18px; line-height: 1.7; }
	.sp-mm__price { font-family: var(--sp-font-heading); font-size: 1.4rem; color: var(--sp-maroon); }
	.sp-mm__claim { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--sp-border-light); }
	.sp-mm .sp-card { margin-bottom: 0; }
	.sp-mm { margin-bottom: var(--sp-gap); }
</style>
