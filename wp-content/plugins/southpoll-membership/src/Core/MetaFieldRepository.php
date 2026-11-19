<?php
/**
 * Admin-configured fields (sp_meta_fields) and listing field groups (sp_meta_field_groups).
 *   - scope "farm": flat list rendered on the My Farm editor and the public farm page.
 *   - scope "listing": fields belong to a group; a group applies to all categories or to chosen ones (spec: "field
 *     groups by category or all"), and renders as its own section on the listing editor and listing page.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

use SouthPoll\Membership\Support\Dates;
use WP_Error;

final class MetaFieldRepository {

	public const TYPES  = [ 'text', 'textarea', 'select', 'bool' ];
	public const SCOPES = [ 'farm', 'listing' ];

	/** @var array<string, object[]> */
	private array $cache = [];

	private function table(): string {
		return Schema::table( 'sp_meta_fields' );
	}

	private function groups_table(): string {
		return Schema::table( 'sp_meta_field_groups' );
	}

	/* ---------------------------------------------------------------- fields */

	/**
	 * @return object[] ordered by sort_order, decoded (options as array)
	 */
	public function for_scope( string $scope, bool $active_only = true ): array {
		global $wpdb;
		$key = 'f:' . $scope . ':' . ( $active_only ? '1' : '0' );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		$sql  = "SELECT * FROM {$this->table()} WHERE scope = %s" . ( $active_only ? ' AND active = 1' : '' ) . ' ORDER BY sort_order, id';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $scope ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$this->decode( $row );
		}
		return $this->cache[ $key ] = $rows;
	}

	/** @return object[] */
	public function fields_for_group( int $group_id, bool $active_only = true ): array {
		return array_values( array_filter( $this->for_scope( 'listing', $active_only ), static fn( $f ) => (int) $f->group_id === $group_id ) );
	}

	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->decode( $row ) : null;
	}

	public function find_by_key( string $scope, string $key ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE scope = %s AND field_key = %s", $scope, $key ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * @param array{label:string, type?:string, options?:string[]|string, default_value?:string, required?:bool, group_id?:int|null, field_key?:string} $in
	 */
	public function create( string $scope, array $in ): \stdClass|WP_Error {
		global $wpdb;
		$scope = in_array( $scope, self::SCOPES, true ) ? $scope : 'farm';
		$label = sanitize_text_field( (string) ( $in['label'] ?? '' ) );
		if ( '' === $label ) {
			return new WP_Error( 'sp_label_required', __( 'Please enter a field label.', 'southpoll-membership' ) );
		}
		if ( 'listing' === $scope && empty( $in['group_id'] ) ) {
			return new WP_Error( 'sp_group_required', __( 'Listing fields must belong to a field group.', 'southpoll-membership' ) );
		}
		$key = $this->unique_key( $scope, (string) ( $in['field_key'] ?? $label ) );
		$now = Dates::now();
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), -1) FROM {$this->table()} WHERE scope = %s", $scope ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert( $this->table(), array_merge( $this->normalize( $in ), [
			'scope'      => $scope,
			'field_key'  => $key,
			'label'      => $label,
			'sort_order' => $max + 1,
			'active'     => 1,
			'created_at' => $now,
			'updated_at' => $now,
		] ) );
		$this->cache = [];
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * @param array<string, mixed> $in label, type, options, default_value, required, active, group_id
	 */
	public function update( int $id, array $in ): bool {
		global $wpdb;
		$data = $this->normalize( $in );
		if ( isset( $in['label'] ) ) {
			$label = sanitize_text_field( (string) $in['label'] );
			if ( '' !== $label ) {
				$data['label'] = $label;
			}
		}
		if ( isset( $in['active'] ) ) {
			$data['active'] = (int) (bool) $in['active'];
		}
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = Dates::now();
		$ok                 = false !== $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
		$this->cache        = [];
		return $ok;
	}

	/** Delete the field definition and every stored value for it (farm meta rows or listing post meta). */
	public function delete( int $id ): bool {
		global $wpdb;
		$field = $this->find( $id );
		if ( ! $field ) {
			return false;
		}
		$wpdb->delete( $this->table(), [ 'id' => $id ] );
		if ( 'farm' === $field->scope ) {
			$wpdb->delete( Schema::table( 'sp_farm_meta' ), [ 'field_key' => $field->field_key ] );
		} else {
			delete_post_meta_by_key( '_sp_meta_' . $field->field_key ); // keeps the post meta cache in sync
		}
		$this->cache = [];
		return true;
	}

	/**
	 * @param int[] $ordered_ids
	 */
	public function reorder( string $scope, array $ordered_ids ): void {
		global $wpdb;
		foreach ( array_values( array_map( 'intval', $ordered_ids ) ) as $i => $id ) {
			$wpdb->update( $this->table(), [ 'sort_order' => $i, 'updated_at' => Dates::now() ], [ 'id' => $id, 'scope' => $scope ] );
		}
		$this->cache = [];
	}

	public function move( int $id, int $direction ): void {
		$field = $this->find( $id );
		if ( ! $field ) {
			return;
		}
		$siblings = 'listing' === $field->scope ? $this->fields_for_group( (int) $field->group_id, false ) : $this->for_scope( (string) $field->scope, false );
		$ids      = array_map( static fn( $f ) => (int) $f->id, $siblings );
		$pos      = array_search( $id, $ids, true );
		if ( false === $pos ) {
			return;
		}
		$new = $pos + $direction;
		if ( $new < 0 || $new >= count( $ids ) ) {
			return;
		}
		[ $ids[ $pos ], $ids[ $new ] ] = [ $ids[ $new ], $ids[ $pos ] ];
		$this->reorder( (string) $field->scope, $this->merge_order( (string) $field->scope, $ids ) );
	}

	/** Keep the other fields' relative order when reordering one group's slice. */
	private function merge_order( string $scope, array $slice ): array {
		$all   = array_map( static fn( $f ) => (int) $f->id, $this->for_scope( $scope, false ) );
		$set   = array_flip( $slice );
		$out   = [];
		$queue = $slice;
		foreach ( $all as $id ) {
			$out[] = isset( $set[ $id ] ) ? array_shift( $queue ) : $id;
		}
		return $out;
	}

	/* ---------------------------------------------------------------- groups */

	/** @return object[] groups for a scope in order (category_term_ids decoded) */
	public function groups( string $scope = 'listing' ): array {
		global $wpdb;
		$key = 'g:' . $scope;
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->groups_table()} WHERE scope = %s ORDER BY sort_order, id", $scope ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$this->decode_group( $row );
		}
		return $this->cache[ $key ] = $rows;
	}

	public function find_group( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->groups_table()} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->decode_group( $row ) : null;
	}

	/** @param int[] $category_ids empty = applies to every category */
	public function create_group( string $scope, string $title, array $category_ids = [] ): \stdClass|WP_Error {
		global $wpdb;
		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			return new WP_Error( 'sp_title_required', __( 'Please enter a group title.', 'southpoll-membership' ) );
		}
		$now = Dates::now();
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), -1) FROM {$this->groups_table()} WHERE scope = %s", $scope ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert( $this->groups_table(), [ 'scope' => in_array( $scope, self::SCOPES, true ) ? $scope : 'listing', 'title' => $title, 'category_term_ids' => wp_json_encode( array_values( array_unique( array_filter( array_map( 'intval', $category_ids ) ) ) ) ), 'sort_order' => $max + 1, 'created_at' => $now, 'updated_at' => $now ] );
		$this->cache = [];
		return $this->find_group( (int) $wpdb->insert_id );
	}

	/** @param int[]|null $category_ids */
	public function update_group( int $id, ?string $title = null, ?array $category_ids = null ): bool {
		global $wpdb;
		$data = [];
		if ( null !== $title && '' !== sanitize_text_field( $title ) ) {
			$data['title'] = sanitize_text_field( $title );
		}
		if ( null !== $category_ids ) {
			$data['category_term_ids'] = wp_json_encode( array_values( array_unique( array_filter( array_map( 'intval', $category_ids ) ) ) ) );
		}
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = Dates::now();
		$ok                 = false !== $wpdb->update( $this->groups_table(), $data, [ 'id' => $id ] );
		$this->cache        = [];
		return $ok;
	}

	/** Deletes the group, its fields and their stored values. */
	public function delete_group( int $id ): bool {
		global $wpdb;
		$group = $this->find_group( $id );
		if ( ! $group ) {
			return false;
		}
		foreach ( $this->fields_for_group( $id, false ) as $f ) {
			$this->delete( (int) $f->id );
		}
		$wpdb->delete( $this->groups_table(), [ 'id' => $id ] );
		$this->cache = [];
		return true;
	}

	public function move_group( int $id, int $direction ): void {
		global $wpdb;
		$group = $this->find_group( $id );
		if ( ! $group ) {
			return;
		}
		$ids = array_map( static fn( $g ) => (int) $g->id, $this->groups( (string) $group->scope ) );
		$pos = array_search( $id, $ids, true );
		$new = false === $pos ? -1 : $pos + $direction;
		if ( false === $pos || $new < 0 || $new >= count( $ids ) ) {
			return;
		}
		[ $ids[ $pos ], $ids[ $new ] ] = [ $ids[ $new ], $ids[ $pos ] ];
		foreach ( $ids as $i => $gid ) {
			$wpdb->update( $this->groups_table(), [ 'sort_order' => $i ], [ 'id' => $gid ] );
		}
		$this->cache = [];
	}

	/**
	 * Groups that apply to a listing with these categories (no categories on the group = applies to all).
	 *
	 * @param int[] $category_ids
	 * @return object[]
	 */
	public function groups_for_categories( array $category_ids, string $scope = 'listing' ): array {
		$category_ids = array_map( 'intval', $category_ids );
		return array_values( array_filter( $this->groups( $scope ), static fn( $g ) => empty( $g->category_term_ids ) || array_intersect( $g->category_term_ids, $category_ids ) ) );
	}

	/* ---------------------------------------------------------------- helpers */

	/**
	 * Sanitize a submitted value for a field definition. Returns null for "unset".
	 */
	public static function sanitize_value( object $field, mixed $raw ): ?string {
		return match ( (string) $field->type ) {
			'bool'     => ( is_string( $raw ) && in_array( strtolower( $raw ), [ '1', 'yes', 'true', 'on' ], true ) ) || true === $raw || 1 === $raw ? '1' : '0',
			'select'   => in_array( (string) $raw, (array) $field->options, true ) ? (string) $raw : null,
			'textarea' => '' === trim( (string) $raw ) ? null : sanitize_textarea_field( (string) $raw ),
			default    => '' === trim( (string) $raw ) ? null : sanitize_text_field( (string) $raw ),
		};
	}

	/** Human display for a stored value. */
	public static function display_value( object $field, ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( 'bool' === $field->type ) {
			return '1' === $value ? __( 'Yes', 'southpoll-membership' ) : __( 'No', 'southpoll-membership' );
		}
		return $value;
	}

	private function unique_key( string $scope, string $base ): string {
		global $wpdb;
		$slug = str_replace( '-', '_', sanitize_title( $base ) ) ?: 'field';
		$slug = substr( $slug, 0, 90 );
		$try  = $slug;
		$i    = 2;
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE scope = %s AND field_key = %s", $scope, $try ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$try = $slug . '_' . $i++;
		}
		return $try;
	}

	/** @return array<string, mixed> */
	private function normalize( array $in ): array {
		$data = [];
		if ( isset( $in['type'] ) ) {
			$data['type'] = in_array( $in['type'], self::TYPES, true ) ? $in['type'] : 'text';
		}
		if ( array_key_exists( 'options', $in ) ) {
			$opts = is_array( $in['options'] ) ? $in['options'] : preg_split( '/\r\n|\r|\n|,/', (string) $in['options'] );
			$opts = array_values( array_unique( array_filter( array_map( static fn( $o ) => sanitize_text_field( trim( (string) $o ) ), (array) $opts ), static fn( $o ) => '' !== $o ) ) );
			$data['options'] = $opts ? wp_json_encode( $opts ) : null;
		}
		if ( array_key_exists( 'default_value', $in ) ) {
			$data['default_value'] = '' === trim( (string) $in['default_value'] ) ? null : sanitize_text_field( (string) $in['default_value'] );
		}
		if ( array_key_exists( 'required', $in ) ) {
			$data['required'] = (int) (bool) $in['required'];
		}
		if ( array_key_exists( 'group_id', $in ) ) {
			$data['group_id'] = $in['group_id'] ? (int) $in['group_id'] : null;
		}
		return $data;
	}

	private function decode( object $row ): object {
		$opts         = json_decode( (string) ( $row->options ?? '' ), true );
		$row->options = is_array( $opts ) ? array_values( array_map( 'strval', $opts ) ) : [];
		return $row;
	}

	private function decode_group( object $row ): object {
		$ids                    = json_decode( (string) ( $row->category_term_ids ?? '' ), true );
		$row->category_term_ids = is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : [];
		return $row;
	}
}
