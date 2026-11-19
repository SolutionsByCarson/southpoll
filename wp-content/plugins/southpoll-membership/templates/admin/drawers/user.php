<?php
/**
 * Admin drawer: one user. Rendered by AdminUsersController::detail() and swapped in by spAdminTable.
 * Forms use the data-sp-* protocol (sp-admin.js): data-sp-action → POST …/action, data-sp-patch → PATCH.
 *
 * @var array<string, mixed>  $u       AdminUsersController::detail()
 * @var array<string, string> $states  code => name
 * @package SouthPoll\Membership
 */

defined( 'ABSPATH' ) || exit;

$p     = $u['profile'];
$badge = static fn( string $s ): string => 'sp-badge sp-badge--' . match ( $s ) {
	'active', 'public', 'live' => 'success',
	'expiring', 'pending' => 'warning',
	'expired', 'cancelled', 'suspended' => 'danger',
	default => 'muted',
};
$yes   = static fn( bool $b ): string => $b ? __( 'on', 'southpoll-membership' ) : __( 'off', 'southpoll-membership' );
?>
<div class="sp-drawer__head">
	<div class="sp-drawer__avatar" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( (string) ( $u['name'] ?: $u['email'] ), 0, 1 ) ) ); ?></div>
	<div class="sp-drawer__headtext">
		<h2 class="sp-drawer__title"><?php echo esc_html( $u['name'] ?: $u['email'] ); ?></h2>
		<div class="sp-drawer__sub"><a href="mailto:<?php echo esc_attr( $u['email'] ); ?>"><?php echo esc_html( $u['email'] ); ?></a> <span class="sp-badge sp-badge--role"><?php echo esc_html( $u['role_label'] ); ?></span>
			<?php if ( $u['suspended'] ) : ?><span class="sp-badge sp-badge--danger"><?php esc_html_e( 'Suspended', 'southpoll-membership' ); ?></span><?php elseif ( $p && ! $u['verified'] ) : ?><span class="sp-badge sp-badge--warning"><?php esc_html_e( 'Unverified', 'southpoll-membership' ); ?></span><?php elseif ( $p ) : ?><span class="sp-badge sp-badge--success"><?php esc_html_e( 'Verified', 'southpoll-membership' ); ?></span><?php endif; ?>
		</div>
		<div class="sp-text-small sp-text-muted">#<?php echo (int) $u['id']; ?> · <?php echo esc_html( $u['login'] ); ?> · <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'joined %s', 'southpoll-membership' ), $u['created'] ) ); ?><?php echo $u['last_seen'] ? esc_html( ' · ' . sprintf( /* translators: %s: date */ __( 'last seen %s', 'southpoll-membership' ), $u['last_seen'] ) ) : ''; ?> · <a href="<?php echo esc_url( $u['edit_url'] ); ?>"><?php esc_html_e( 'WordPress profile', 'southpoll-membership' ); ?></a></div>
	</div>
</div>

<nav class="sp-drawer__tabs" role="tablist">
	<button type="button" class="sp-drawer__tab is-active" data-sp-tab="overview"><?php esc_html_e( 'Overview', 'southpoll-membership' ); ?></button>
	<button type="button" class="sp-drawer__tab" data-sp-tab="profile"><?php esc_html_e( 'Profile', 'southpoll-membership' ); ?></button>
	<button type="button" class="sp-drawer__tab" data-sp-tab="farm"><?php esc_html_e( 'Farm & role', 'southpoll-membership' ); ?></button>
</nav>

<section class="sp-drawer__panel" data-sp-panel="overview">
	<?php if ( $u['pending_email'] ) : ?><div class="sp-alert sp-alert--info"><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Email change to %s is waiting for confirmation.', 'southpoll-membership' ), $u['pending_email'] ) ); ?></div><?php endif; ?>
	<?php if ( ! $p ) : ?><div class="sp-alert sp-alert--info"><?php esc_html_e( 'This WordPress account has no member profile (staff or legacy user). Saving the Profile tab creates one.', 'southpoll-membership' ); ?></div><?php endif; ?>
	<h3 class="sp-drawer__h"><?php esc_html_e( 'Account actions', 'southpoll-membership' ); ?></h3>
	<div class="sp-drawer__actions">
		<form data-sp-action="reset_password" data-sp-confirm="<?php echo esc_attr( sprintf( /* translators: %s: email */ __( 'Email a password reset link to %s?', 'southpoll-membership' ), $u['email'] ) ); ?>"><button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Send password reset', 'southpoll-membership' ); ?></button></form>
		<?php if ( $p && ! $u['verified'] ) : ?>
			<form data-sp-action="resend_verification"><button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Resend verification', 'southpoll-membership' ); ?></button></form>
			<form data-sp-action="verify" data-sp-confirm="<?php esc_attr_e( 'Mark this email as verified without the member clicking the link?', 'southpoll-membership' ); ?>"><button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Mark verified', 'southpoll-membership' ); ?></button></form>
		<?php endif; ?>
		<?php if ( $u['suspended'] ) : ?>
			<form data-sp-action="unsuspend"><button class="sp-btn sp-btn--secondary sp-btn--sm"><?php esc_html_e( 'Reinstate account', 'southpoll-membership' ); ?></button></form>
		<?php elseif ( 'staff' !== $u['role'] ) : ?>
			<form data-sp-action="suspend" data-sp-confirm="<?php esc_attr_e( 'Suspend this account? It can still log in but cannot see contact details, favorite, save searches or edit its farm and listings. No email is sent.', 'southpoll-membership' ); ?>" data-sp-danger><button class="sp-btn sp-btn--outline sp-btn--sm sp-admin__danger"><?php esc_html_e( 'Suspend', 'southpoll-membership' ); ?></button></form>
		<?php endif; ?>
		<?php if ( $u['can_delete'] ) : ?>
			<form data-sp-action="delete" data-sp-confirm="<?php esc_attr_e( 'Permanently delete this account with its favorites, saved searches and alerts? This cannot be undone.', 'southpoll-membership' ); ?>" data-sp-danger data-sp-after="close"><button class="sp-btn sp-btn--danger sp-btn--sm"><?php esc_html_e( 'Delete account', 'southpoll-membership' ); ?></button></form>
		<?php endif; ?>
	</div>

	<?php if ( $u['farm'] ) : ?>
		<h3 class="sp-drawer__h"><?php esc_html_e( 'Membership', 'southpoll-membership' ); ?></h3>
		<dl class="sp-dl">
			<dt><?php esc_html_e( 'Farm', 'southpoll-membership' ); ?></dt><dd><a href="<?php echo esc_url( $u['farm']['admin_url'] ); ?>"><?php echo esc_html( $u['farm']['name'] ); ?></a> · <a href="<?php echo esc_url( $u['farm']['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'public page', 'southpoll-membership' ); ?></a></dd>
			<?php if ( $u['membership'] ) : ?>
				<dt><?php esc_html_e( 'Status', 'southpoll-membership' ); ?></dt><dd><span class="<?php echo esc_attr( $badge( $u['membership']['status'] ) ); ?>"><?php echo esc_html( ucfirst( $u['membership']['status'] ) ); ?></span> <?php echo $u['membership']['ends_at'] ? esc_html( sprintf( /* translators: 1: start, 2: end */ __( '%1$s – %2$s', 'southpoll-membership' ), $u['membership']['starts_at'], $u['membership']['ends_at'] ) ) : ''; ?></dd>
				<dt><?php esc_html_e( 'Auto-renew', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $yes( $u['membership']['auto_renew'] ) . ' · ' . $u['membership']['source'] ); ?> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=southpoll-memberships&farm=' . (int) $u['farm']['id'] ) ); ?>"><?php esc_html_e( 'manage', 'southpoll-membership' ); ?></a></dd>
			<?php endif; ?>
		</dl>
	<?php endif; ?>

	<?php if ( $u['payments'] ) : ?>
		<h3 class="sp-drawer__h"><?php esc_html_e( 'Recent payments', 'southpoll-membership' ); ?></h3>
		<table class="sp-table sp-table--compact"><tbody>
			<?php foreach ( $u['payments'] as $pay ) : ?><tr><td><?php echo esc_html( $pay['paid_at'] ); ?></td><td><?php echo esc_html( $pay['amount'] ); ?></td><td><?php echo esc_html( $pay['method'] ); ?></td><td><span class="<?php echo esc_attr( $badge( 'succeeded' === $pay['status'] ? 'active' : ( 'failed' === $pay['status'] || 'rejected' === $pay['status'] ? 'expired' : 'pending' ) ) ); ?>"><?php echo esc_html( $pay['status'] ); ?></span></td></tr><?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>

	<h3 class="sp-drawer__h"><?php esc_html_e( 'Activity', 'southpoll-membership' ); ?></h3>
	<dl class="sp-dl sp-dl--inline">
		<dt><?php esc_html_e( 'Favorite farms', 'southpoll-membership' ); ?></dt><dd><?php echo (int) $u['counts']['favorite_farms']; ?></dd>
		<dt><?php esc_html_e( 'Favorite listings', 'southpoll-membership' ); ?></dt><dd><?php echo (int) $u['counts']['favorite_listings']; ?></dd>
		<dt><?php esc_html_e( 'Saved searches', 'southpoll-membership' ); ?></dt><dd><?php echo (int) $u['counts']['saved_searches']; ?></dd>
		<dt><?php esc_html_e( 'Listings authored', 'southpoll-membership' ); ?></dt><dd><?php echo (int) $u['counts']['listings_authored']; ?></dd>
		<dt><?php esc_html_e( 'Inquiries sent', 'southpoll-membership' ); ?></dt><dd><?php echo (int) $u['counts']['inquiries_sent']; ?></dd>
	</dl>
</section>

<section class="sp-drawer__panel" data-sp-panel="profile" hidden>
	<form data-sp-patch class="sp-drawer__form">
		<div class="sp-form__row sp-form__row--2">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'First name', 'southpoll-membership' ); ?></span><input class="sp-input" name="first_name" value="<?php echo esc_attr( $p['first_name'] ?? '' ); ?>"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Last name', 'southpoll-membership' ); ?></span><input class="sp-input" name="last_name" value="<?php echo esc_attr( $p['last_name'] ?? '' ); ?>"></label>
		</div>
		<div class="sp-form__row sp-form__row--2">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Email (login)', 'southpoll-membership' ); ?></span><input class="sp-input" type="email" name="email" value="<?php echo esc_attr( $u['email'] ); ?>"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Phone', 'southpoll-membership' ); ?></span><input class="sp-input" name="phone" value="<?php echo esc_attr( $p['phone'] ?? '' ); ?>"></label>
		</div>
		<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Address', 'southpoll-membership' ); ?></span><input class="sp-input" name="address1" value="<?php echo esc_attr( $p['address1'] ?? '' ); ?>"></label>
		<div class="sp-form__row sp-form__row--3">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'City', 'southpoll-membership' ); ?></span><input class="sp-input" name="city" value="<?php echo esc_attr( $p['city'] ?? '' ); ?>"></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'State', 'southpoll-membership' ); ?></span><select class="sp-select" name="state"><option value=""><?php esc_html_e( '—', 'southpoll-membership' ); ?></option><?php foreach ( $states as $code => $sname ) : ?><option value="<?php echo esc_attr( $code ); ?>"<?php selected( $code, $p['state'] ?? '' ); ?>><?php echo esc_html( $sname ); ?></option><?php endforeach; ?></select></label>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'ZIP', 'southpoll-membership' ); ?></span><input class="sp-input" name="zip" value="<?php echo esc_attr( $p['zip'] ?? '' ); ?>"></label>
		</div>
		<div class="sp-cluster">
			<label class="sp-check"><input type="checkbox" name="notify_all" value="1"<?php checked( $p['notify_all'] ?? true ); ?>> <span><?php esc_html_e( 'Receives alert emails', 'southpoll-membership' ); ?></span></label>
			<label class="sp-check"><input type="checkbox" name="default_notify_favorites" value="1"<?php checked( $p['default_notify_favorites'] ?? false ); ?>> <span><?php esc_html_e( 'New favorites alert by default', 'southpoll-membership' ); ?></span></label>
			<label class="sp-check"><input type="checkbox" name="default_notify_searches" value="1"<?php checked( $p['default_notify_searches'] ?? false ); ?>> <span><?php esc_html_e( 'New saved searches alert by default', 'southpoll-membership' ); ?></span></label>
		</div>
		<div class="sp-form__actions"><button class="sp-btn sp-btn--primary sp-btn--sm"><?php esc_html_e( 'Save profile', 'southpoll-membership' ); ?></button></div>
	</form>
</section>

<section class="sp-drawer__panel" data-sp-panel="farm" hidden>
	<?php if ( $u['farm'] ) : ?>
		<p><?php echo esc_html( $u['role_label'] ); ?> · <a href="<?php echo esc_url( $u['farm']['admin_url'] ); ?>"><?php echo esc_html( $u['farm']['name'] ); ?></a></p>
		<dl class="sp-dl sp-dl--inline">
			<dt><?php esc_html_e( 'Listing emails', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $yes( $u['farm']['notify_listings'] ) ); ?></dd>
			<dt><?php esc_html_e( 'Membership emails', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $yes( $u['farm']['notify_membership'] ) ); ?></dd>
		</dl>
		<?php if ( 'manager' === $u['farm']['role'] ) : ?>
			<form data-sp-action="make_owner" class="sp-drawer__form" data-sp-confirm="<?php echo esc_attr( sprintf( /* translators: %s: farm */ __( 'Transfer ownership of %s to this account?', 'southpoll-membership' ), $u['farm']['name'] ) ); ?>">
				<input type="hidden" name="farm_id" value="<?php echo (int) $u['farm']['id']; ?>">
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Current owner becomes', 'southpoll-membership' ); ?></span><select class="sp-select" name="old_owner_becomes"><option value="manager"><?php esc_html_e( 'Account manager', 'southpoll-membership' ); ?></option><option value="buyer"><?php esc_html_e( 'Buyer (removed from the farm)', 'southpoll-membership' ); ?></option></select></label>
				<button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Make farm owner', 'southpoll-membership' ); ?></button>
			</form>
			<form data-sp-action="detach" data-sp-confirm="<?php esc_attr_e( 'Remove this account from the farm? It becomes a buyer account.', 'southpoll-membership' ); ?>" data-sp-danger><button class="sp-btn sp-btn--outline sp-btn--sm sp-admin__danger"><?php esc_html_e( 'Remove from farm', 'southpoll-membership' ); ?></button></form>
		<?php else : ?>
			<p class="sp-help"><?php esc_html_e( 'Owners are changed from the farm record (Ownership section), which also protects the farm from deletion.', 'southpoll-membership' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<?php if ( $u['pending_invite'] ) : ?><div class="sp-alert sp-alert--info"><?php echo esc_html( sprintf( /* translators: 1: farm, 2: date */ __( 'Invited to %1$s on %2$s; not accepted yet.', 'southpoll-membership' ), $u['pending_invite']['farm'], $u['pending_invite']['invited_at'] ) ); ?></div><?php endif; ?>
		<?php if ( 'staff' === $u['role'] ) : ?><p class="sp-help"><?php esc_html_e( 'Staff accounts manage every farm from here; attach one only if this person also owns a farm.', 'southpoll-membership' ); ?></p><?php endif; ?>
		<form data-sp-action="attach_manager" class="sp-drawer__form">
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Farm ID', 'southpoll-membership' ); ?></span><input class="sp-input sp-input--short" type="number" name="farm_id" min="1" required></label>
			<button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Attach as account manager', 'southpoll-membership' ); ?></button>
			<p class="sp-help"><?php esc_html_e( 'Farm IDs are shown under each name on the Farms screen. A pending invitation for this email is replaced.', 'southpoll-membership' ); ?></p>
		</form>
		<form data-sp-action="make_owner" class="sp-drawer__form" data-sp-confirm="<?php esc_attr_e( 'Make this account the owner of that farm? The current owner is demoted as chosen.', 'southpoll-membership' ); ?>">
			<div class="sp-form__row sp-form__row--2">
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Farm ID', 'southpoll-membership' ); ?></span><input class="sp-input sp-input--short" type="number" name="farm_id" min="1" required></label>
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Current owner becomes', 'southpoll-membership' ); ?></span><select class="sp-select" name="old_owner_becomes"><option value="manager"><?php esc_html_e( 'Account manager', 'southpoll-membership' ); ?></option><option value="buyer"><?php esc_html_e( 'Buyer (removed from the farm)', 'southpoll-membership' ); ?></option></select></label>
			</div>
			<button class="sp-btn sp-btn--outline sp-btn--sm"><?php esc_html_e( 'Make farm owner', 'southpoll-membership' ); ?></button>
		</form>
	<?php endif; ?>
</section>
