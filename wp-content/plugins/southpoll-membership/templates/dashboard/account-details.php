<?php
/**
 * Account details: profile, email, password, notification preferences.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \WP_User $user
 * @var object   $member
 * @var WP_Error $errors
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array    $states
 * @var object|null $farm
 */

defined( 'ABSPATH' ) || exit;

$sp_err = static fn( string $code ): string => $errors->get_error_message( $code ) ? '<div class="sp-error">' . esc_html( (string) $errors->get_error_message( $code ) ) . '</div>' : '';
$sp_cls = static fn( string $code ): string => $errors->get_error_message( $code ) ? ' sp-field--error' : '';
?>
<?php if ( $errors->has_errors() ) : ?>
	<div class="sp-alert sp-alert--error" role="alert"><span class="sp-alert__title"><?php esc_html_e( 'Your changes were not saved', 'southpoll-membership' ); ?></span><?php echo esc_html( (string) $errors->get_error_message() ); ?></div>
<?php endif; ?>

<div class="sp-grid sp-grid--2 sp-account">
	<form method="post" class="sp-card sp-form">
		<?php wp_nonce_field( 'sp_account_profile', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="profile">
		<h2 class="sp-card__title"><?php esc_html_e( 'Your details', 'southpoll-membership' ); ?></h2>
		<div class="sp-form__row sp-form__row--2">
			<div class="sp-field<?php echo $sp_cls( 'name' ); ?>"><label class="sp-label" for="ad-first"><?php esc_html_e( 'First name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="ad-first" class="sp-input" name="first_name" value="<?php echo esc_attr( (string) $member->first_name ); ?>" required></div>
			<div class="sp-field<?php echo $sp_cls( 'name' ); ?>"><label class="sp-label" for="ad-last"><?php esc_html_e( 'Last name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="ad-last" class="sp-input" name="last_name" value="<?php echo esc_attr( (string) $member->last_name ); ?>" required><?php echo $sp_err( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		</div>
		<div class="sp-field"><label class="sp-label" for="ad-phone"><?php esc_html_e( 'Phone', 'southpoll-membership' ); ?></label><input id="ad-phone" class="sp-input" type="tel" name="phone" value="<?php echo esc_attr( (string) $member->phone ); ?>"></div>
		<div class="sp-field"><label class="sp-label" for="ad-addr"><?php esc_html_e( 'Street address', 'southpoll-membership' ); ?></label><input id="ad-addr" class="sp-input" name="address1" value="<?php echo esc_attr( (string) $member->address1 ); ?>"></div>
		<div class="sp-form__row sp-form__row--3">
			<div class="sp-field"><label class="sp-label" for="ad-city"><?php esc_html_e( 'City', 'southpoll-membership' ); ?></label><input id="ad-city" class="sp-input" name="city" value="<?php echo esc_attr( (string) $member->city ); ?>"></div>
			<div class="sp-field"><label class="sp-label" for="ad-state"><?php esc_html_e( 'State', 'southpoll-membership' ); ?></label><select id="ad-state" class="sp-select" name="state"><option value=""><?php esc_html_e( 'Select…', 'southpoll-membership' ); ?></option><?php foreach ( $states as $abbr => $sname ) : ?><option value="<?php echo esc_attr( $abbr ); ?>"<?php selected( (string) $member->state, $abbr ); ?>><?php echo esc_html( $sname ); ?></option><?php endforeach; ?></select></div>
			<div class="sp-field"><label class="sp-label" for="ad-zip"><?php esc_html_e( 'ZIP', 'southpoll-membership' ); ?></label><input id="ad-zip" class="sp-input" name="zip" value="<?php echo esc_attr( (string) $member->zip ); ?>"></div>
		</div>
		<div class="sp-form__actions"><button class="sp-btn sp-btn--primary" type="submit"><?php esc_html_e( 'Save details', 'southpoll-membership' ); ?></button></div>
	</form>

	<div>
		<form method="post" class="sp-card sp-form">
			<?php wp_nonce_field( 'sp_account_email', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="email">
			<h2 class="sp-card__title"><?php esc_html_e( 'Email address', 'southpoll-membership' ); ?></h2>
			<p><strong><?php echo esc_html( $user->user_email ); ?></strong>
				<?php if ( $context->is_verified() ) : ?><span class="sp-badge sp-badge--success"><?php esc_html_e( 'Verified', 'southpoll-membership' ); ?></span><?php else : ?><span class="sp-badge sp-badge--warning"><?php esc_html_e( 'Not verified', 'southpoll-membership' ); ?></span><?php endif; ?>
			</p>
			<?php if ( '' !== (string) $member->pending_email ) : ?>
				<div class="sp-alert sp-alert--info"><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Waiting for you to confirm %s. Check that inbox for the verification link.', 'southpoll-membership' ), (string) $member->pending_email ) ); ?></div>
			<?php endif; ?>
			<div class="sp-field<?php echo $sp_cls( 'email' ); ?>"><label class="sp-label" for="ad-email"><?php esc_html_e( 'New email address', 'southpoll-membership' ); ?></label><input id="ad-email" class="sp-input" type="email" name="new_email" autocomplete="email"><div class="sp-help"><?php esc_html_e( 'We will send a verification link to the new address before switching.', 'southpoll-membership' ); ?></div><?php echo $sp_err( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="sp-form__actions">
				<button class="sp-btn sp-btn--outline" type="submit"><?php esc_html_e( 'Change email', 'southpoll-membership' ); ?></button>
				<?php if ( ! $context->is_verified() || '' !== (string) $member->pending_email ) : ?>
					<button class="sp-btn sp-btn--ghost" type="submit" formaction="" name="sp_action" value="resend" onclick="this.form.querySelector('[name=sp_nonce]').value='<?php echo esc_js( wp_create_nonce( 'sp_account_resend' ) ); ?>'"><?php esc_html_e( 'Resend verification', 'southpoll-membership' ); ?></button>
				<?php endif; ?>
				<?php if ( '' !== (string) $member->pending_email ) : ?>
					<button class="sp-btn sp-btn--ghost" type="submit" name="sp_action" value="cancel_email" onclick="this.form.querySelector('[name=sp_nonce]').value='<?php echo esc_js( wp_create_nonce( 'sp_account_cancel_email' ) ); ?>'"><?php esc_html_e( 'Cancel change', 'southpoll-membership' ); ?></button>
				<?php endif; ?>
			</div>
		</form>

		<form method="post" class="sp-card sp-form">
			<?php wp_nonce_field( 'sp_account_password', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="password">
			<h2 class="sp-card__title"><?php esc_html_e( 'Password', 'southpoll-membership' ); ?></h2>
			<div class="sp-field<?php echo $sp_cls( 'current_password' ); ?>"><label class="sp-label" for="ad-cur"><?php esc_html_e( 'Current password', 'southpoll-membership' ); ?></label><input id="ad-cur" class="sp-input" type="password" name="current_password" autocomplete="current-password" required><?php echo $sp_err( 'current_password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="sp-form__row sp-form__row--2">
				<div class="sp-field<?php echo $sp_cls( 'new_password' ); ?>"><label class="sp-label" for="ad-new"><?php esc_html_e( 'New password', 'southpoll-membership' ); ?></label><input id="ad-new" class="sp-input" type="password" name="new_password" autocomplete="new-password" minlength="8" required><?php echo $sp_err( 'new_password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field<?php echo $sp_cls( 'new_password_confirm' ); ?>"><label class="sp-label" for="ad-new2"><?php esc_html_e( 'Confirm', 'southpoll-membership' ); ?></label><input id="ad-new2" class="sp-input" type="password" name="new_password_confirm" autocomplete="new-password" required><?php echo $sp_err( 'new_password_confirm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
			<div class="sp-form__actions"><button class="sp-btn sp-btn--outline" type="submit"><?php esc_html_e( 'Change password', 'southpoll-membership' ); ?></button></div>
		</form>
	</div>
</div>

<form method="post" class="sp-card sp-form">
	<?php wp_nonce_field( 'sp_account_preferences', 'sp_nonce' ); ?><input type="hidden" name="sp_action" value="preferences">
	<h2 class="sp-card__title"><?php esc_html_e( 'Email notifications', 'southpoll-membership' ); ?></h2>
	<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Membership emails, receipts and renewal reminders are always sent. These control the optional ones.', 'southpoll-membership' ); ?></p>
	<div class="sp-field"><label class="sp-toggle"><input type="checkbox" name="notify_all" value="1"<?php checked( (int) $member->notify_all, 1 ); ?>><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Send me optional emails (favorites, saved searches, listing activity)', 'southpoll-membership' ); ?></span></label></div>
	<div class="sp-field"><label class="sp-toggle"><input type="checkbox" name="default_notify_favorites" value="1"<?php checked( (int) $member->default_notify_favorites, 1 ); ?>><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Automatically subscribe me to updates when I favorite a farm or listing', 'southpoll-membership' ); ?></span></label></div>
	<div class="sp-field"><label class="sp-toggle"><input type="checkbox" name="default_notify_searches" value="1"<?php checked( (int) $member->default_notify_searches, 1 ); ?>><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Automatically email me new listings for searches I save', 'southpoll-membership' ); ?></span></label></div>
	<?php if ( $farm && $context->is_owner() ) : ?>
		<div class="sp-field"><label class="sp-label"><?php esc_html_e( 'Farm page contact form', 'southpoll-membership' ); ?></label><p class="sp-text-small"><?php echo esc_html( (int) $farm->contact_form_enabled ? __( 'Visitors who are not logged in can send you a message from your farm page.', 'southpoll-membership' ) : __( 'The farm page contact form is turned off.', 'southpoll-membership' ) ); ?> <a href="<?php echo esc_url( $urls->dashboard( 'my-farm' ) ); ?>"><?php esc_html_e( 'Change this on My Farm', 'southpoll-membership' ); ?></a></p></div>
	<?php endif; ?>
	<div class="sp-form__actions"><button class="sp-btn sp-btn--primary" type="submit"><?php esc_html_e( 'Save preferences', 'southpoll-membership' ); ?></button></div>
</form>
<style>.sp-account .sp-card { margin-bottom: var(--sp-gap); }</style>
