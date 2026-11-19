<?php
/**
 * Admin → SouthPoll → Integrations (administrators): Stripe keys + mode + webhook, Google Maps / Geocoding keys,
 * reCAPTCHA keys, email sender. Saving goes through AdminSettingsController::put_integrations (same rules as the
 * REST API; blank or still-masked values keep what is stored). "Test" buttons call /admin/integrations/test.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Rest\Admin\AdminSettingsController;
use WP_REST_Request;

final class IntegrationsPage extends AdminPage {

	public const SLUG   = 'southpoll-integrations';
	private const NONCE = 'sp_integrations_save';

	public function __construct( private AdminSettingsController $controller ) {}

	public function handle_actions(): void {
		if ( empty( $_POST['sp_integrations_action'] ) || ! current_user_can( Capabilities::CAP_MANAGE_INTEGRATIONS ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		$values = isset( $_POST['values'] ) && is_array( $_POST['values'] ) ? array_map( static fn( $v ) => is_string( $v ) ? sanitize_text_field( wp_unslash( $v ) ) : '', $_POST['values'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clear  = isset( $_POST['clear'] ) ? array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) $_POST['clear'] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$req    = new WP_REST_Request( 'PUT', '/sp/v1/admin/integrations' );
		$req->set_body_params( [ 'values' => $values, 'clear' => $clear ] );
		$res = $this->controller->put_integrations( $req );
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect( self::url( [ 'sp_done' => 'error', 'sp_msg' => $res->get_error_message() ] ) );
			exit;
		}
		wp_safe_redirect( self::url( [ 'sp_done' => 'saved', 'sp_msg' => __( 'Integrations saved.', 'southpoll-membership' ) ] ) );
		exit;
	}

	public function render(): void {
		$this->guard( Capabilities::CAP_MANAGE_INTEGRATIONS );
		$s = $this->controller->integration_state();
		$v = $s['values'];
		$p = $s['present'];
		$this->open( __( 'Integrations', 'southpoll-membership' ) );
		?>
		<p class="description sp-admin__hint"><?php esc_html_e( 'Keys are stored outside the autoloaded options and shown masked. Leave a field as-is to keep the stored value, paste a new key to replace it, or tick "clear" to remove it. Nothing here is written to the log.', 'southpoll-membership' ); ?></p>
		<form method="post" class="sp-admin__settings">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="sp_integrations_action" value="save">
			<div class="sp-admin__settings-groups">

				<section class="sp-card sp-admin__group">
					<div class="sp-card__header"><h2 class="sp-card__title"><?php esc_html_e( 'Stripe', 'southpoll-membership' ); ?></h2><?php $this->light( $s['configured']['stripe'], __( 'Keys present for the selected mode', 'southpoll-membership' ), __( 'Not configured', 'southpoll-membership' ) ); ?></div>
					<div class="sp-field"><span class="sp-label"><?php esc_html_e( 'Mode', 'southpoll-membership' ); ?></span>
						<div class="sp-cluster"><label class="sp-check"><input type="radio" name="values[stripe.mode]" value="test" <?php checked( 'test', $s['stripe_mode'] ); ?>> <span><?php esc_html_e( 'Test', 'southpoll-membership' ); ?></span></label><label class="sp-check"><input type="radio" name="values[stripe.mode]" value="live" <?php checked( 'live', $s['stripe_mode'] ); ?>> <span><?php esc_html_e( 'Live', 'southpoll-membership' ); ?></span></label></div>
						<p class="sp-help"><?php esc_html_e( 'Test mode uses the test keys and never charges real cards. Switch to Live only after the cutover.', 'southpoll-membership' ); ?></p>
					</div>
					<div class="sp-form__row sp-form__row--2">
						<?php $this->key( 'stripe.test_publishable_key', __( 'Test publishable key (pk_test_…)', 'southpoll-membership' ), $v, $p ); ?>
						<?php $this->key( 'stripe.test_secret_key', __( 'Test secret key (sk_test_…)', 'southpoll-membership' ), $v, $p ); ?>
						<?php $this->key( 'stripe.live_publishable_key', __( 'Live publishable key (pk_live_…)', 'southpoll-membership' ), $v, $p ); ?>
						<?php $this->key( 'stripe.live_secret_key', __( 'Live secret key (sk_live_…)', 'southpoll-membership' ), $v, $p ); ?>
					</div>
					<?php $this->key( 'stripe.webhook_secret', __( 'Webhook signing secret (whsec_…)', 'southpoll-membership' ), $v, $p, sprintf( /* translators: %s: URL */ __( 'In the Stripe Dashboard add an endpoint for %s listening to checkout.session.completed, invoice.paid, invoice.payment_failed, customer.subscription.updated and customer.subscription.deleted, then paste its signing secret here.', 'southpoll-membership' ), $s['webhook_url'] ) ); ?>
					<div class="sp-admin__test"><button type="button" class="sp-btn sp-btn--outline sp-btn--sm" data-sp-test="stripe"><?php esc_html_e( 'Test Stripe connection', 'southpoll-membership' ); ?></button> <span class="sp-help" data-sp-test-result="stripe"></span></div>
				</section>

				<section class="sp-card sp-admin__group">
					<div class="sp-card__header"><h2 class="sp-card__title"><?php esc_html_e( 'Google Maps', 'southpoll-membership' ); ?></h2><?php $this->light( $s['configured']['maps'] && $s['configured']['geocoding'], __( 'Both keys present', 'southpoll-membership' ), __( 'Map and pins stay off until both keys are entered', 'southpoll-membership' ) ); ?></div>
					<div class="sp-form__row sp-form__row--2">
						<?php $this->key( 'google.maps_browser_key', __( 'Maps JavaScript (browser) key', 'southpoll-membership' ), $v, $p, __( 'Restrict it to this site’s domain in Google Cloud. Used by the directory map and farm-page map.', 'southpoll-membership' ) ); ?>
						<?php $this->key( 'google.geocoding_key', __( 'Geocoding (server) key', 'southpoll-membership' ), $v, $p, __( 'Restrict it to the Geocoding API. Used to turn farm addresses into map pins (Status → "Geocode farms" runs the backlog).', 'southpoll-membership' ) ); ?>
					</div>
					<div class="sp-admin__test"><button type="button" class="sp-btn sp-btn--outline sp-btn--sm" data-sp-test="geocoding"><?php esc_html_e( 'Test geocoding', 'southpoll-membership' ); ?></button> <span class="sp-help" data-sp-test-result="geocoding"></span> <button type="button" class="sp-btn sp-btn--outline sp-btn--sm" data-sp-test="maps"><?php esc_html_e( 'Check browser key', 'southpoll-membership' ); ?></button> <span class="sp-help" data-sp-test-result="maps"></span></div>
				</section>

				<section class="sp-card sp-admin__group">
					<div class="sp-card__header"><h2 class="sp-card__title"><?php esc_html_e( 'reCAPTCHA v3', 'southpoll-membership' ); ?></h2><?php $this->light( $s['configured']['recaptcha'], __( 'Keys present', 'southpoll-membership' ), __( 'Not configured (forms still work; enable under Settings → Accounts once keys are in)', 'southpoll-membership' ) ); ?></div>
					<div class="sp-form__row sp-form__row--3">
						<?php $this->key( 'recaptcha.site_key', __( 'Site key', 'southpoll-membership' ), $v, $p ); ?>
						<?php $this->key( 'recaptcha.secret_key', __( 'Secret key', 'southpoll-membership' ), $v, $p ); ?>
						<div class="sp-field"><label class="sp-label" for="sp-int-threshold"><?php esc_html_e( 'Score threshold (0–1)', 'southpoll-membership' ); ?></label><input class="sp-input sp-input--short" type="number" step="0.05" min="0" max="1" id="sp-int-threshold" name="values[recaptcha.threshold]" value="<?php echo esc_attr( (string) ( $v['recaptcha.threshold'] ?: '0.5' ) ); ?>"><p class="sp-help"><?php esc_html_e( 'Submissions scoring below this are rejected. 0.5 is Google’s default.', 'southpoll-membership' ); ?></p></div>
					</div>
					<div class="sp-admin__test"><button type="button" class="sp-btn sp-btn--outline sp-btn--sm" data-sp-test="recaptcha"><?php esc_html_e( 'Check reCAPTCHA keys', 'southpoll-membership' ); ?></button> <span class="sp-help" data-sp-test-result="recaptcha"></span></div>
				</section>

				<section class="sp-card sp-admin__group">
					<div class="sp-card__header"><h2 class="sp-card__title"><?php esc_html_e( 'Email sender', 'southpoll-membership' ); ?></h2></div>
					<div class="sp-form__row sp-form__row--2">
						<div class="sp-field"><label class="sp-label" for="sp-int-from-name"><?php esc_html_e( 'From name', 'southpoll-membership' ); ?></label><input class="sp-input" type="text" id="sp-int-from-name" name="values[email.from_name]" value="<?php echo esc_attr( (string) $v['email.from_name'] ); ?>"></div>
						<div class="sp-field"><label class="sp-label" for="sp-int-from-email"><?php esc_html_e( 'From address', 'southpoll-membership' ); ?></label><input class="sp-input" type="email" id="sp-int-from-email" name="values[email.from_email]" value="<?php echo esc_attr( (string) $v['email.from_email'] ); ?>"><p class="sp-help"><?php esc_html_e( 'Should be on the site’s own domain so the host’s mail service (or your SMTP plugin) signs it.', 'southpoll-membership' ); ?></p></div>
					</div>
					<div class="sp-admin__test"><button type="button" class="sp-btn sp-btn--outline sp-btn--sm" data-sp-test="email"><?php esc_html_e( 'Send me a test email', 'southpoll-membership' ); ?></button> <span class="sp-help" data-sp-test-result="email"></span></div>
				</section>

				<p><button class="sp-btn sp-btn--primary"><?php esc_html_e( 'Save integrations', 'southpoll-membership' ); ?></button></p>
			</div>
		</form>
		<?php
		$this->close();
	}

	/** @param array<string, mixed> $values @param array<string, bool> $present */
	private function key( string $key, string $label, array $values, array $present, string $help = '' ): void {
		$id = 'sp-int-' . sanitize_html_class( str_replace( '.', '-', $key ) );
		echo '<div class="sp-field"><label class="sp-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ' ' . ( ! empty( $present[ $key ] ) ? '<span class="sp-badge sp-badge--success">' . esc_html__( 'set', 'southpoll-membership' ) . '</span>' : '<span class="sp-badge sp-badge--muted">' . esc_html__( 'empty', 'southpoll-membership' ) . '</span>' ) . '</label>';
		echo '<input class="sp-input" type="text" id="' . esc_attr( $id ) . '" name="values[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $values[ $key ] ) . '" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__( 'paste to replace', 'southpoll-membership' ) . '">';
		if ( ! empty( $present[ $key ] ) ) {
			echo '<label class="sp-check sp-admin__clear"><input type="checkbox" name="clear[]" value="' . esc_attr( $key ) . '"> <span>' . esc_html__( 'clear this key', 'southpoll-membership' ) . '</span></label>';
		}
		if ( $help ) {
			echo '<p class="sp-help">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	private function light( bool $on, string $on_text, string $off_text ): void {
		echo '<span class="sp-admin__light"><span class="sp-light ' . ( $on ? 'sp-light--on' : 'sp-light--off' ) . '" aria-hidden="true"></span> ' . esc_html( $on ? $on_text : $off_text ) . '</span>';
	}
}
