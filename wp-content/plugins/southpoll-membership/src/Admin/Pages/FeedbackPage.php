<?php
/**
 * Admin → SouthPoll → Feedback: every submission from the floating feedback button (S8), with status (new / seen /
 * closed), screenshot, page + browser, bulk actions and a CSV export. Data: /sp/v1/admin/feedback.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Feedback\FeedbackRepository;
use SouthPoll\Membership\Rest\Admin\AdminFeedbackController;

final class FeedbackPage extends AdminPage {

	public const SLUG   = 'southpoll-feedback';
	public const EXPORT = 'sp_feedback_export';

	public function __construct( private FeedbackRepository $feedback ) {}

	public static function export_link( string $status = '' ): string {
		return wp_nonce_url( add_query_arg( array_filter( [ 'action' => self::EXPORT, 'status' => $status ] ), admin_url( 'admin-post.php' ) ), self::EXPORT );
	}

	public function render(): void {
		$this->guard();
		$statuses = [];
		foreach ( FeedbackRepository::STATUSES as $s ) {
			$statuses[ $s ] = AdminFeedbackController::status_label( $s );
		}
		$counts = $this->feedback->counts();
		$config = [
			'endpoint'       => 'admin/feedback',
			'bulk'           => 'admin/feedback/bulk',
			'deleteEndpoint' => 'admin/feedback',
			'filters'        => [ 'status' => '' ],
			'sort'           => 'created',
			'dir'            => 'desc',
			'exportUrl'      => self::export_link(),
			'q'              => self::get( 'q' ),
		];
		$this->open( __( 'Feedback', 'southpoll-membership' ), 'spAdminTable', $config, '<a class="sp-btn sp-btn--outline sp-btn--sm" href="' . esc_url( self::export_link() ) . '">' . esc_html__( 'Export CSV', 'southpoll-membership' ) . '</a>' );
		?>
		<p class="description sp-admin__hint"><?php echo esc_html( sprintf( /* translators: 1: new, 2: total */ __( '%1$d new of %2$d submissions from the floating "Feedback" button (login page + member dashboard). Each one was also e-mailed to the addresses under Settings → Launch & feedback.', 'southpoll-membership' ), (int) $counts['new'], (int) $counts['all'] ) ); ?></p>
		<div class="sp-admin__toolbar">
			<?php $this->search_box( __( 'Search name, e-mail, message, page or #…', 'southpoll-membership' ) ); ?>
			<?php $this->filter_select( 'status', __( 'Status', 'southpoll-membership' ), $statuses, __( 'Any status', 'southpoll-membership' ) ); ?>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="isFiltered" x-cloak @click="resetFilters()"><?php esc_html_e( 'Clear', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-admin__bulk" x-show="selected.length" x-cloak>
			<span class="sp-admin__bulk-count" x-text="selected.length + ' <?php echo esc_js( __( 'selected', 'southpoll-membership' ) ); ?>'"></span>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulk('seen')"><?php esc_html_e( 'Mark seen', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulk('closed')"><?php esc_html_e( 'Close', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="busy" @click="bulk('new')"><?php esc_html_e( 'Mark new', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--danger sp-btn--sm" :disabled="busy" @click="bulk('delete', {}, '<?php echo esc_js( __( 'Permanently delete the selected feedback (and their screenshots)?', 'southpoll-membership' ) ); ?>')"><?php esc_html_e( 'Delete', 'southpoll-membership' ); ?></button>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="clearSelection()"><?php esc_html_e( 'Clear selection', 'southpoll-membership' ); ?></button>
		</div>
		<div class="sp-table-wrap"><table class="sp-table sp-admin__table sp-admin__table--feedback">
			<thead><tr>
				<th class="sp-admin__check"><input type="checkbox" :checked="allSelected" @change="toggleAll()" aria-label="<?php esc_attr_e( 'Select all on this page', 'southpoll-membership' ); ?>"></th>
				<?php $this->th( 'created', __( 'Received', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'status', __( 'Status', 'southpoll-membership' ) ); ?>
				<?php $this->th( 'name', __( 'From', 'southpoll-membership' ) ); ?>
				<th><?php esc_html_e( 'Message', 'southpoll-membership' ); ?></th>
				<th><?php esc_html_e( 'Page / browser', 'southpoll-membership' ); ?></th>
				<th><?php esc_html_e( 'Screenshot', 'southpoll-membership' ); ?></th>
				<th><span class="sp-sr-only"><?php esc_html_e( 'Actions', 'southpoll-membership' ); ?></span></th>
			</tr></thead>
			<tbody>
				<?php $this->table_states( 8 ); ?>
				<template x-for="r in rows" :key="r.id">
					<tr :class="{ 'is-selected': isSelected(r.id), 'is-new': r.status === 'new' }">
						<td><input type="checkbox" :checked="isSelected(r.id)" @change="toggle(r.id)" :aria-label="'#' + r.id"></td>
						<td><span x-text="r.created_date"></span><div class="sp-text-small sp-text-muted" x-text="'#' + r.id + ' · ' + r.created_ago"></div></td>
						<td><span :class="badge(r.status === 'new' ? 'warning' : (r.status === 'closed' ? 'muted' : 'success'))" x-text="r.status_label"></span></td>
						<td><strong x-text="r.name || '—'"></strong><div class="sp-text-small"><a :href="'mailto:' + r.email" x-text="r.email"></a></div><div class="sp-text-small sp-text-muted"><template x-if="r.user_login"><a :href="r.user_url" x-text="r.user_login"></a></template><template x-if="!r.user_login"><span><?php esc_html_e( 'guest', 'southpoll-membership' ); ?></span></template></div></td>
						<td class="sp-admin__feedback-msg"><details><summary x-text="r.excerpt"></summary><p style="white-space:pre-wrap" x-text="r.message"></p><p class="sp-text-small sp-text-muted" x-show="r.notes" x-text="'<?php echo esc_js( __( 'Notes:', 'southpoll-membership' ) ); ?> ' + r.notes"></p></details></td>
						<td class="sp-text-small"><a :href="r.page_url" target="_blank" rel="noopener" x-text="r.page_path"></a><div class="sp-text-muted sp-admin__ua" :title="r.user_agent" x-text="r.user_agent"></div></td>
						<td><template x-if="r.screenshot_url"><a :href="r.screenshot_url" target="_blank" rel="noopener"><img :src="r.screenshot_thumb || r.screenshot_url" alt="" class="sp-admin__thumb"></a></template></td>
						<td class="sp-admin__rowactions">
							<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="r.status !== 'seen'" @click="SP.api('admin/feedback/' + r.id, { method: 'PATCH', body: { status: 'seen' } }).then(function(){ load(); })"><?php esc_html_e( 'Seen', 'southpoll-membership' ); ?></button>
							<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="r.status !== 'closed'" @click="SP.api('admin/feedback/' + r.id, { method: 'PATCH', body: { status: 'closed' } }).then(function(){ load(); })"><?php esc_html_e( 'Close', 'southpoll-membership' ); ?></button>
							<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="r.status === 'closed'" @click="SP.api('admin/feedback/' + r.id, { method: 'PATCH', body: { status: 'new' } }).then(function(){ load(); })"><?php esc_html_e( 'Reopen', 'southpoll-membership' ); ?></button>
							<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm sp-admin__danger" @click="remove(r.id, '#' + r.id)"><?php esc_html_e( 'Delete', 'southpoll-membership' ); ?></button>
						</td>
					</tr>
				</template>
			</tbody>
		</table></div>
		<?php
		$this->table_foot( false );
		$this->close();
	}

	/** admin-post.php?action=sp_feedback_export[&status=new] — CSV of every submission. */
	public function handle_export(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'southpoll-membership' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::EXPORT );
		$status = sanitize_key( self::get( 'status' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="southpoll-feedback-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads UTF-8
		fputcsv( $out, [ 'id', 'received_utc', 'status', 'name', 'email', 'user_id', 'page_url', 'user_agent', 'message', 'screenshot_url', 'notes' ] );
		$cell = static fn( string $s ): string => preg_match( '/^[=+\-@\t\r]/', $s ) ? "'" . $s : $s; // guest-supplied text: neutralise spreadsheet formulas
		foreach ( $this->feedback->all( $status ) as $f ) {
			fputcsv( $out, [ (int) $f->id, (string) $f->created_at, (string) $f->status, $cell( (string) $f->name ), $cell( (string) $f->email ), (int) $f->user_id, $cell( (string) $f->page_url ), $cell( (string) $f->user_agent ), $cell( (string) $f->message ), $f->screenshot_id ? (string) wp_get_attachment_url( (int) $f->screenshot_id ) : '', $cell( (string) $f->notes ) ] );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
