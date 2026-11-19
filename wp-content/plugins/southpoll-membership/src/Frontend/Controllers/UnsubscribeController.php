<?php
/**
 * /members/unsubscribe/?u=&t=&i=&k= — one-click alert opt-out from email links (signed; no login needed).
 * t = farm | listing (favorite alerts), search (saved search alerts), all (every optional email).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend\Controllers;

use SouthPoll\Membership\Engagement\FavoriteRepository;
use SouthPoll\Membership\Engagement\SavedSearchRepository;
use SouthPoll\Membership\Frontend\View;
use SouthPoll\Membership\Members\MemberRepository;
use SouthPoll\Membership\Routing\Controller;
use SouthPoll\Membership\Routing\RouteMatch;
use SouthPoll\Membership\Routing\Urls;

final class UnsubscribeController implements Controller {

	public function __construct( private View $view, private Urls $urls, private FavoriteRepository $favorites, private SavedSearchRepository $searches, private MemberRepository $members ) {}

	public function prepare( RouteMatch $match ): void {
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	public function title( RouteMatch $match ): string {
		return __( 'Email alerts', 'southpoll-membership' );
	}

	public function render( RouteMatch $match ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the link carries its own HMAC signature
		$uid  = isset( $_GET['u'] ) ? (int) $_GET['u'] : 0;
		$type = isset( $_GET['t'] ) ? sanitize_key( wp_unslash( $_GET['t'] ) ) : '';
		$oid  = isset( $_GET['i'] ) ? (int) $_GET['i'] : 0;
		$key  = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
		// phpcs:enable
		$result = $this->apply( $uid, $type, $oid, $key );
		$this->view->display( 'theme/message', [
			'heading' => $result['ok'] ? __( 'Alerts turned off', 'southpoll-membership' ) : __( 'That link did not work', 'southpoll-membership' ),
			'message' => $result['message'],
			'type'    => $result['ok'] ? 'success' : 'warning',
			'links'   => [
				[ 'label' => __( 'Manage all alerts', 'southpoll-membership' ), 'href' => $this->urls->dashboard( 'account-details' ), 'primary' => true ],
				[ 'label' => __( 'Classifieds', 'southpoll-membership' ), 'href' => $this->urls->classifieds() ],
			],
		] );
	}

	/** @return array{ok:bool, message:string} */
	public function apply( int $uid, string $type, int $oid, string $key ): array {
		if ( $uid <= 0 || '' === $type || '' === $key || ! hash_equals( Urls::unsubscribe_key( $uid, $type, $oid ), $key ) ) {
			return [ 'ok' => false, 'message' => __( 'The unsubscribe link is invalid or has expired. You can manage alerts from Account Details after logging in.', 'southpoll-membership' ) ];
		}
		switch ( $type ) {
			case 'farm':
			case 'listing':
				$fav = $this->favorites->find_for( $uid, $type, $oid );
				if ( $fav ) {
					$this->favorites->set_notify( (int) $fav->id, false );
				}
				return [ 'ok' => true, 'message' => 'farm' === $type ? __( 'You will no longer get emails when this farm publishes a listing. It stays in your favorites.', 'southpoll-membership' ) : __( 'You will no longer get emails when this listing changes. It stays in your favorites.', 'southpoll-membership' ) ];
			case 'search':
				$search = $this->searches->find_for_user( $uid, $oid );
				if ( $search ) {
					$this->searches->update( (int) $search->id, [ 'notify' => false ] );
				}
				return [ 'ok' => true, 'message' => __( 'Email alerts for this saved search are off. The search itself is still saved.', 'southpoll-membership' ) ];
			case 'all':
				$member = $this->members->find_by_user( $uid );
				if ( $member ) {
					$this->members->update( (int) $member->id, [ 'notify_all' => 0 ] );
				}
				return [ 'ok' => true, 'message' => __( 'All optional emails are off. Membership emails and receipts are still sent.', 'southpoll-membership' ) ];
		}
		return [ 'ok' => false, 'message' => __( 'Unknown alert type.', 'southpoll-membership' ) ];
	}
}
