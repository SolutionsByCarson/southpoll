<?php
/**
 * Every plugin URL is generated here so a change to the route bases (admin setting) propagates everywhere,
 * including links in emails.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Routing;

use SouthPoll\Membership\Core\Settings;

final class Urls {

	public function __construct( private Settings $settings ) {}

	/**
	 * First path segment for a route family: members | classifieds | farms | favorites | search.
	 */
	public function base( string $key ): string {
		$bases = (array) $this->settings->get( 'routes', [] );
		$value = isset( $bases[ $key ] ) ? sanitize_title( (string) $bases[ $key ] ) : '';
		return '' !== $value ? $value : $key;
	}

	public function home( string $path = '' ): string {
		return home_url( '/' . ltrim( $path, '/' ) );
	}

	public function directory( array $query = [] ): string {
		return $this->with_query( $this->home( $this->base( 'members' ) . '/' ), $query );
	}

	public function login( string $redirect_to = '' ): string {
		$url = $this->home( $this->base( 'members' ) . '/login/' );
		return '' !== $redirect_to ? add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url ) : $url;
	}

	public function register( string $tier = '' ): string {
		return $this->with_query( $this->home( $this->base( 'members' ) . '/login/' ), array_filter( [ 'action' => 'register', 'tier' => $tier ] ) );
	}

	public function logout(): string {
		return wp_nonce_url( $this->home( $this->base( 'members' ) . '/logout/' ), 'sp_logout', 'sp_nonce' );
	}

	public function verify( string $token ): string {
		return add_query_arg( 'token', $token, $this->home( $this->base( 'members' ) . '/verify/' ) );
	}

	public function reset_password( string $key = '', string $login = '' ): string {
		return $this->with_query( $this->home( $this->base( 'members' ) . '/reset-password/' ), array_filter( compact( 'key', 'login' ) ) );
	}

	public function invite( string $token ): string {
		return add_query_arg( 'token', $token, $this->home( $this->base( 'members' ) . '/invite/' ) );
	}

	/**
	 * Dashboard section: '' | my-favorites | saved-searches | my-farm | my-listings | edit-listing | account-details |
	 * manage-membership | help | ui-kit.
	 */
	public function dashboard( string $section = '', array $query = [] ): string {
		$segment = '' === $section ? 'dashboard' : $section;
		return $this->with_query( $this->home( $this->base( 'members' ) . '/' . $segment . '/' ), $query );
	}

	public function edit_listing( int $id = 0, array $query = [] ): string {
		return $this->dashboard( 'edit-listing', ( $id ? [ 'id' => $id ] : [] ) + $query );
	}

	public function my_listings( array $query = [] ): string {
		return $this->dashboard( 'my-listings', $query );
	}

	public function saved_searches(): string {
		return $this->dashboard( 'saved-searches' );
	}

	/**
	 * One-click unsubscribe link for alert emails: type farm|listing|search|all, signed so it works without logging in.
	 */
	public function unsubscribe( int $user_id, string $type, int $object_id = 0 ): string {
		return $this->with_query( $this->home( $this->base( 'members' ) . '/unsubscribe/' ), [ 'u' => $user_id, 't' => $type, 'i' => $object_id, 'k' => self::unsubscribe_key( $user_id, $type, $object_id ) ] );
	}

	public static function unsubscribe_key( int $user_id, string $type, int $object_id ): string {
		return substr( hash_hmac( 'sha256', $user_id . '|' . $type . '|' . $object_id, wp_salt( 'nonce' ) ), 0, 24 );
	}

	public function checkout_return( string $result ): string {
		return $this->home( $this->base( 'members' ) . '/checkout/' . sanitize_key( $result ) . '/' );
	}

	public function help(): string {
		return $this->dashboard( 'help' );
	}

	public function my_farm( string $anchor = '' ): string {
		return $this->dashboard( 'my-farm' ) . ( '' !== $anchor ? '#' . $anchor : '' );
	}

	public function my_favorites(): string {
		return $this->dashboard( 'my-favorites' );
	}

	public function farm( string $slug ): string {
		return $this->home( $this->base( 'farms' ) . '/' . rawurlencode( $slug ) . '/' );
	}

	public function classifieds( array $query = [] ): string {
		return $this->with_query( $this->home( $this->base( 'classifieds' ) . '/' ), $query );
	}

	public function category( string $slug, array $query = [] ): string {
		return $this->with_query( $this->home( $this->base( 'classifieds' ) . '/' . rawurlencode( $slug ) . '/' ), $query );
	}

	public function listing( string $slug ): string {
		return $this->home( $this->base( 'classifieds' ) . '/listing/' . rawurlencode( $slug ) . '/' );
	}

	public function favorites_share( string $token ): string {
		return $this->home( $this->base( 'favorites' ) . '/' . rawurlencode( $token ) . '/' );
	}

	public function search_share( string $token ): string {
		return $this->home( $this->base( 'search' ) . '/' . rawurlencode( $token ) . '/' );
	}

	public function stripe_webhook(): string {
		return $this->home( 'sp-webhook/stripe/' );
	}

	public function rest( string $path = '' ): string {
		return rest_url( 'sp/v1/' . ltrim( $path, '/' ) );
	}

	/**
	 * Current request URL (for redirect_to parameters).
	 */
	public function current(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return home_url( $uri );
	}

	private function with_query( string $url, array $query ): string {
		$query = array_filter( $query, static fn( $v ) => null !== $v && '' !== $v );
		return $query ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url ) : $url;
	}
}
