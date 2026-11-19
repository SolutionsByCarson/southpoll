<?php
/**
 * Legacy URL redirects (Classified Listing era → plugin URLs). Exact rows live in sp_redirects (loaded by
 * scripts/migrate-redirects.php); anything else under the old prefixes falls back to a pattern rule. The front-end hook
 * is inert until the `legacy_redirects_enabled` setting is on (switched on at cutover); resolve() can be used any time.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Routing;

use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Service;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Support\Str;

final class Redirects implements Service {

	public function __construct( private Settings $settings, private Urls $urls ) {}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 0 );
	}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'legacy_redirects_enabled', false );
	}

	public function maybe_redirect(): void {
		if ( ! $this->enabled() || is_admin() ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$hit  = $this->resolve( $path );
		if ( ! $hit ) {
			return;
		}
		if ( $hit['id'] ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'sp_redirects' ) . ' SET hits = hits + 1, last_hit_at = %s WHERE id = %d', current_time( 'mysql', true ), $hit['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		wp_safe_redirect( $hit['to'], (int) $hit['status'] );
		exit;
	}

	/**
	 * @return array{to:string, status:int, id:int, rule:string}|null
	 */
	public function resolve( string $path ): ?array {
		global $wpdb;
		$path = '/' . trim( strtolower( rawurldecode( $path ) ), '/' ) . '/';
		if ( '//' === $path ) {
			$path = '/';
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, to_url, status FROM ' . Schema::table( 'sp_redirects' ) . ' WHERE from_path = %s', $path ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $row ) {
			return [ 'to' => (string) $row->to_url, 'status' => (int) $row->status, 'id' => (int) $row->id, 'rule' => 'table' ];
		}
		// Pattern fallbacks for anything the table does not list.
		if ( preg_match( '#^/rtcl_listing/([^/]+)/#', $path, $m ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sp_old_slugs' WHERE p.post_type = 'sp_listing' AND (p.post_name = %s OR pm.meta_value LIKE %s) LIMIT 1", $m[1], '%' . $wpdb->esc_like( '"' . $m[1] . '"' ) . '%' ) );
			$post = $id ? get_post( $id ) : null;
			return [ 'to' => $post && 'publish' === $post->post_status ? $this->urls->listing( $post->post_name ) : $this->urls->classifieds(), 'status' => 301, 'id' => 0, 'rule' => 'listing-pattern' ];
		}
		if ( preg_match( '#^/store/([^/]+)/#', $path, $m ) ) {
			$farm = $wpdb->get_row( $wpdb->prepare( 'SELECT slug FROM ' . Schema::table( 'sp_farms' ) . ' WHERE slug = %s OR legacy_store_id = %d LIMIT 1', $m[1], is_numeric( $m[1] ) ? (int) $m[1] : 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return [ 'to' => $farm ? $this->urls->farm( (string) $farm->slug ) : $this->urls->directory(), 'status' => 301, 'id' => 0, 'rule' => 'store-pattern' ];
		}
		if ( preg_match( '#^/listing-category/([^/]+)/#', $path, $m ) ) {
			$map = [ 'bulls' => 'bulls', 'cows' => 'cows', 'heifers' => 'heifers', 'steers' => 'steers', 'semen' => 'semen', 'pairs' => 'cows' ];
			return [ 'to' => isset( $map[ $m[1] ] ) ? $this->urls->category( $map[ $m[1] ] ) : $this->urls->classifieds(), 'status' => 301, 'id' => 0, 'rule' => 'category-pattern' ];
		}
		// Listing classes retired on 2026-11-18 (S5): the new-site archives that existed on staging before the change.
		$classifieds = rtrim( (string) wp_parse_url( $this->urls->classifieds(), PHP_URL_PATH ), '/' );
		if ( '' !== $classifieds && preg_match( '#^' . preg_quote( $classifieds, '#' ) . '/(females|feeders|finishers|calves)/#', $path, $m ) ) {
			$retired = [ 'females' => 'cows', 'feeders' => 'steers', 'finishers' => 'steers', 'calves' => 'steers' ];
			return [ 'to' => $this->urls->category( $retired[ $m[1] ] ), 'status' => 301, 'id' => 0, 'rule' => 'retired-category-pattern' ];
		}
		if ( preg_match( '#^/listing-location/([^/]+)/#', $path, $m ) ) {
			$code = '';
			foreach ( Str::us_states() as $c => $name ) {
				if ( sanitize_title( $name ) === $m[1] ) {
					$code = $c;
				}
			}
			return [ 'to' => $code ? $this->urls->classifieds( [ 'state' => $code ] ) : $this->urls->classifieds(), 'status' => 301, 'id' => 0, 'rule' => 'location-pattern' ];
		}
		if ( preg_match( '#^/(listing-tag|listings)/#', $path ) ) {
			return [ 'to' => $this->urls->classifieds(), 'status' => 301, 'id' => 0, 'rule' => 'archive-pattern' ];
		}
		if ( preg_match( '#^/stores?/#', $path ) ) {
			return [ 'to' => $this->urls->directory(), 'status' => 301, 'id' => 0, 'rule' => 'archive-pattern' ];
		}
		if ( preg_match( '#^/my-account/(.*)#', $path, $m ) ) {
			$ep  = trim( $m[1], '/' );
			$map = [ '' => $this->urls->dashboard(), 'listings' => $this->urls->my_listings(), 'my-store' => $this->urls->my_farm(), 'favourites' => $this->urls->my_favorites(), 'favorites' => $this->urls->my_favorites(), 'edit-account' => $this->urls->dashboard( 'account-details' ), 'payments' => $this->urls->dashboard( 'manage-membership' ), 'membership' => $this->urls->dashboard( 'manage-membership' ), 'lost-password' => $this->urls->reset_password(), 'logout' => $this->urls->logout(), 'chat' => $this->urls->dashboard(), 'subscriptions' => $this->urls->dashboard( 'manage-membership' ) ];
			$key = explode( '/', $ep )[0];
			return [ 'to' => $map[ $key ] ?? $this->urls->dashboard(), 'status' => 301, 'id' => 0, 'rule' => 'account-pattern' ];
		}
		if ( preg_match( '#^/checkout/#', $path ) ) {
			return [ 'to' => $this->urls->dashboard( 'manage-membership' ), 'status' => 301, 'id' => 0, 'rule' => 'checkout-pattern' ];
		}
		return null;
	}
}
