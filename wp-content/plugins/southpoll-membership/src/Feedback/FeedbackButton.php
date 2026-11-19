<?php
/**
 * Renders the floating "Feedback / report a problem" button + its modal template (S8). Called from the login template
 * and the dashboard layout; prints nothing while `feedback_enabled` is off.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Feedback;

use SouthPoll\Membership\Auth\Recaptcha;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\ContextProvider;

final class FeedbackButton {

	public function __construct( private Settings $settings, private Recaptcha $recaptcha, private View $view, private ContextProvider $contexts ) {}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'feedback_enabled', false );
	}

	public function render(): string {
		if ( ! $this->enabled() ) {
			return '';
		}
		$ctx  = $this->contexts->current();
		$user = $ctx->is_logged_in() ? get_userdata( $ctx->user_id ) : null;
		if ( ! $ctx->is_logged_in() ) {
			$this->recaptcha->enqueue();
		}
		return $this->view->render( 'feedback/button', [
			'label'       => (string) ( $this->settings->get( 'feedback_label', '' ) ?: __( 'Feedback / report a problem', 'southpoll-membership' ) ),
			'logged_in'   => $ctx->is_logged_in(),
			'name'        => $user ? ( trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name ) : '',
			'email'       => $user ? (string) $user->user_email : '',
			'recaptcha'   => ! $ctx->is_logged_in() && $this->recaptcha->enabled(),
			'max_mb'      => FeedbackService::SCREENSHOT_MB,
			'max_chars'   => FeedbackService::MAX_CHARS,
		] );
	}

	public function display(): void {
		echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes its output
	}
}
