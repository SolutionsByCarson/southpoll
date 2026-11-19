<?php
/**
 * Admin → SouthPoll → Settings (administrators): every SettingsSchema group as one classic form (no JS needed),
 * validated by AdminSettingsController::apply_settings so the REST API and this form behave identically.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Core\SettingsSchema;
use SouthPoll\Membership\Rest\Admin\AdminSettingsController;

final class SettingsPage extends AdminPage {

	public const SLUG   = 'southpoll-settings';
	private const NONCE = 'sp_settings_save';

	public function __construct( private Settings $settings, private AdminSettingsController $controller ) {}

	public function handle_actions(): void {
		if ( empty( $_POST['sp_settings_action'] ) || ! current_user_can( Capabilities::CAP_MANAGE_SETTINGS ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		$posted = isset( $_POST['sp'] ) && is_array( $_POST['sp'] ) ? wp_unslash( $_POST['sp'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field by the schema
		$values = [];
		foreach ( SettingsSchema::fields() as $key => $field ) {
			if ( 'toggle' === $field['type'] ) {
				$values[ $key ] = ! empty( $posted[ $key ] ) ? '1' : '0';
			} elseif ( array_key_exists( $key, $posted ) ) {
				$values[ $key ] = is_array( $posted[ $key ] ) ? array_map( 'sanitize_text_field', $posted[ $key ] ) : (string) $posted[ $key ];
			}
		}
		$result = AdminSettingsController::apply_settings( $this->settings, $values );
		if ( $result['errors'] ) {
			set_transient( 'sp_settings_errors_' . get_current_user_id(), [ 'errors' => $result['errors'], 'values' => $values ], 120 );
			wp_safe_redirect( self::url( [ 'sp_done' => 'error', 'sp_msg' => __( 'Some settings were not saved; see the fields marked below.', 'southpoll-membership' ) ] ) );
			exit;
		}
		wp_safe_redirect( self::url( [ 'sp_done' => 'saved', 'sp_msg' => sprintf( /* translators: %d: count */ __( 'Settings saved (%d values).', 'southpoll-membership' ), $result['saved'] ) ] ) );
		exit;
	}

	public function render(): void {
		$this->guard( Capabilities::CAP_MANAGE_SETTINGS );
		$state  = get_transient( 'sp_settings_errors_' . get_current_user_id() );
		$errors = is_array( $state ) ? (array) $state['errors'] : [];
		$values = SettingsSchema::values( $this->settings );
		if ( is_array( $state ) ) {
			delete_transient( 'sp_settings_errors_' . get_current_user_id() );
			foreach ( (array) $state['values'] as $k => $v ) {
				if ( isset( $errors[ $k ] ) ) {
					$values[ $k ] = $v; // keep the rejected input visible
				}
			}
		}
		$groups = $this->controller->groups();
		$this->open( __( 'Settings', 'southpoll-membership' ) );
		?>
		<form method="post" class="sp-admin__settings">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="sp_settings_action" value="save">
			<div class="sp-admin__settings-layout">
				<nav class="sp-admin__settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'southpoll-membership' ); ?>">
					<?php foreach ( $groups as $gkey => $g ) : ?><a href="#sp-group-<?php echo esc_attr( $gkey ); ?>"><?php echo esc_html( $g['title'] ); ?></a><?php endforeach; ?>
					<button class="sp-btn sp-btn--primary sp-btn--block"><?php esc_html_e( 'Save settings', 'southpoll-membership' ); ?></button>
				</nav>
				<div class="sp-admin__settings-groups">
					<?php foreach ( $groups as $gkey => $g ) : ?>
						<section class="sp-card sp-admin__group" id="sp-group-<?php echo esc_attr( $gkey ); ?>">
							<h2 class="sp-card__title"><?php echo esc_html( $g['title'] ); ?></h2>
							<?php if ( ! empty( $g['description'] ) ) : ?><p class="description"><?php echo esc_html( $g['description'] ); ?></p><?php endif; ?>
							<?php foreach ( $g['fields'] as $key => $f ) : ?>
								<?php $this->field( $key, $f, $values[ $key ] ?? null, $errors[ $key ] ?? '' ); ?>
							<?php endforeach; ?>
						</section>
					<?php endforeach; ?>
					<p><button class="sp-btn sp-btn--primary"><?php esc_html_e( 'Save settings', 'southpoll-membership' ); ?></button></p>
				</div>
			</div>
		</form>
		<?php
		$this->close();
	}

	/** @param array<string, mixed> $f */
	private function field( string $key, array $f, mixed $value, string $error ): void {
		$id   = 'sp-set-' . $key;
		$name = 'sp[' . $key . ']';
		$type = (string) $f['type'];
		echo '<div class="sp-field sp-admin__setting' . ( $error ? ' sp-field--error' : '' ) . ( 'toggle' === $type ? ' sp-admin__setting--toggle' : '' ) . '">';
		if ( 'toggle' === $type ) {
			echo '<label class="sp-check"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '> <span>' . esc_html( (string) $f['label'] ) . '</span></label>';
		} else {
			echo '<label class="sp-label" for="' . esc_attr( $id ) . '">' . esc_html( (string) $f['label'] ) . '</label>';
			switch ( $type ) {
				case 'number':
				case 'float':
					echo '<input class="sp-input sp-input--short" type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( isset( $f['min'] ) ? ' min="' . esc_attr( (string) $f['min'] ) . '"' : '' ) . ( isset( $f['max'] ) ? ' max="' . esc_attr( (string) $f['max'] ) . '"' : '' ) . ( 'float' === $type ? ' step="any"' : ' step="1"' ) . '>';
					break;
				case 'select':
				case 'page':
					echo '<select class="sp-select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
					foreach ( (array) ( $f['options'] ?? [] ) as $ov => $ol ) {
						echo '<option value="' . esc_attr( (string) $ov ) . '"' . selected( (string) $ov, (string) $value, false ) . '>' . esc_html( (string) $ol ) . '</option>';
					}
					echo '</select>';
					break;
				case 'region_map':
					echo '<textarea class="sp-textarea" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="8" spellcheck="false">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				case 'textarea':
					echo '<textarea class="sp-textarea" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				case 'date':
					echo '<input class="sp-input sp-input--short" type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
					break;
				default:
					echo '<input class="sp-input" type="' . ( in_array( $type, [ 'email', 'url' ], true ) ? $type : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			}
		}
		if ( ! empty( $f['help'] ) ) {
			echo '<p class="sp-help">' . esc_html( (string) $f['help'] ) . '</p>';
		}
		if ( $error ) {
			echo '<p class="sp-error">' . esc_html( $error ) . '</p>';
		}
		echo '</div>';
	}
}
