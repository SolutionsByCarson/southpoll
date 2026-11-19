<?php
/**
 * Shared chrome for the wp-admin screens: capability guard, page/export URLs, header, the generic server-table
 * footer (results bar + pagination) and the detail drawer that `spAdminTable` (sp-admin.js) drives.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Core\Capabilities;

abstract class AdminPage {

	public const SLUG = '';

	protected function guard( string $cap = Capabilities::CAP_MANAGE ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'southpoll-membership' ) );
		}
	}

	/** @param array<string, mixed> $query */
	public static function url( array $query = [] ): string {
		return add_query_arg( array_merge( [ 'page' => static::SLUG ], $query ), admin_url( 'admin.php' ) );
	}

	/** @param array<string, mixed> $args */
	public static function export_url( string $resource, array $args = [] ): string {
		return wp_nonce_url( add_query_arg( array_merge( [ 'action' => 'sp_export', 'resource' => $resource ], $args ), admin_url( 'admin-post.php' ) ), 'sp_export' );
	}

	public static function template_url( string $resource ): string {
		return wp_nonce_url( add_query_arg( [ 'action' => 'sp_template', 'resource' => $resource ], admin_url( 'admin-post.php' ) ), 'sp_export' );
	}

	/** @param array<string, mixed> $config */
	protected function json( array $config ): string {
		return esc_attr( (string) wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) );
	}

	/** Query param (GET) read-only helper for filters and notices. */
	protected static function get( string $key ): string {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Open the page wrapper (optionally bound to an Alpine component) and print the header.
	 *
	 * @param array<string, mixed> $config
	 */
	protected function open( string $title, string $component = '', array $config = [], string $actions_html = '' ): void {
		echo '<div class="wrap sp-admin sp-scope"' . ( $component ? ' x-data="' . esc_attr( $component ) . '(' . $this->json( $config ) . ')"' : '' ) . '>';
		echo '<div class="sp-admin__header"><h1>' . esc_html( $title ) . '</h1>' . ( $actions_html ? '<div class="sp-admin__actions">' . $actions_html . '</div>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers escape
		$this->notice();
	}

	protected function close(): void {
		echo '</div>';
	}

	/** Classic-form result notices (?sp_done=…&sp_msg=…). */
	protected function notice(): void {
		$done = self::get( 'sp_done' );
		$msg  = self::get( 'sp_msg' );
		if ( 'error' === $done ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ?: __( 'Something went wrong.', 'southpoll-membership' ) ) . '</p></div>';
		} elseif ( '' !== $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ?: sprintf( /* translators: %s: action */ __( 'Done: %s', 'southpoll-membership' ), str_replace( '_', ' ', $done ) ) ) . '</p></div>';
		}
	}

	/** Tabs rendered the WordPress way (full reload per tab). @param array<string, string> $tabs */
	protected function tabs( array $tabs, string $active, string $param = 'tab', array $extra = [] ): void {
		echo '<nav class="nav-tab-wrapper sp-admin__tabs">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( static::url( array_merge( $extra, [ $param => $key ] ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** A filter <select> bound to spAdminTable's filters.{key}. @param array<string, string> $options */
	protected function filter_select( string $key, string $label, array $options, string $all_label = '' ): void {
		echo '<label class="sp-admin__filter"><span class="sp-sr-only">' . esc_html( $label ) . '</span><select class="sp-select" x-model="filters.' . esc_attr( $key ) . '" aria-label="' . esc_attr( $label ) . '">';
		if ( '' !== $all_label ) {
			echo '<option value="">' . esc_html( $all_label ) . '</option>';
		}
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $text ) . '</option>';
		}
		echo '</select></label>';
	}

	protected function search_box( string $placeholder ): void {
		echo '<input class="sp-input sp-admin__search" type="search" x-model="q" placeholder="' . esc_attr( $placeholder ) . '" aria-label="' . esc_attr__( 'Search', 'southpoll-membership' ) . '">';
	}

	/** Sortable column header. */
	protected function th( string $key, string $label, string $class = '' ): void {
		echo '<th' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '><button type="button" class="sp-table__sort" :class="sortClass(\'' . esc_attr( $key ) . '\')" @click="setSort(\'' . esc_attr( $key ) . '\')">' . esc_html( $label ) . ' <span class="sp-table__dir" x-text="sortArrow(\'' . esc_attr( $key ) . '\')" aria-hidden="true"></span></button></th>';
	}

	/** Loading / empty rows for a table body. */
	protected function table_states( int $colspan ): void {
		echo '<template x-if="loading && !rows.length"><tr><td colspan="' . (int) $colspan . '" class="sp-admin__state sp-loading">' . esc_html__( 'Loading…', 'southpoll-membership' ) . '</td></tr></template>';
		echo '<template x-if="loaded && !loading && !rows.length"><tr><td colspan="' . (int) $colspan . '" class="sp-admin__state sp-empty">' . esc_html__( 'Nothing matches those filters.', 'southpoll-membership' ) . ' <button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="isFiltered" @click="resetFilters()">' . esc_html__( 'Clear filters', 'southpoll-membership' ) . '</button></td></tr></template>';
	}

	/** Results bar + pagination + (optional) drawer. Call after the table. */
	protected function table_foot( bool $with_drawer = true, string $extra_summary = '' ): void {
		?>
		<div class="sp-admin__foot">
			<div class="sp-results-bar"><span x-text="summary"></span><?php echo $extra_summary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller escapes ?>
				<label class="sp-table-perpage"><?php esc_html_e( 'Per page', 'southpoll-membership' ); ?> <select class="sp-select" x-model.number="perPage"><template x-for="n in perPageOptions" :key="n"><option :value="n" x-text="n"></option></template></select></label>
			</div>
			<nav class="sp-pagination" x-show="pageCount > 1" x-cloak aria-label="<?php esc_attr_e( 'Pagination', 'southpoll-membership' ); ?>">
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" :disabled="page <= 1" @click="goTo(page - 1)">&lsaquo; <?php esc_html_e( 'Prev', 'southpoll-membership' ); ?></button>
				<span class="sp-pagination__pages"><template x-for="n in pageNumbers" :key="n"><button type="button" class="sp-pill" :class="{ 'sp-pill--active': n === page }" :aria-current="n === page ? 'page' : null" @click="goTo(n)" x-text="n"></button></template></span>
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" :disabled="page >= pageCount" @click="goTo(page + 1)"><?php esc_html_e( 'Next', 'southpoll-membership' ); ?> &rsaquo;</button>
			</nav>
		</div>
		<?php
		if ( $with_drawer ) {
			$this->drawer();
		}
	}

	/** The slide-in detail panel; content comes from the REST detail endpoint (`html`). */
	protected function drawer(): void {
		?>
		<div class="sp-drawer-backdrop" x-show="drawer.open" x-cloak @click="close()"></div>
		<aside class="sp-drawer" :class="{ 'is-open': drawer.open, 'is-busy': busy }" x-show="drawer.open" x-cloak role="dialog" aria-modal="true" :aria-label="drawer.title" x-ref="drawer" @keydown.escape.window="close()" @click="drawerClick($event)" @submit.prevent="drawerSubmit($event)">
			<div class="sp-drawer__bar"><strong class="sp-drawer__bar-title" x-text="drawer.title"></strong><button type="button" class="sp-icon-btn sp-drawer__close" @click="close()" aria-label="<?php esc_attr_e( 'Close', 'southpoll-membership' ); ?>">&times;</button></div>
			<div class="sp-drawer__loading" x-show="drawer.loading"><?php esc_html_e( 'Loading…', 'southpoll-membership' ); ?></div>
			<div class="sp-drawer__body" x-show="!drawer.loading" x-html="drawer.html"></div>
		</aside>
		<?php
	}
}
