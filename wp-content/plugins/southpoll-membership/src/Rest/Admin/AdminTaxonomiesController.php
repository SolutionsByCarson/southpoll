<?php
/**
 * Offerings + listing categories (staff only): CRUD with display order and SEO fields.
 *
 *   GET    /sp/v1/admin/taxonomies/{tax}
 *   POST   /sp/v1/admin/taxonomies/{tax}                { name, slug?, description?, order?, seo_title?, seo_description? }
 *   PATCH  /sp/v1/admin/taxonomies/{tax}/{term_id}
 *   DELETE /sp/v1/admin/taxonomies/{tax}/{term_id}
 *   POST   /sp/v1/admin/taxonomies/{tax}/reorder        { ids: [] }
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Rest\BaseController;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminTaxonomiesController extends BaseController {

	public const TAXONOMIES = [ Offerings::TAXONOMY, ListingTypes::TAXONOMY ];

	public function __construct( private Offerings $offerings, private ListingTypes $types, private Urls $urls ) {}

	public function register_routes(): void {
		$perm = [ self::class, 'require_staff' ];
		$tax  = '(?P<tax>sp_offering|sp_listing_category)';
		$this->route( "/admin/taxonomies/{$tax}", [
			[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create' ], 'permission_callback' => $perm ],
		] );
		$this->route( "/admin/taxonomies/{$tax}/reorder", [ [ 'methods' => 'POST', 'callback' => [ $this, 'reorder' ], 'permission_callback' => $perm ] ] );
		$this->route( "/admin/taxonomies/{$tax}/(?P<term_id>\d+)", [
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'update' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'remove' ], 'permission_callback' => $perm ],
		] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( [ 'items' => $this->items( (string) $request['tax'] ) ] );
	}

	/** @return array<int, array<string, mixed>> */
	public function items( string $tax ): array {
		$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
		$out   = [];
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		foreach ( $terms as $t ) {
			$out[] = [
				'id'              => (int) $t->term_id,
				'name'            => (string) $t->name,
				'slug'            => (string) $t->slug,
				'description'     => (string) $t->description,
				'count'           => (int) $t->count,
				'order'           => (int) get_term_meta( $t->term_id, 'sp_order', true ),
				'seo_title'       => (string) get_term_meta( $t->term_id, 'sp_seo_title', true ),
				'seo_description' => (string) get_term_meta( $t->term_id, 'sp_seo_description', true ),
				'url'             => ListingTypes::TAXONOMY === $tax ? $this->urls->category( (string) $t->slug ) : $this->urls->directory( [ 'offerings' => (string) $t->slug ] ),
			];
		}
		usort( $out, static fn( $a, $b ) => [ $a['order'], $a['name'] ] <=> [ $b['order'], $b['name'] ] );
		return $out;
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tax  = (string) $request['tax'];
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return $this->fail( 'sp_name_required', __( 'Enter a name.', 'southpoll-membership' ), 422 );
		}
		$args = [ 'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ) ];
		if ( $request->get_param( 'slug' ) ) {
			$args['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
		}
		$res = wp_insert_term( $name, $tax, $args );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
		}
		$this->save_meta( (int) $res['term_id'], $request );
		$this->flush( $tax );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Added.', 'southpoll-membership' ), 'items' => $this->items( $tax ) ] );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tax  = (string) $request['tax'];
		$id   = (int) $request['term_id'];
		$term = get_term( $id, $tax );
		if ( ! $term instanceof \WP_Term ) {
			return $this->fail( 'sp_not_found', __( 'Term not found.', 'southpoll-membership' ), 404 );
		}
		$args = [];
		if ( $request->has_param( 'name' ) && '' !== sanitize_text_field( (string) $request->get_param( 'name' ) ) ) {
			$args['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}
		if ( $request->has_param( 'slug' ) && '' !== sanitize_title( (string) $request->get_param( 'slug' ) ) ) {
			$args['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
		}
		if ( $request->has_param( 'description' ) ) {
			$args['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		}
		if ( $args ) {
			$res = wp_update_term( $id, $tax, $args );
			if ( is_wp_error( $res ) ) {
				return $this->fail( $res->get_error_code(), $res->get_error_message(), 422 );
			}
		}
		$this->save_meta( $id, $request );
		$this->flush( $tax );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Saved.', 'southpoll-membership' ), 'items' => $this->items( $tax ) ] );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tax = (string) $request['tax'];
		$res = wp_delete_term( (int) $request['term_id'], $tax );
		if ( is_wp_error( $res ) || ! $res ) {
			return $this->fail( 'sp_delete_failed', is_wp_error( $res ) ? $res->get_error_message() : __( 'Could not delete that term.', 'southpoll-membership' ), 422 );
		}
		$this->flush( $tax );
		return $this->ok( [ 'ok' => true, 'message' => __( 'Deleted. Farms and listings that used it simply lose the tag.', 'southpoll-membership' ), 'items' => $this->items( $tax ) ] );
	}

	public function reorder( WP_REST_Request $request ): WP_REST_Response {
		$tax = (string) $request['tax'];
		foreach ( array_values( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) as $i => $id ) {
			update_term_meta( $id, 'sp_order', $i );
		}
		$this->flush( $tax );
		return $this->ok( [ 'ok' => true, 'items' => $this->items( $tax ) ] );
	}

	private function save_meta( int $id, WP_REST_Request $request ): void {
		if ( $request->has_param( 'order' ) ) {
			update_term_meta( $id, 'sp_order', max( 0, (int) $request->get_param( 'order' ) ) );
		}
		if ( $request->has_param( 'seo_title' ) ) {
			update_term_meta( $id, 'sp_seo_title', sanitize_text_field( (string) $request->get_param( 'seo_title' ) ) );
		}
		if ( $request->has_param( 'seo_description' ) ) {
			update_term_meta( $id, 'sp_seo_description', sanitize_textarea_field( (string) $request->get_param( 'seo_description' ) ) );
		}
	}

	private function flush( string $tax ): void {
		if ( Offerings::TAXONOMY === $tax ) {
			$this->offerings->flush();
		} else {
			$this->types->flush();
		}
	}
}
