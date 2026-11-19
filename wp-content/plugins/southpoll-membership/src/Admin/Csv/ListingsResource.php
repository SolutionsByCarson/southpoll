<?php
/**
 * Listings CSV: export with farm, categories, price, dates, views and every admin-defined meta_* column; import
 * updates by listing_id or creates for a farm_id (meta_* columns map to the configured listing fields).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Csv;

use SouthPoll\Membership\Admin\Tables\AdminTables;
use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Farms\FarmRepository;
use SouthPoll\Membership\Listings\ListingRepository;
use SouthPoll\Membership\Listings\ListingService;
use SouthPoll\Membership\Listings\ListingTypes;
use SouthPoll\Membership\Members\ContextProvider;
use SouthPoll\Membership\Routing\Urls;
use WP_Error;

final class ListingsResource extends Resource {

	public function __construct( private AdminTables $tables, private ListingRepository $listings, private ListingService $service, private ListingTypes $types, private FarmRepository $farms, private MetaFieldRepository $fields, private ContextProvider $contexts, private Urls $urls ) {}

	public function name(): string {
		return 'listings';
	}

	public function label(): string {
		return __( 'Listings', 'southpoll-membership' );
	}

	public function columns(): array {
		$cols = [ 'listing_id' => 'listing_id', 'farm_id' => 'farm_id', 'farm' => 'farm', 'title' => 'title', 'categories' => 'categories', 'status' => 'status', 'visibility' => 'visibility', 'price' => 'price', 'price_label' => 'price_label', 'excerpt' => 'excerpt', 'content' => 'content', 'published_at' => 'published_at', 'expires_at' => 'expires_at' ];
		foreach ( $this->fields->for_scope( 'listing', false ) as $f ) {
			$cols[ 'meta_' . $f->field_key ] = 'meta_' . $f->field_key;
		}
		return $cols + [ 'views' => 'views', 'renewed' => 'renewed', 'url' => 'url', 'created' => 'created' ];
	}

	public function export_only(): array {
		return [ 'farm', 'views', 'renewed', 'url', 'created' ];
	}

	public function required(): array {
		return [ 'title' ];
	}

	public function example(): array {
		return [ 'listing_id' => '', 'farm_id' => '12', 'title' => 'Registered South Poll bull', 'categories' => 'bulls', 'status' => 'available', 'visibility' => 'live', 'price' => '2500', 'price_label' => 'OBO', 'excerpt' => 'Short summary', 'content' => '<p>Details.</p>', 'published_at' => '', 'expires_at' => '' ];
	}

	public function export( array $args ): iterable {
		$r = $this->tables->listings( $args + [ 'per_page' => 'all' ] );
		foreach ( $this->listings->find_many( $r['ids'] ) as $l ) {
			$farm = $this->farms->find( $l->farm_id );
			$row  = [ 'listing_id' => $l->id, 'farm_id' => $l->farm_id, 'farm' => $farm ? $farm->name : '', 'title' => $l->title, 'categories' => implode( '|', array_map( static fn( $c ) => $c->slug, $l->categories ) ), 'status' => $l->status, 'visibility' => 'publish' === $l->post_status ? 'live' : ( 'sp_expired' === $l->post_status ? 'expired' : 'draft' ), 'price' => null !== $l->price_cents ? number_format( $l->price_cents / 100, 2, '.', '' ) : '', 'price_label' => $l->price_label, 'excerpt' => $l->excerpt, 'content' => $l->content, 'published_at' => $l->published_at, 'expires_at' => $l->expires_at ];
			foreach ( $this->fields->for_scope( 'listing', false ) as $f ) {
				$row[ 'meta_' . $f->field_key ] = $l->meta[ $f->field_key ] ?? '';
			}
			yield $row + [ 'views' => $l->view_count, 'renewed' => $l->renewed_count, 'url' => $this->urls->listing( $l->slug ), 'created' => $l->created_at ];
		}
	}

	public function validate( array $row ): array {
		$errors  = [];
		$id      = (int) ( $row['listing_id'] ?? 0 );
		$listing = $id ? $this->listings->find( $id ) : null;
		if ( $id && ! $listing ) {
			$errors[] = sprintf( /* translators: %d: id */ __( 'listing_id %d does not exist', 'southpoll-membership' ), $id );
		}
		if ( ! $listing && ! $this->farms->find( (int) ( $row['farm_id'] ?? 0 ) ) ) {
			$errors[] = __( 'farm_id must match an existing farm for a new listing', 'southpoll-membership' );
		}
		if ( ! $listing && ! self::has( $row, 'title' ) ) {
			$errors[] = __( 'title is required', 'southpoll-membership' );
		}
		if ( self::has( $row, 'categories' ) ) {
			foreach ( array_filter( array_map( 'trim', explode( '|', (string) $row['categories'] ) ) ) as $slug ) {
				if ( ! $this->types->category( $slug ) ) {
					$errors[] = sprintf( /* translators: %s: slug */ __( 'unknown category "%s"', 'southpoll-membership' ), $slug );
				}
			}
		} elseif ( ! $listing ) {
			$errors[] = __( 'categories is required (pipe-separated slugs)', 'southpoll-membership' );
		}
		if ( self::has( $row, 'status' ) && ! in_array( strtolower( trim( (string) $row['status'] ) ), ListingTypes::STATUSES, true ) ) {
			$errors[] = __( 'status must be available, coming_soon, pending, sold or inactive', 'southpoll-membership' );
		}
		if ( self::has( $row, 'visibility' ) && ! in_array( strtolower( trim( (string) $row['visibility'] ) ), [ 'live', 'draft', 'expired' ], true ) ) {
			$errors[] = __( 'visibility must be live, draft or expired', 'southpoll-membership' );
		}
		return [ 'action' => $listing ? 'update' : 'create', 'errors' => $errors, 'key' => $listing ? '#' . $listing->id . ' ' . $listing->title : (string) ( $row['title'] ?? '' ) ];
	}

	public function import( array $row ): array|WP_Error {
		$ctx     = $this->contexts->current();
		$id      = (int) ( $row['listing_id'] ?? 0 );
		$listing = $id ? $this->listings->find( $id ) : null;
		$action  = $listing ? 'update' : 'create';
		if ( ! $listing ) {
			$listing = $this->listings->create( [ 'farm_id' => (int) $row['farm_id'], 'author_id' => get_current_user_id(), 'title' => (string) $row['title'] ] );
		}
		$data = [];
		foreach ( [ 'title', 'excerpt', 'content', 'price_label', 'status' ] as $k ) {
			if ( array_key_exists( $k, $row ) && ( 'title' !== $k || '' !== trim( (string) $row[ $k ] ) ) ) {
				$data[ $k ] = (string) $row[ $k ];
			}
		}
		if ( array_key_exists( 'price', $row ) ) {
			$raw                 = str_replace( [ '$', ',' ], '', trim( (string) $row['price'] ) );
			$data['price_cents'] = '' === $raw || ! is_numeric( $raw ) ? null : (int) round( (float) $raw * 100 );
		}
		if ( self::has( $row, 'categories' ) ) {
			$data['categories'] = array_map( fn( $slug ) => $this->types->category( trim( $slug ) )->id, array_filter( array_map( 'trim', explode( '|', (string) $row['categories'] ) ) ) );
		}
		$meta = [];
		foreach ( $this->fields->for_scope( 'listing', false ) as $f ) {
			if ( array_key_exists( 'meta_' . $f->field_key, $row ) ) {
				$meta[ $f->field_key ] = MetaFieldRepository::sanitize_value( $f, $row[ 'meta_' . $f->field_key ] );
			}
		}
		if ( $meta ) {
			$data['meta'] = $meta;
		}
		$visibility = strtolower( trim( (string) ( $row['visibility'] ?? '' ) ) );
		$act        = 'live' === $visibility ? 'publish' : ( 'draft' === $visibility ? 'draft' : 'save' );
		$res        = $this->service->save( $ctx, $listing, $data, $act );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$dates = [];
		if ( self::has( $row, 'published_at' ) && self::date( $row['published_at'] ) ) {
			$dates['published_at'] = self::date( $row['published_at'] );
			$dates['post_date']    = $dates['published_at'];
		}
		if ( self::has( $row, 'expires_at' ) && self::date( $row['expires_at'] ) ) {
			$dates['expires_at'] = self::date( $row['expires_at'] );
		}
		if ( 'expired' === $visibility ) {
			$dates['post_status']   = ListingTypes::STATUS_EXPIRED;
			$dates['hidden_reason'] = 'expired';
		}
		if ( $dates ) {
			$this->listings->update( $res->id, $dates );
			$this->service->refresh_farm_stats( $res->farm_id );
		}
		return [ 'action' => $action, 'id' => $res->id ];
	}
}
