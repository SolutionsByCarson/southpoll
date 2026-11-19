<?php
/**
 * Wraps a Message in the branded HTML email layout (templates/emails/layout.php).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Email;

use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Routing\Urls;

final class Template {

	public function __construct( private View $view, private Settings $settings, private Urls $urls ) {}

	public function render( Message $message ): string {
		return $this->view->render( 'emails/layout', [
			'message'       => $message,
			'subject'       => $message->subject(),
			'heading'       => $message->heading(),
			'preheader'     => $message->preheader(),
			'body'          => $message->body(),
			'cta'           => $message->cta(),
			'footer_note'   => $message->footer_note(),
			'logo_url'      => SP_PLUGIN_URL . 'assets/img/southpoll-horizontal-logo.png',
			'site_name'     => get_bloginfo( 'name' ),
			'home_url'      => home_url( '/' ),
			'admin_contact' => (array) $this->settings->get( 'admin_contact', [] ),
			'prefs_url'     => $message->configurable() ? $this->urls->dashboard( 'account-details' ) : '',
			'year'          => wp_date( 'Y' ),
		] );
	}

	/**
	 * Plain-text alternative derived from the HTML (used for logging and as a fallback).
	 */
	public function text( Message $message ): string {
		$text = $message->heading() . "\n\n" . wp_strip_all_tags( str_replace( [ '</p>', '<br>', '<br/>', '<br />' ], "\n", $message->body() ) );
		$cta  = $message->cta();
		if ( $cta ) {
			$text .= "\n\n" . $cta['label'] . ': ' . $cta['url'];
		}
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}
}
