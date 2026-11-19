<?php
/**
 * Offerings taxonomy (`sp_offering`) attached to farms. Terms are WordPress terms so staff manage them with
 * the familiar tags screen (linked from the SouthPoll menu); relationships are stored in wp_term_relationships
 * with the farm id as object id (object type `sp_farm`, which has no posts, so ids never collide in practice).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Service;

final class Offerings implements Service {

	public const TAXONOMY    = 'sp_offering';
	public const OBJECT_TYPE = 'sp_farm';
	public const DEFAULTS    = [ 'Bulls', 'Cows', 'Heifers', 'Steers', 'Semen' ]; // same classes as the listings (S5, 2026-11-18)

	/** @var array<int, object>|null */
	private ?array $cache = null;

	public function register(): void {
		add_action( 'init', [ self::class, 'register_taxonomy' ], 6 );
		add_action( 'admin_menu', [ $this, 'admin_menu' ], 20 );
		add_filter( 'parent_file', [ $this, 'highlight_menu' ] );
		add_filter( 'submenu_file', [ $this, 'highlight_submenu' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'flush' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'flush' ] );
		add_action( 'delete_' . self::TAXONOMY, [ $this, 'flush' ] );
		add_action( self::TAXONOMY . '_add_form_fields', [ $this, 'order_field_add' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ $this, 'order_field_edit' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'save_order' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'save_order' ] );
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', [ $this, 'columns' ] );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', [ $this, 'column_value' ], 10, 3 );
	}

	public static function register_taxonomy(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		register_taxonomy(
			self::TAXONOMY,
			self::OBJECT_TYPE,
			[
				'labels'                => [
					'name'          => _x( 'Offerings', 'taxonomy general name', 'southpoll-membership' ),
					'singular_name' => _x( 'Offering', 'taxonomy singular name', 'southpoll-membership' ),
					'menu_name'     => __( 'Offerings', 'southpoll-membership' ),
					'all_items'     => __( 'All offerings', 'southpoll-membership' ),
					'edit_item'     => __( 'Edit offering', 'southpoll-membership' ),
					'update_item'   => __( 'Update offering', 'southpoll-membership' ),
					'add_new_item'  => __( 'Add new offering', 'southpoll-membership' ),
					'new_item_name' => __( 'New offering name', 'southpoll-membership' ),
					'search_items'  => __( 'Search offerings', 'southpoll-membership' ),
					'not_found'     => __( 'No offerings found.', 'southpoll-membership' ),
				],
				'description'           => __( 'What a member farm offers (shown as pills on farm pages, filterable in the directory).', 'southpoll-membership' ),
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => true,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'show_admin_column'     => false,
				'show_tagcloud'         => false,
				'hierarchical'          => false,
				'rewrite'               => false,
				'query_var'             => false,
				'update_count_callback' => '_update_generic_term_count',
				'capabilities'          => [
					'manage_terms' => Capabilities::CAP_MANAGE,
					'edit_terms'   => Capabilities::CAP_MANAGE,
					'delete_terms' => Capabilities::CAP_MANAGE,
					'assign_terms' => Capabilities::CAP_MANAGE,
				],
			]
		);
	}

	/* ------------------------------------------------------------------ admin */

	public function admin_menu(): void {
		add_submenu_page(
			'southpoll',
			__( 'Offerings', 'southpoll-membership' ),
			__( 'Offerings', 'southpoll-membership' ),
			Capabilities::CAP_MANAGE,
			'edit-tags.php?taxonomy=' . self::TAXONOMY
		);
	}

	public function highlight_menu( string $parent ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && self::TAXONOMY === $screen->taxonomy ) ? 'southpoll' : $parent;
	}

	public function highlight_submenu( ?string $submenu ): ?string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && self::TAXONOMY === $screen->taxonomy ) ? 'edit-tags.php?taxonomy=' . self::TAXONOMY : $submenu;
	}

	public function order_field_add(): void {
		echo '<div class="form-field"><label for="sp_order">' . esc_html__( 'Display order', 'southpoll-membership' ) . '</label><input type="number" name="sp_order" id="sp_order" value="0" min="0" style="width:90px"><p>' . esc_html__( 'Lower numbers show first in filters and on farm pages.', 'southpoll-membership' ) . '</p></div>';
	}

	public function order_field_edit( \WP_Term $term ): void {
		$order = (int) get_term_meta( $term->term_id, 'sp_order', true );
		echo '<tr class="form-field"><th scope="row"><label for="sp_order">' . esc_html__( 'Display order', 'southpoll-membership' ) . '</label></th><td><input type="number" name="sp_order" id="sp_order" value="' . esc_attr( (string) $order ) . '" min="0" style="width:90px"><p class="description">' . esc_html__( 'Lower numbers show first in filters and on farm pages.', 'southpoll-membership' ) . '</p></td></tr>';
	}

	public function save_order( int $term_id ): void {
		if ( isset( $_POST['sp_order'] ) && current_user_can( Capabilities::CAP_MANAGE ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core term forms are nonce-checked before these hooks fire
			update_term_meta( $term_id, 'sp_order', max( 0, (int) $_POST['sp_order'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$this->flush();
	}

	/** @param array<string, string> $columns */
	public function columns( array $columns ): array {
		unset( $columns['posts'] );
		$columns['sp_order'] = __( 'Order', 'southpoll-membership' );
		$columns['sp_farms'] = __( 'Farms', 'southpoll-membership' );
		return $columns;
	}

	public function column_value( string $out, string $column, int $term_id ): string {
		if ( 'sp_order' === $column ) {
			return esc_html( (string) (int) get_term_meta( $term_id, 'sp_order', true ) );
		}
		if ( 'sp_farms' === $column ) {
			$term = get_term( $term_id, self::TAXONOMY );
			return $term instanceof \WP_Term ? esc_html( (string) $term->count ) : '0';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ data */

	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * All offerings in display order.
	 *
	 * @return array<int, object{id:int, name:string, slug:string, count:int, order:int}>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		self::register_taxonomy();
		$terms = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
		$out   = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[] = (object) [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug, 'count' => (int) $t->count, 'order' => (int) get_term_meta( $t->term_id, 'sp_order', true ) ];
			}
			usort( $out, static fn( $a, $b ) => [ $a->order, $a->name ] <=> [ $b->order, $b->name ] );
		}
		return $this->cache = $out;
	}

	/** @return int[] term ids */
	public function ids_for_farm( int $farm_id ): array {
		$ids = wp_get_object_terms( $farm_id, self::TAXONOMY, [ 'fields' => 'ids' ] );
		return is_wp_error( $ids ) ? [] : array_map( 'intval', $ids );
	}

	/** @return array<int, object> offerings of a farm in display order */
	public function for_farm( int $farm_id ): array {
		$ids = $this->ids_for_farm( $farm_id );
		return array_values( array_filter( $this->all(), static fn( $o ) => in_array( $o->id, $ids, true ) ) );
	}

	/**
	 * Offerings for many farms at once (directory cards).
	 *
	 * @param int[] $farm_ids
	 * @return array<int, array<int, object>> farm_id => offerings
	 */
	public function for_farms( array $farm_ids ): array {
		global $wpdb;
		$farm_ids = array_values( array_unique( array_map( 'intval', $farm_ids ) ) );
		if ( empty( $farm_ids ) ) {
			return [];
		}
		$by_term = [];
		foreach ( $this->all() as $o ) {
			$by_term[ $o->id ] = $o;
		}
		$placeholders = implode( ',', array_fill( 0, count( $farm_ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id IN ({$placeholders})", array_merge( [ self::TAXONOMY ], $farm_ids ) ) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out          = array_fill_keys( $farm_ids, [] );
		foreach ( $rows as $r ) {
			if ( isset( $by_term[ (int) $r->term_id ] ) ) {
				$out[ (int) $r->object_id ][] = $by_term[ (int) $r->term_id ];
			}
		}
		foreach ( $out as &$list ) {
			usort( $list, static fn( $a, $b ) => [ $a->order, $a->name ] <=> [ $b->order, $b->name ] );
		}
		return $out;
	}

	/**
	 * Replace a farm's offerings. Accepts term ids or slugs; unknown values are ignored (never auto-created).
	 *
	 * @param array<int, int|string> $values
	 */
	public function set_for_farm( int $farm_id, array $values ): void {
		$ids = [];
		foreach ( $this->all() as $o ) {
			if ( in_array( $o->id, array_map( 'intval', $values ), true ) || in_array( $o->slug, array_map( 'strval', $values ), true ) ) {
				$ids[] = $o->id;
			}
		}
		wp_set_object_terms( $farm_id, $ids, self::TAXONOMY, false );
		clean_object_term_cache( $farm_id, self::OBJECT_TYPE );
		$this->flush();
	}

	/**
	 * Farm ids that have ANY of the given offering slugs.
	 *
	 * @param string[] $slugs
	 * @return int[]
	 */
	public function farm_ids_with( array $slugs ): array {
		global $wpdb;
		$slugs = array_values( array_filter( array_map( 'sanitize_title', $slugs ) ) );
		if ( empty( $slugs ) ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND t.slug IN ({$placeholders})", array_merge( [ self::TAXONOMY ], $slugs ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
