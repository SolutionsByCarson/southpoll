<?php
/**
 * Admin drawer: one farm. Rendered by AdminFarmsController::detail(). Forms use the data-sp-* protocol (sp-admin.js).
 *
 * @var array<string, mixed> $f
 * @package SouthPoll\Membership
 */

defined( 'ABSPATH' ) || exit;

$farm  = $f['farm'];
$card  = $f['card'];
$ms    = $f['membership'];
$badge = static fn( string $s ): string => 'sp-badge sp-badge--' . match ( $s ) {
	'active', 'public', 'succeeded' => 'success',
	'expiring', 'pending', 'received' => 'warning',
	'expired', 'cancelled', 'inactive', 'failed', 'rejected' => 'danger',
	default => 'muted',
};
$vis_label = match ( $f['visibility'] ) {
	'public'    => __( 'Public', 'southpoll-membership' ),
	'inactive'  => __( 'Hidden by staff', 'southpoll-membership' ),
	'expired'   => __( 'Hidden: membership expired', 'southpoll-membership' ),
	'pending'   => __( 'Hidden: payment pending', 'southpoll-membership' ),
	'cancelled' => __( 'Hidden: membership cancelled', 'southpoll-membership' ),
	default     => __( 'Hidden: no membership', 'southpoll-membership' ),
};
$accepted = array_values( array_filter( $f['users'], static fn( $u ) => ! $u['pending'] ) );
$memberships_url = admin_url( 'admin.php?page=southpoll-memberships&farm=' . (int) $f['id'] );
?>
<div class="sp-drawer__head">
	<?php if ( $card['logo'] ) : ?><img class="sp-drawer__logo" src="<?php echo esc_url( $card['logo'] ); ?>" alt=""><?php else : ?><div class="sp-drawer__avatar" aria-hidden="true"><?php echo esc_html( $card['initials'] ); ?></div><?php endif; ?>
	<div class="sp-drawer__headtext">
		<h2 class="sp-drawer__title"><?php echo esc_html( $farm['name'] ); ?></h2>
		<div class="sp-drawer__sub"><span class="<?php echo esc_attr( $badge( $f['visibility'] ) ); ?>"><?php echo esc_html( $vis_label ); ?></span>
			<?php if ( $ms ) : ?><span class="<?php echo esc_attr( $badge( $ms['status'] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: status */ __( 'Membership: %s', 'southpoll-membership' ), $ms['status'] ) ); ?></span><?php endif; ?>
			<a href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Public page', 'southpoll-membership' ); ?></a>
		</div>
		<div class="sp-text-small sp-text-muted">#<?php echo (int) $f['id']; ?> · <?php echo esc_html( $farm['owner_name'] ); ?> · <?php echo esc_html( $card['location'] ?: __( 'no address', 'southpoll-membership' ) ); ?><?php echo $farm['breeder_number'] ? esc_html( ' · ' . sprintf( /* translators: %s: number */ __( 'Breeder #%s', 'southpoll-membership' ), $farm['breeder_number'] ) ) : ''; ?> · <?php echo $f['geocoded'] ? esc_html__( 'on the map', 'southpoll-membership' ) : esc_html__( 'no map pin', 'southpoll-membership' ); ?></div>
	</div>
</div>

<nav class="sp-drawer__tabs" role="tablist">
	<button type="button" class="sp-drawer__tab is-active" data-sp-tab="overview"><?php esc_html_e( 'Overview', 'southpoll-membership' ); ?></button>
	<button type="button" class="sp-drawer__tab" data-sp-tab="edit"><?php esc_html_e( 'Edit', 'southpoll-membership' ); ?></button>
	<button type="button" class="sp-drawer__tab" data-sp-tab="people"><?php esc_html_e( 'People', 'southpoll-membership' ); ?> (<?php echo count( $f['users'] ); ?>)</button>
	<button type="button" class="sp-drawer__tab" data-sp-tab="membership"><?php esc_html_e( 'Membership', 'southpoll-membership' ); ?></button>
</nav>

<section class="sp-drawer__panel" data-sp-panel="overview">
	<dl class="sp-dl">
		<dt><?php esc_html_e( 'Listings', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: live, 2: draft, 3: expired */ __( '%1$d live · %2$d draft · %3$d expired', 'southpoll-membership' ), $f['listings']['live'], $f['listings']['draft'], $f['listings']['expired'] ) ); ?> · <a href="<?php echo esc_url( $f['listings']['admin_url'] ); ?>"><?php esc_html_e( 'open', 'southpoll-membership' ); ?></a></dd>
		<dt><?php esc_html_e( 'Offerings', 'southpoll-membership' ); ?></dt><dd><?php echo $f['offerings'] ? esc_html( implode( ', ', $f['offerings'] ) ) : '<span class="sp-text-muted">' . esc_html__( 'none selected', 'southpoll-membership' ) . '</span>'; ?></dd>
		<dt><?php esc_html_e( 'Contact', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $farm['phone'] ); ?><?php echo $farm['email'] ? ' · <a href="mailto:' . esc_attr( $farm['email'] ) . '">' . esc_html( $farm['email'] ) . '</a>' : ''; ?><?php echo $farm['website'] ? ' · <a href="' . esc_url( $farm['website'] ) . '" target="_blank" rel="noopener">' . esc_html( $farm['website'] ) . '</a>' : ''; ?><?php echo (int) $farm['contact_form_enabled'] ? '' : ' · <span class="sp-badge sp-badge--muted">' . esc_html__( 'contact form off', 'southpoll-membership' ) . '</span>'; ?></dd>
		<dt><?php esc_html_e( 'Address', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( trim( $farm['address1'] . ' ' . $card['location'] . ' ' . $farm['zip'] ) ?: '—' ); ?></dd>
		<?php foreach ( $f['meta'] as $row ) : ?><dt><?php echo esc_html( $row['label'] ); ?></dt><dd><?php echo esc_html( $row['value'] ); ?></dd><?php endforeach; ?>
	</dl>
	<div class="sp-drawer__actions">
		<form data-sp-action="geocode"><button class="sp-btn sp-btn--outline sp-btn--sm"><?php echo $f['geocoded'] ? esc_html__( 'Re-geocode address', 'southpoll-membership' ) : esc_html__( 'Geocode address', 'southpoll-membership' ); ?></button></form>
		<form data-sp-action="refresh_stats"><button class="sp-btn sp-btn--ghost sp-btn--sm"><?php esc_html_e( 'Recount listings', 'southpoll-membership' ); ?></button></form>
		<a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=southpoll-users&farm_id=' . (int) $f['id'] ) ); ?>"><?php esc_html_e( 'Users of this farm', 'southpoll-membership' ); ?></a>
	</div>
</section>

<section class="sp-drawer__panel" data-sp-panel="edit" hidden>
	<form data-sp-patch class="sp-drawer__form">
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Farm name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></span><input class="sp-input" name="name" value="<?php echo esc_attr( $farm['name'] ); ?>" required></label>
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Slogan', 'southpoll-membership' ); ?></span><input class="sp-input" name="slogan" value="<?php echo esc_attr( $farm['slogan'] ); ?>" maxlength="255"></label>
		<div class="sp-form__row sp-form__row--2">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Owner / contact name', 'southpoll-membership' ); ?></span><input class="sp-input" name="owner_name" value="<?php echo esc_attr( $farm['owner_name'] ); ?>"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Breeder number', 'southpoll-membership' ); ?></span><input class="sp-input" name="breeder_number" value="<?php echo esc_attr( $farm['breeder_number'] ); ?>"></label>
		</div>
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Street address', 'southpoll-membership' ); ?></span><input class="sp-input" name="address1" value="<?php echo esc_attr( $farm['address1'] ); ?>"></label>
		<div class="sp-form__row sp-form__row--3">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'City', 'southpoll-membership' ); ?></span><input class="sp-input" name="city" value="<?php echo esc_attr( $farm['city'] ); ?>"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'State', 'southpoll-membership' ); ?></span><select class="sp-select" name="state"><option value="">—</option><?php foreach ( $f['states'] as $code => $sname ) : ?><option value="<?php echo esc_attr( $code ); ?>"<?php selected( $code, $farm['state'] ); ?>><?php echo esc_html( $sname ); ?></option><?php endforeach; ?></select></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'ZIP', 'southpoll-membership' ); ?></span><input class="sp-input" name="zip" value="<?php echo esc_attr( $farm['zip'] ); ?>"></label>
		</div>
		<div class="sp-form__row sp-form__row--3">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Phone', 'southpoll-membership' ); ?></span><input class="sp-input" name="phone" value="<?php echo esc_attr( $farm['phone'] ); ?>"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Email', 'southpoll-membership' ); ?></span><input class="sp-input" type="email" name="email" value="<?php echo esc_attr( $farm['email'] ); ?>"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Website', 'southpoll-membership' ); ?></span><input class="sp-input" name="website" value="<?php echo esc_attr( $farm['website'] ); ?>"></label>
		</div>
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'About (HTML allowed)', 'southpoll-membership' ); ?></span><textarea class="sp-textarea" name="about" rows="6"><?php echo esc_textarea( (string) $farm['about'] ); ?></textarea></label>
		<fieldset class="sp-field"><legend class="sp-label"><?php esc_html_e( 'Offerings', 'southpoll-membership' ); ?></legend>
			<div class="sp-offerings-grid"><?php foreach ( $f['all_offerings'] as $o ) : ?><label class="sp-check"><input type="checkbox" name="offerings[]" value="<?php echo (int) $o['id']; ?>"<?php checked( in_array( (int) $o['id'], $f['offering_ids'], true ) ); ?>> <span><?php echo esc_html( $o['name'] ); ?></span></label><?php endforeach; ?></div>
		</fieldset>
		<div class="sp-form__row sp-form__row--2">
			<label class="sp-check"><input type="checkbox" name="contact_form_enabled" value="1"<?php checked( (int) $farm['contact_form_enabled'] ); ?>> <span><?php esc_html_e( 'Contact form on the farm page and listings', 'southpoll-membership' ); ?></span></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Farm status', 'southpoll-membership' ); ?></span><select class="sp-select" name="status"><option value="active"<?php selected( 'active', $farm['status'] ); ?>><?php esc_html_e( 'Active', 'southpoll-membership' ); ?></option><option value="inactive"<?php selected( 'inactive', $farm['status'] ); ?>><?php esc_html_e( 'Inactive (hidden regardless of membership)', 'southpoll-membership' ); ?></option></select></label>
		</div>
		<p class="sp-help"><?php esc_html_e( 'Logo, banner, gallery, hours and social links are edited by the owner on My Farm. Changing the address re-geocodes the pin when a key is configured.', 'southpoll-membership' ); ?></p>
		<div class="sp-form__actions"><button class="sp-btn sp-btn--primary sp-btn--sm"><?php esc_html_e( 'Save farm', 'southpoll-membership' ); ?></button></div>
	</form>
</section>

<section class="sp-drawer__panel" data-sp-panel="people" hidden>
	<ul class="sp-drawer__people">
		<?php foreach ( $f['users'] as $u ) : ?>
			<li><span class="sp-badge sp-badge--role"><?php echo esc_html( 'owner' === $u['role'] ? __( 'Owner', 'southpoll-membership' ) : __( 'Manager', 'southpoll-membership' ) ); ?></span>
				<?php if ( $u['user_id'] ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=southpoll-users&user=' . (int) $u['user_id'] ) ); ?>"><?php echo esc_html( $u['name'] ?: $u['email'] ); ?></a><?php else : ?><span><?php echo esc_html( $u['email'] ); ?></span><?php endif; ?>
				<span class="sp-text-small sp-text-muted"><?php echo esc_html( $u['email'] ); ?></span>
				<?php if ( $u['pending'] ) : ?><span class="sp-badge sp-badge--warning"><?php esc_html_e( 'invited, not accepted', 'southpoll-membership' ); ?></span><?php endif; ?>
				<?php if ( $u['user_id'] && (int) $u['user_id'] === (int) $farm['billing_user_id'] ) : ?><span class="sp-badge sp-badge--muted"><?php esc_html_e( 'billing contact', 'southpoll-membership' ); ?></span><?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<form data-sp-patch class="sp-drawer__form sp-drawer__box" data-sp-confirm="<?php esc_attr_e( 'Transfer ownership of this farm? The new owner must already have an account; the current owner is demoted as chosen.', 'southpoll-membership' ); ?>">
		<h3 class="sp-drawer__h"><?php esc_html_e( 'Transfer ownership', 'southpoll-membership' ); ?></h3>
		<div class="sp-form__row sp-form__row--2">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'New owner user ID', 'southpoll-membership' ); ?></span><input class="sp-input sp-input--short" type="number" name="owner_user_id" min="1" required></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Current owner becomes', 'southpoll-membership' ); ?></span><select class="sp-select" name="old_owner_becomes"><option value="manager"><?php esc_html_e( 'Account manager', 'southpoll-membership' ); ?></option><option value="buyer"><?php esc_html_e( 'Buyer (removed from the farm)', 'southpoll-membership' ); ?></option></select></label>
		</div>
		<p class="sp-help"><?php esc_html_e( 'User IDs are shown in each user’s drawer (#). Owners cannot be deleted while they own a farm.', 'southpoll-membership' ); ?></p>
		<button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Transfer ownership', 'southpoll-membership' ); ?></button>
	</form>
	<form data-sp-patch class="sp-drawer__form sp-drawer__box">
		<h3 class="sp-drawer__h"><?php esc_html_e( 'Billing contact', 'southpoll-membership' ); ?></h3>
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Receives renewal and receipt emails', 'southpoll-membership' ); ?></span>
			<select class="sp-select" name="billing_user_id"><option value="0"><?php esc_html_e( 'Owner (default)', 'southpoll-membership' ); ?></option><?php foreach ( $accepted as $u ) : ?><?php if ( $u['user_id'] ) : ?><option value="<?php echo (int) $u['user_id']; ?>"<?php selected( (int) $u['user_id'], (int) $farm['billing_user_id'] ); ?>><?php echo esc_html( ( $u['name'] ?: $u['email'] ) . ' (' . $u['role'] . ')' ); ?></option><?php endif; ?><?php endforeach; ?></select>
		</label>
		<button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Save billing contact', 'southpoll-membership' ); ?></button>
	</form>
	<p class="sp-help"><?php esc_html_e( 'Managers are invited by the owner from My Farm, or attached from a user’s drawer (Farm & role).', 'southpoll-membership' ); ?></p>
</section>

<section class="sp-drawer__panel" data-sp-panel="membership" hidden>
	<?php if ( $ms ) : ?>
		<dl class="sp-dl">
			<dt><?php esc_html_e( 'Status', 'southpoll-membership' ); ?></dt><dd><span class="<?php echo esc_attr( $badge( $ms['status'] ) ); ?>"><?php echo esc_html( ucfirst( $ms['status'] ) ); ?></span></dd>
			<dt><?php esc_html_e( 'Term', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $ms['ends_at'] ? $ms['starts_at'] . ' – ' . $ms['ends_at'] : __( 'No term yet', 'southpoll-membership' ) ); ?></dd>
			<dt><?php esc_html_e( 'Auto-renew', 'southpoll-membership' ); ?></dt><dd><?php echo $ms['auto_renew'] ? esc_html__( 'On', 'southpoll-membership' ) : esc_html__( 'Off', 'southpoll-membership' ); ?> · <?php echo esc_html( $ms['source'] ); ?><?php echo $ms['price_paid'] ? esc_html( ' · ' . $ms['price_paid'] ) : ''; ?></dd>
			<?php if ( $ms['stripe_customer_id'] ) : ?><dt><?php esc_html_e( 'Stripe', 'southpoll-membership' ); ?></dt><dd><code><?php echo esc_html( $ms['stripe_customer_id'] ); ?></code> <code><?php echo esc_html( $ms['stripe_subscription_id'] ); ?></code></dd><?php endif; ?>
		</dl>
		<p><a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( $memberships_url ); ?>"><?php esc_html_e( 'Manage membership (extend, record payment, cancel)', 'southpoll-membership' ); ?></a></p>
		<?php if ( $f['payments'] ) : ?>
			<h3 class="sp-drawer__h"><?php esc_html_e( 'Recent payments', 'southpoll-membership' ); ?></h3>
			<table class="sp-table sp-table--compact"><tbody><?php foreach ( $f['payments'] as $pay ) : ?><tr><td><?php echo esc_html( $pay['paid_at'] ); ?></td><td><?php echo esc_html( $pay['amount'] ); ?></td><td><?php echo esc_html( $pay['method'] ); ?></td><td><span class="<?php echo esc_attr( $badge( $pay['status'] ) ); ?>"><?php echo esc_html( $pay['status'] ); ?></span></td><td><?php echo esc_html( $pay['term'] ); ?></td></tr><?php endforeach; ?></tbody></table>
		<?php endif; ?>
		<?php if ( $ms['notes'] ) : ?><h3 class="sp-drawer__h"><?php esc_html_e( 'Notes', 'southpoll-membership' ); ?></h3><pre class="sp-drawer__notes"><?php echo esc_html( $ms['notes'] ); ?></pre><?php endif; ?>
	<?php else : ?>
		<p class="sp-text-muted"><?php esc_html_e( 'No membership record yet (the farm has never paid or been activated).', 'southpoll-membership' ); ?></p>
		<p><a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( $memberships_url ); ?>"><?php esc_html_e( 'Activate or record a payment', 'southpoll-membership' ); ?></a></p>
	<?php endif; ?>
</section>
