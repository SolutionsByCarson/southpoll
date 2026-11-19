<?php
/**
 * Classified listings: the `sp_listing` post type, the `sp_listing_category` taxonomy (with SEO + order term meta,
 * managed under SouthPoll → Listing Categories) and the `sp_expired` post status.
 *
 * Visibility is never decided by post status alone (roadmap §2.3): `publish` = may be shown, `draft` = inactive /
 * withdrawn / hidden for membership or sold-window reasons, `sp_expired` = past its 60-day life. Listing::is_public()
 * and ListingQuery apply the full rule.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Service;

final class ListingTypes implements Service {

	public const POST_TYPE      = 'sp_listing';
	public const TAXONOMY       = 'sp_listing_category';
	public const STATUS_EXPIRED = 'sp_expired';

	/** Member-facing listing statuses (stored in `_sp_status`). */
	public const STATUSES = [ 'available', 'coming_soon', 'pending', 'sold', 'inactive' ];

	/** Statuses that count against the farm's listing limit and light the directory "green" light (D-18). */
	public const ACTIVE_STATUSES = [ 'available', 'coming_soon', 'pending' ];

	/** Listing classes (Nathan, 2026-11-18): existing sites are converted by `temp/scripts/recategorize-listings.php`. */
	public const DEFAULT_CATEGORIES = [ 'Bulls', 'Cows', 'Heifers', 'Steers', 'Semen' ];

	/** Category slugs that can never exist because they collide with routes. */
	public const RESERVED_SLUGS = [ 'listing', 'page' ];

	/** @var array<int, object>|null */
	private ?array $cache = null;

	public function register(): void {
		add_action( 'init', [ self::class, 'register_types' ], 6 );
		add_action( 'admin_menu', [ $this, 'admin_menu' ], 20 );
		add_filter( 'parent_file', [ $this, 'highlight_menu' ] );
		add_filter( 'submenu_file', [ $this, 'highlight_submenu' ] );
		add_filter( 'pre_insert_term', [ $this, 'reject_reserved' ], 10, 2 );
		add_filter( 'wp_update_term_data', [ $this, 'reject_reserved_update' ], 10, 3 );
		foreach ( [ 'created_', 'edited_', 'delete_' ] as $prefix ) {
			add_action( $prefix . self::TAXONOMY, [ $this, 'flush' ] );
		}
		add_action( self::TAXONOMY . '_add_form_fields', [ $this, 'term_fields_add' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ $this, 'term_fields_edit' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'save_term_fields' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'save_term_fields' ] );
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', [ $this, 'columns' ] );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', [ $this, 'column_value' ], 10, 3 );
	}

	public static function register_types(): void {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			register_post_type(
				self::POST_TYPE,
				[
					'labels'              => [ 'name' => __( 'Classified Listings', 'southpoll-membership' ), 'singular_name' => __( 'Listing', 'southpoll-membership' ) ],
					'public'              => false,
					'publicly_queryable'  => false,
					'exclude_from_search' => true,
					'show_ui'             => false,
					'show_in_menu'        => false,
					'show_in_rest'        => false,
					'has_archive'         => false,
					'rewrite'             => false,
					'query_var'           => false,
					'hierarchical'        => false,
					'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'author' ],
					'taxonomies'          => [ self::TAXONOMY ],
					'can_export'          => true,
					'delete_with_user'    => false,
					'map_meta_cap'        => true,
					'capability_type'     => 'post',
				]
			);
		}
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			register_taxonomy(
				self::TAXONOMY,
				self::POST_TYPE,
				[
					'labels'             => [
						'name'          => _x( 'Listing Categories', 'taxonomy general name', 'southpoll-membership' ),
						'singular_name' => _x( 'Listing Category', 'taxonomy singular name', 'southpoll-membership' ),
						'menu_name'     => __( 'Listing Categories', 'southpoll-membership' ),
						'all_items'     => __( 'All categories', 'southpoll-membership' ),
						'edit_item'     => __( 'Edit category', 'southpoll-membership' ),
						'update_item'   => __( 'Update category', 'southpoll-membership' ),
						'add_new_item'  => __( 'Add new category', 'southpoll-membership' ),
						'new_item_name' => __( 'New category name', 'southpoll-membership' ),
						'search_items'  => __( 'Search categories', 'southpoll-membership' ),
						'not_found'     => __( 'No categories found.', 'southpoll-membership' ),
					],
					'description'        => __( 'Classified listing categories. Each has its own archive at /classifieds/{slug}/ with an SEO title and description.', 'southpoll-membership' ),
					'public'             => false,
					'publicly_queryable' => false,
					'show_ui'            => true,
					'show_in_menu'       => false,
					'show_in_nav_menus'  => false,
					'show_in_rest'       => false,
					'show_admin_column'  => false,
					'show_tagcloud'      => false,
					'hierarchical'       => false,
					'rewrite'            => false,
					'query_var'          => false,
					'capabilities'       => [
						'manage_terms' => Capabilities::CAP_MANAGE,
						'edit_terms'   => Capabilities::CAP_MANAGE,
						'delete_terms' => Capabilities::CAP_MANAGE,
						'assign_terms' => Capabilities::CAP_MANAGE,
					],
				]
			);
		}
		register_post_status( self::STATUS_EXPIRED, [
			'label'                     => _x( 'Expired', 'listing status', 'southpoll-membership' ),
			'public'                    => false,
			'internal'                  => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => false,
		] );
	}

	/* ------------------------------------------------------------------ admin */

	public function admin_menu(): void {
		add_submenu_page( 'southpoll', __( 'Listing Categories', 'southpoll-membership' ), __( 'Listing Categories', 'southpoll-membership' ), Capabilities::CAP_MANAGE, 'edit-tags.php?taxonomy=' . self::TAXONOMY );
	}

	public function highlight_menu( string $parent ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && self::TAXONOMY === $screen->taxonomy ) ? 'southpoll' : $parent;
	}

	public function highlight_submenu( ?string $submenu ): ?string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && self::TAXONOMY === $screen->taxonomy ) ? 'edit-tags.php?taxonomy=' . self::TAXONOMY : $submenu;
	}

	/** @return string|\WP_Error */
	public function reject_reserved( $term, string $taxonomy ) {
		if ( self::TAXONOMY === $taxonomy && is_string( $term ) && in_array( sanitize_title( $term ), self::RESERVED_SLUGS, true ) ) {
			return new \WP_Error( 'sp_reserved_slug', sprintf( /* translators: %s: slug */ __( '"%s" is reserved for the classifieds URLs. Please choose another name.', 'southpoll-membership' ), $term ) );
		}
		return $term;
	}

	/** @param array<string, mixed> $data */
	public function reject_reserved_update( array $data, int $term_id, string $taxonomy ): array {
		if ( self::TAXONOMY === $taxonomy && in_array( sanitize_title( (string) ( $data['slug'] ?? '' ) ), self::RESERVED_SLUGS, true ) ) {
			$data['slug'] = sanitize_title( (string) $data['name'] ) . '-category';
		}
		return $data;
	}

	public function term_fields_add(): void {
		echo '<div class="form-field"><label for="sp_order">' . esc_html__( 'Display order', 'southpoll-membership' ) . '</label><input type="number" name="sp_order" id="sp_order" value="0" min="0" style="width:90px"></div>';
		echo '<div class="form-field"><label for="sp_seo_title">' . esc_html__( 'SEO title', 'southpoll-membership' ) . '</label><input type="text" name="sp_seo_title" id="sp_seo_title"><p>' . esc_html__( 'Browser/search title for the category archive. Blank = "{Category} – Classifieds".', 'southpoll-membership' ) . '</p></div>';
		echo '<div class="form-field"><label for="sp_seo_description">' . esc_html__( 'SEO description', 'southpoll-membership' ) . '</label><textarea name="sp_seo_description" id="sp_seo_description" rows="3"></textarea><p>' . esc_html__( 'Meta description for the category archive (about 150 characters).', 'southpoll-membership' ) . '</p></div>';
	}

	public function term_fields_edit( \WP_Term $term ): void {
		$order = (int) get_term_meta( $term->term_id, 'sp_order', true );
		echo '<tr class="form-field"><th scope="row"><label for="sp_order">' . esc_html__( 'Display order', 'southpoll-membership' ) . '</label></th><td><input type="number" name="sp_order" id="sp_order" value="' . esc_attr( (string) $order ) . '" min="0" style="width:90px"></td></tr>';
		echo '<tr class="form-field"><th scope="row"><label for="sp_seo_title">' . esc_html__( 'SEO title', 'southpoll-membership' ) . '</label></th><td><input type="text" name="sp_seo_title" id="sp_seo_title" value="' . esc_attr( (string) get_term_meta( $term->term_id, 'sp_seo_title', true ) ) . '"><p class="description">' . esc_html__( 'Browser/search title for the category archive. Blank = "{Category} – Classifieds".', 'southpoll-membership' ) . '</p></td></tr>';
		echo '<tr class="form-field"><th scope="row"><label for="sp_seo_description">' . esc_html__( 'SEO description', 'southpoll-membership' ) . '</label></th><td><textarea name="sp_seo_description" id="sp_seo_description" rows="3">' . esc_textarea( (string) get_term_meta( $term->term_id, 'sp_seo_description', true ) ) . '</textarea><p class="description">' . esc_html__( 'Meta description for the category archive.', 'southpoll-membership' ) . '</p></td></tr>';
	}

	public function save_term_fields( int $term_id ): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core term forms are nonce-checked before these hooks fire
		if ( isset( $_POST['sp_order'] ) ) {
			update_term_meta( $term_id, 'sp_order', max( 0, (int) $_POST['sp_order'] ) );
		}
		if ( isset( $_POST['sp_seo_title'] ) ) {
			update_term_meta( $term_id, 'sp_seo_title', sanitize_text_field( wp_unslash( $_POST['sp_seo_title'] ) ) );
		}
		if ( isset( $_POST['sp_seo_description'] ) ) {
			update_term_meta( $term_id, 'sp_seo_description', sanitize_textarea_field( wp_unslash( $_POST['sp_seo_description'] ) ) );
		}
		// phpcs:enable
		$this->flush();
	}

	/** @param array<string, string> $columns */
	public function columns( array $columns ): array {
		unset( $columns['posts'] );
		$columns['sp_order']    = __( 'Order', 'southpoll-membership' );
		$columns['sp_listings'] = __( 'Live listings', 'southpoll-membership' );
		$columns['sp_seo']      = __( 'SEO title', 'southpoll-membership' );
		return $columns;
	}

	public function column_value( string $out, string $column, int $term_id ): string {
		return match ( $column ) {
			'sp_order'    => esc_html( (string) (int) get_term_meta( $term_id, 'sp_order', true ) ),
			'sp_listings' => esc_html( (string) ( ( $t = get_term( $term_id, self::TAXONOMY ) ) instanceof \WP_Term ? $t->count : 0 ) ),
			'sp_seo'      => esc_html( (string) get_term_meta( $term_id, 'sp_seo_title', true ) ),
			default       => $out,
		};
	}

	/* ------------------------------------------------------------------- data */

	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * All categories in display order.
	 *
	 * @return array<int, object{id:int, name:string, slug:string, count:int, order:int, description:string, seo_title:string, seo_description:string}>
	 */
	public function categories(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		self::register_types();
		$terms = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
		$out   = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[] = (object) [
					'id'              => (int) $t->term_id,
					'name'            => (string) $t->name,
					'slug'            => (string) $t->slug,
					'count'           => (int) $t->count,
					'order'           => (int) get_term_meta( $t->term_id, 'sp_order', true ),
					'description'     => (string) $t->description,
					'seo_title'       => (string) get_term_meta( $t->term_id, 'sp_seo_title', true ),
					'seo_description' => (string) get_term_meta( $t->term_id, 'sp_seo_description', true ),
				];
			}
			usort( $out, static fn( $a, $b ) => [ $a->order, $a->name ] <=> [ $b->order, $b->name ] );
		}
		return $this->cache = $out;
	}

	public function category( string $slug ): ?object {
		foreach ( $this->categories() as $c ) {
			if ( $c->slug === sanitize_title( $slug ) ) {
				return $c;
			}
		}
		return null;
	}

	/** @param int[] $ids @return object[] */
	public function categories_by_ids( array $ids ): array {
		$ids = array_map( 'intval', $ids );
		return array_values( array_filter( $this->categories(), static fn( $c ) => in_array( $c->id, $ids, true ) ) );
	}

	/** @return array<string, string> status => label */
	public static function status_labels(): array {
		return [
			'available'   => __( 'Available', 'southpoll-membership' ),
			'coming_soon' => __( 'Coming soon', 'southpoll-membership' ),
			'pending'     => __( 'Sale pending', 'southpoll-membership' ),
			'sold'        => __( 'Sold', 'southpoll-membership' ),
			'inactive'    => __( 'Inactive', 'southpoll-membership' ),
			'expired'     => __( 'Expired', 'southpoll-membership' ),
		];
	}

	/** Labels for the stored statuses only (editor select, My Listings quick change). */
	public static function selectable_status_labels(): array {
		return array_intersect_key( self::status_labels(), array_flip( self::STATUSES ) );
	}

	public static function status_label( string $status ): string {
		if ( 'draft' === $status ) {
			return __( 'Draft', 'southpoll-membership' );
		}
		return self::status_labels()[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}
}
