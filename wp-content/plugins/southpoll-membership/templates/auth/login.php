<?php
/**
 * Login · Create account · Lost password (theme-wrapped).
 *
 * @var string   $tab          login|register|lost
 * @var WP_Error $errors
 * @var array    $old
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var object|null $tier
 * @var string   $price
 * @var string   $redirect_to
 * @var object|null $invite
 * @var \SouthPoll\Membership\Auth\Recaptcha $recaptcha
 * @var array    $states
 * @var \SouthPoll\Membership\Core\Settings $settings
 */

defined( 'ABSPATH' ) || exit;

use SouthPoll\Membership\Auth\Honeypot;

$sp_err   = static fn( string $code ): string => $errors->get_error_message( $code ) ? '<div class="sp-error">' . wp_kses_post( (string) $errors->get_error_message( $code ) ) . '</div>' : '';
$sp_cls   = static fn( string $code ): string => $errors->get_error_message( $code ) ? ' sp-field--error' : '';
$sp_old   = static fn( string $k, string $d = '' ): string => esc_attr( (string) ( $old[ $k ] ?? $d ) );
$general  = array_diff( $errors->get_error_codes(), [ 'first_name', 'last_name', 'email', 'password', 'password_confirm', 'farm_name', 'agree', 'user_login' ] );
$acct     = ( $old['account_type'] ?? '' ) ?: ( isset( $_GET['type'] ) && 'member' === $_GET['type'] ? 'member' : ( $invite ? 'buyer' : 'member' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="sp-auth" x-data="{ tab: '<?php echo esc_js( $tab ); ?>', acct: '<?php echo esc_js( $acct ); ?>' }">
	<header class="sp-page__header sp-auth__header">
		<h1 class="sp-page__title" x-text="tab === 'register' ? '<?php echo esc_js( __( 'Create your account', 'southpoll-membership' ) ); ?>' : (tab === 'lost' ? '<?php echo esc_js( __( 'Reset your password', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Member login', 'southpoll-membership' ) ); ?>')"><?php esc_html_e( 'Member login', 'southpoll-membership' ); ?></h1>
		<?php if ( $invite ) : ?>
			<div class="sp-alert sp-alert--info"><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'You were invited as an account manager. Log in or create an account using %s to accept.', 'southpoll-membership' ), (string) $invite->invite_email ) ); ?></div>
		<?php endif; ?>
	</header>

	<?php
	// Soft-launch release notice (Settings → Launch & feedback): shown until the optional end date (site time), S6.
	$sp_until = (string) $settings->get( 'launch_notice_until', '' );
	if ( $settings->get( 'launch_notice_enabled', false ) && ( '' === $sp_until || wp_date( 'Y-m-d' ) <= $sp_until ) ) :
		$sp_link_url   = (string) $settings->get( 'launch_notice_link_url', '' );
		$sp_link_label = (string) $settings->get( 'launch_notice_link_label', '' );
		?>
		<div class="sp-alert sp-alert--info sp-launch-notice" role="status" id="sp-launch-notice">
			<?php if ( '' !== (string) $settings->get( 'launch_notice_title', '' ) ) : ?><span class="sp-alert__title"><?php echo esc_html( (string) $settings->get( 'launch_notice_title' ) ); ?></span><?php endif; ?>
			<?php echo nl2br( esc_html( (string) $settings->get( 'launch_notice_text', '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>
			<?php if ( '' !== $sp_link_url ) : ?> <a href="<?php echo esc_url( $sp_link_url ); ?>"><?php echo esc_html( '' !== $sp_link_label ? $sp_link_label : __( 'What\'s new', 'southpoll-membership' ) ); ?></a><?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="sp-tabs" role="tablist">
		<button type="button" class="sp-tab" :class="{ 'is-active': tab === 'login' }" @click="tab = 'login'" role="tab"><?php esc_html_e( 'Log in', 'southpoll-membership' ); ?></button>
		<button type="button" class="sp-tab" :class="{ 'is-active': tab === 'register' }" @click="tab = 'register'" role="tab"><?php esc_html_e( 'Create account', 'southpoll-membership' ); ?></button>
	</div>

	<?php foreach ( $general as $code ) : ?>
		<div class="sp-alert sp-alert--error" role="alert"><span class="sp-alert__title"><?php esc_html_e( 'Something needs your attention', 'southpoll-membership' ); ?></span><?php echo wp_kses_post( (string) $errors->get_error_message( $code ) ); ?></div>
	<?php endforeach; ?>

	<div class="sp-auth__grid">
		<!-- LOGIN -->
		<form method="post" class="sp-form sp-card sp-auth__panel" x-show="tab === 'login'" x-cloak>
			<?php wp_nonce_field( 'sp_auth_login', 'sp_nonce' ); echo Honeypot::fields(); echo $recaptcha->form_assets( 'login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="sp_action" value="login">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
			<?php if ( $invite ) : ?><input type="hidden" name="invite_token" value="<?php echo esc_attr( (string) $invite->raw_token ); ?>"><?php endif; ?>
			<div class="sp-field"><label class="sp-label" for="sp-log"><?php esc_html_e( 'Email address', 'southpoll-membership' ); ?></label><input id="sp-log" class="sp-input" type="text" name="log" value="<?php echo $sp_old( 'log', $invite ? (string) $invite->invite_email : '' ); ?>" autocomplete="username" required></div>
			<div class="sp-field"><label class="sp-label" for="sp-pwd"><?php esc_html_e( 'Password', 'southpoll-membership' ); ?></label><input id="sp-pwd" class="sp-input" type="password" name="pwd" autocomplete="current-password" required></div>
			<div class="sp-field"><label class="sp-check"><input type="checkbox" name="rememberme" value="1" checked> <span><?php esc_html_e( 'Keep me logged in', 'southpoll-membership' ); ?></span></label></div>
			<div class="sp-form__actions">
				<button class="sp-btn sp-btn--primary" type="submit"><?php esc_html_e( 'Log in', 'southpoll-membership' ); ?></button>
				<button class="sp-btn sp-btn--ghost" type="button" @click="tab = 'lost'"><?php esc_html_e( 'Forgot your password?', 'southpoll-membership' ); ?></button>
			</div>
		</form>

		<!-- LOST PASSWORD -->
		<form method="post" class="sp-form sp-card sp-auth__panel" x-show="tab === 'lost'" x-cloak>
			<?php wp_nonce_field( 'sp_auth_lost', 'sp_nonce' ); echo Honeypot::fields(); echo $recaptcha->form_assets( 'lost' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="sp_action" value="lost">
			<p class="sp-text-muted"><?php esc_html_e( 'Enter the email address on your account and we will send you a link to choose a new password.', 'southpoll-membership' ); ?></p>
			<div class="sp-field<?php echo $sp_cls( 'user_login' ); ?>"><label class="sp-label" for="sp-user-login"><?php esc_html_e( 'Email address', 'southpoll-membership' ); ?></label><input id="sp-user-login" class="sp-input" type="email" name="user_login" value="<?php echo $sp_old( 'user_login' ); ?>" required><?php echo $sp_err( 'user_login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="sp-form__actions">
				<button class="sp-btn sp-btn--primary" type="submit"><?php esc_html_e( 'Send reset link', 'southpoll-membership' ); ?></button>
				<button class="sp-btn sp-btn--ghost" type="button" @click="tab = 'login'"><?php esc_html_e( 'Back to login', 'southpoll-membership' ); ?></button>
			</div>
			<p class="sp-text-small sp-text-muted"><?php echo wp_kses_post( sprintf( /* translators: %s: email */ __( 'Trouble? Contact the office at %s.', 'southpoll-membership' ), '<a href="mailto:' . esc_attr( (string) $settings->get( 'admin_contact.email' ) ) . '">' . esc_html( (string) $settings->get( 'admin_contact.email' ) ) . '</a>' ) ); ?></p>
		</form>

		<!-- REGISTER -->
		<form method="post" class="sp-form sp-card sp-auth__panel sp-auth__panel--wide" x-show="tab === 'register'" x-cloak>
			<?php wp_nonce_field( 'sp_auth_register', 'sp_nonce' ); echo Honeypot::fields(); echo $recaptcha->form_assets( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="sp_action" value="register">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
			<?php if ( $invite ) : ?><input type="hidden" name="invite_token" value="<?php echo esc_attr( (string) $invite->raw_token ); ?>"><?php endif; ?>

			<p class="sp-eyebrow"><?php esc_html_e( 'Step 1 · Choose your account', 'southpoll-membership' ); ?></p>
			<div class="sp-choice-grid">
				<label class="sp-choice" :class="{ 'is-selected': acct === 'buyer' }">
					<input type="radio" name="account_type" value="buyer" x-model="acct">
					<span class="sp-choice__title"><?php esc_html_e( 'Buyer account', 'southpoll-membership' ); ?></span>
					<span class="sp-choice__price"><?php esc_html_e( 'Free', 'southpoll-membership' ); ?></span>
					<ul class="sp-choice__list">
						<li><?php esc_html_e( 'See farm and seller contact information', 'southpoll-membership' ); ?></li>
						<li><?php esc_html_e( 'Save favorite farms and listings', 'southpoll-membership' ); ?></li>
						<li><?php esc_html_e( 'Saved searches with new-listing email alerts', 'southpoll-membership' ); ?></li>
					</ul>
				</label>
				<label class="sp-choice sp-choice--featured" :class="{ 'is-selected': acct === 'member' }">
					<input type="radio" name="account_type" value="member" x-model="acct">
					<span class="sp-badge sp-badge--new sp-choice__badge"><?php esc_html_e( 'SPGCA member', 'southpoll-membership' ); ?></span>
					<span class="sp-choice__title"><?php echo esc_html( $tier ? (string) $tier->name : __( 'Membership', 'southpoll-membership' ) ); ?></span>
					<span class="sp-choice__price"><?php echo esc_html( $price ); ?> <small><?php esc_html_e( 'per year', 'southpoll-membership' ); ?></small></span>
					<ul class="sp-choice__list">
						<li><?php esc_html_e( 'Everything in the buyer account', 'southpoll-membership' ); ?></li>
						<li><?php esc_html_e( 'Your own farm page in the member directory', 'southpoll-membership' ); ?></li>
						<li><?php esc_html_e( 'Publish cattle classified listings', 'southpoll-membership' ); ?></li>
						<li><?php esc_html_e( 'Invite account managers to help', 'southpoll-membership' ); ?></li>
						<li><?php esc_html_e( 'Registry discounts and member benefits', 'southpoll-membership' ); ?></li>
					</ul>
				</label>
			</div>

			<p class="sp-eyebrow"><?php esc_html_e( 'Step 2 · Your details', 'southpoll-membership' ); ?></p>
			<div class="sp-form__row sp-form__row--2">
				<div class="sp-field<?php echo $sp_cls( 'first_name' ); ?>"><label class="sp-label" for="sp-first"><?php esc_html_e( 'First name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="sp-first" class="sp-input" type="text" name="first_name" value="<?php echo $sp_old( 'first_name' ); ?>" autocomplete="given-name" required><?php echo $sp_err( 'first_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field<?php echo $sp_cls( 'last_name' ); ?>"><label class="sp-label" for="sp-last"><?php esc_html_e( 'Last name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="sp-last" class="sp-input" type="text" name="last_name" value="<?php echo $sp_old( 'last_name' ); ?>" autocomplete="family-name" required><?php echo $sp_err( 'last_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
			<div class="sp-form__row sp-form__row--2">
				<div class="sp-field<?php echo $sp_cls( 'email' ); ?>"><label class="sp-label" for="sp-email"><?php esc_html_e( 'Email address', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="sp-email" class="sp-input" type="email" name="email" value="<?php echo $sp_old( 'email', $invite ? (string) $invite->invite_email : '' ); ?>" autocomplete="email" required<?php echo $invite ? ' readonly' : ''; ?>><?php echo $sp_err( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field"><label class="sp-label" for="sp-phone"><?php esc_html_e( 'Phone', 'southpoll-membership' ); ?></label><input id="sp-phone" class="sp-input" type="tel" name="phone" value="<?php echo $sp_old( 'phone' ); ?>" autocomplete="tel"></div>
			</div>
			<div class="sp-form__row sp-form__row--2">
				<div class="sp-field<?php echo $sp_cls( 'password' ); ?>"><label class="sp-label" for="sp-pass"><?php esc_html_e( 'Password', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="sp-pass" class="sp-input" type="password" name="password" autocomplete="new-password" minlength="8" required><div class="sp-help"><?php esc_html_e( 'At least 8 characters.', 'southpoll-membership' ); ?></div><?php echo $sp_err( 'password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field<?php echo $sp_cls( 'password_confirm' ); ?>"><label class="sp-label" for="sp-pass2"><?php esc_html_e( 'Confirm password', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="sp-pass2" class="sp-input" type="password" name="password_confirm" autocomplete="new-password" required><?php echo $sp_err( 'password_confirm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
			<div class="sp-form__row sp-form__row--2" x-show="acct === 'member'" x-collapse>
				<div class="sp-field<?php echo $sp_cls( 'farm_name' ); ?>"><label class="sp-label" for="sp-farm"><?php esc_html_e( 'Farm / ranch name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="sp-farm" class="sp-input" type="text" name="farm_name" value="<?php echo $sp_old( 'farm_name' ); ?>" :required="acct === 'member'"><?php echo $sp_err( 'farm_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field"><label class="sp-label" for="sp-state"><?php esc_html_e( 'State', 'southpoll-membership' ); ?></label><select id="sp-state" class="sp-select" name="state"><option value=""><?php esc_html_e( 'Select…', 'southpoll-membership' ); ?></option><?php foreach ( $states as $abbr => $name ) : ?><option value="<?php echo esc_attr( $abbr ); ?>"<?php selected( $old['state'] ?? '', $abbr ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></div>
			</div>
			<div class="sp-form__row sp-form__row--2" x-show="acct === 'member'" x-collapse>
				<div class="sp-field"><label class="sp-label" for="sp-breeder"><?php esc_html_e( 'SPGCA breeder number', 'southpoll-membership' ); ?></label><input id="sp-breeder" class="sp-input" type="text" name="breeder_number" value="<?php echo $sp_old( 'breeder_number' ); ?>" autocomplete="off"><div class="sp-help"><?php esc_html_e( 'Optional. Your registry breeder number, if you already have one. It is tied to your farm, not your login.', 'southpoll-membership' ); ?></div></div>
			</div>
			<div class="sp-field<?php echo $sp_cls( 'agree' ); ?>"><label class="sp-check"><input type="checkbox" name="agree" value="1" required> <span><?php echo wp_kses_post( sprintf( /* translators: %s: rules URL */ __( 'I agree to the SouthPoll.com website terms and the <a href="%s" target="_blank" rel="noopener">SPGCA rules & regulations</a>.', 'southpoll-membership' ), esc_url( home_url( '/rules-regulations/' ) ) ) ); ?></span></label><?php echo $sp_err( 'agree' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="sp-form__actions">
				<button class="sp-btn sp-btn--primary sp-btn--lg" type="submit" x-text="acct === 'member' ? '<?php echo esc_js( __( 'Create account & continue to payment', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Create my free account', 'southpoll-membership' ) ); ?>'"><?php esc_html_e( 'Create account', 'southpoll-membership' ); ?></button>
				<button class="sp-btn sp-btn--ghost" type="button" @click="tab = 'login'"><?php esc_html_e( 'I already have an account', 'southpoll-membership' ); ?></button>
			</div>
			<p class="sp-text-small sp-text-muted" x-show="acct === 'member'"><?php esc_html_e( 'Paying by check or cash? Create your account, then choose "I paid offline" on the membership page and the office will confirm it.', 'southpoll-membership' ); ?></p>
		</form>
	</div>
</div>
<style>
	.sp-auth [x-cloak] { display: none !important; }
	.sp-auth__header { margin-bottom: 16px; }
	.sp-auth__grid { max-width: 920px; }
	.sp-auth__panel { max-width: 520px; }
	.sp-auth__panel--wide { max-width: 920px; }
	.sp-choice-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 24px; }
	.sp-choice { position: relative; display: block; border: 2px solid var(--sp-border); border-radius: var(--sp-radius-sm); padding: 20px 20px 16px; cursor: pointer; background: #fff; transition: border-color var(--sp-transition), box-shadow var(--sp-transition); }
	.sp-choice input { position: absolute; top: 16px; right: 16px; width: 18px; height: 18px; accent-color: var(--sp-maroon); margin: 0; }
	.sp-choice.is-selected { border-color: var(--sp-maroon); box-shadow: 0 0 0 1px var(--sp-maroon); }
	.sp-choice--featured { background: var(--sp-warning-bg); }
	.sp-choice__badge { margin-bottom: 8px; }
	.sp-choice__title { display: block; font-family: var(--sp-font-heading); font-weight: 600; font-size: 1.3rem; text-transform: uppercase; letter-spacing: var(--sp-heading-tracking); color: var(--sp-ink); padding-right: 32px; }
	.sp-choice__price { display: block; font-family: var(--sp-font-heading); font-size: 1.8rem; color: var(--sp-maroon); margin: 4px 0 10px; }
	.sp-choice__price small { font-family: var(--sp-font-body); font-size: .75rem; color: var(--sp-muted); text-transform: uppercase; letter-spacing: .05em; }
	.sp-choice__list { margin: 0; padding-left: 18px; font-size: .9rem; line-height: 1.5; }
	.sp-choice__list li { margin-bottom: 4px; }
</style>
<?php sp_membership()->get( \SouthPoll\Membership\Feedback\FeedbackButton::class )->display(); // S8: floating feedback button (prints nothing while off) ?>
