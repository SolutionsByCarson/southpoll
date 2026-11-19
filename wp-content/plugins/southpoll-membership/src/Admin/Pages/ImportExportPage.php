<?php
/**
 * Admin → SouthPoll → Import / Export: CSV exports (admin-post downloads; the table screens add their filters),
 * templates, and imports with a dry-run report. Data: /sp/v1/admin/import/{resource}.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Admin\Csv\Csv;
use SouthPoll\Membership\Core\Capabilities;

final class ImportExportPage extends AdminPage {

	public const SLUG = 'southpoll-import-export';

	/** Table filter keys an export URL may carry (same names as the REST table params). */
	private const FILTER_KEYS = [ 'q', 'role', 'farm_id', 'status', 'membership', 'state', 'region', 'visibility', 'category', 'source', 'auto_renew', 'sort', 'dir' ];

	public function __construct( private Csv $csv ) {}

	/** admin-post.php?action=sp_export&resource=…&{filters} */
	public function handle_export(): void {
		$this->download( false );
	}

	/** admin-post.php?action=sp_template&resource=… */
	public function handle_template(): void {
		$this->download( true );
	}

	private function download( bool $template ): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'southpoll-membership' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'sp_export' );
		$resource = $this->csv->resource( self::get( 'resource' ) );
		if ( ! $resource ) {
			wp_die( esc_html__( 'Unknown export.', 'southpoll-membership' ), '', [ 'response' => 404 ] );
		}
		$args = [];
		foreach ( self::FILTER_KEYS as $k ) {
			$val = self::get( $k );
			if ( '' !== $val ) {
				$args[ $k ] = $val;
			}
		}
		$this->csv->stream( $resource, $args, $template );
		exit;
	}

	public function render(): void {
		$this->guard();
		$this->open( __( 'Import / Export', 'southpoll-membership' ) );
		?>
		<p class="description sp-admin__hint"><?php esc_html_e( 'Exports are UTF-8 CSV files that open in Excel or Google Sheets. Imports are checked first ("dry run"): nothing changes until you confirm, and rows with problems are listed by line number and skipped. Column headings must match the template (order does not matter; unknown columns are ignored). Order of operations for a migration: Users, then Farms, then Memberships, then Listings.', 'southpoll-membership' ); ?></p>
		<div class="sp-grid sp-grid--2 sp-admin__resources">
			<?php foreach ( $this->csv->resources() as $name => $r ) : ?>
				<section class="sp-card sp-admin__resource" x-data="spAdminImport(<?php echo $this->json( [ 'resource' => $name ] ); ?>)">
					<div class="sp-card__header"><h2 class="sp-card__title"><?php echo esc_html( $r->label() ); ?></h2>
						<div class="sp-cluster"><a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( self::export_url( $name ) ); ?>"><?php esc_html_e( 'Export all', 'southpoll-membership' ); ?></a> <a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( self::template_url( $name ) ); ?>"><?php esc_html_e( 'Template', 'southpoll-membership' ); ?></a></div>
					</div>
					<details class="sp-admin__columns"><summary><?php esc_html_e( 'Columns', 'southpoll-membership' ); ?></summary>
						<p class="sp-text-small"><strong><?php esc_html_e( 'Required:', 'southpoll-membership' ); ?></strong> <code><?php echo esc_html( implode( '</code> <code>', $r->required() ) ); ?></code></p>
						<p class="sp-text-small"><strong><?php esc_html_e( 'Import columns:', 'southpoll-membership' ); ?></strong> <?php echo esc_html( implode( ', ', array_keys( $r->template_columns() ) ) ); ?></p>
						<?php if ( $r->export_only() ) : ?><p class="sp-text-small"><strong><?php esc_html_e( 'Export only (ignored on import):', 'southpoll-membership' ); ?></strong> <?php echo esc_html( implode( ', ', $r->export_only() ) ); ?></p><?php endif; ?>
						<p class="sp-text-small sp-text-muted"><?php echo esc_html( $this->notes( $name ) ); ?></p>
					</details>
					<div class="sp-admin__import">
						<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'CSV file', 'southpoll-membership' ); ?></span><input type="file" accept=".csv,text/csv" x-ref="file" @change="pick($event)"></label>
						<div class="sp-cluster">
							<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="!file || busy" @click="run(true)"><span x-show="mode !== 'check'"><?php esc_html_e( 'Check file (dry run)', 'southpoll-membership' ); ?></span><span x-show="mode === 'check'"><?php esc_html_e( 'Checking…', 'southpoll-membership' ); ?></span></button>
							<button type="button" class="sp-btn sp-btn--primary sp-btn--sm" :disabled="!file || busy || !report || report.dry_run === false" x-show="report && report.dry_run" x-cloak @click="run(false)"><span x-show="mode !== 'import'"><?php esc_html_e( 'Import valid rows', 'southpoll-membership' ); ?></span><span x-show="mode === 'import'"><?php esc_html_e( 'Importing…', 'southpoll-membership' ); ?></span></button>
						</div>
						<template x-if="report">
							<div class="sp-admin__report" :class="report.dry_run ? '' : 'is-final'">
								<p><strong x-text="report.message"></strong></p>
								<ul class="sp-admin__report-counts">
									<li><span x-text="report.total"></span> <?php esc_html_e( 'rows', 'southpoll-membership' ); ?></li>
									<li><span x-text="report.create"></span> <?php esc_html_e( 'to create', 'southpoll-membership' ); ?></li>
									<li><span x-text="report.update"></span> <?php esc_html_e( 'to update', 'southpoll-membership' ); ?></li>
									<li x-show="report.skip"><span x-text="report.skip"></span> <?php esc_html_e( 'skipped', 'southpoll-membership' ); ?></li>
									<li :class="report.invalid ? 'is-bad' : ''"><span x-text="report.invalid"></span> <?php esc_html_e( 'with problems', 'southpoll-membership' ); ?></li>
									<li x-show="!report.dry_run"><span x-text="report.imported"></span> <?php esc_html_e( 'imported', 'southpoll-membership' ); ?></li>
									<li x-show="!report.dry_run && report.failed" class="is-bad"><span x-text="report.failed"></span> <?php esc_html_e( 'failed', 'southpoll-membership' ); ?></li>
								</ul>
								<template x-if="report.file_errors && report.file_errors.length"><ul class="sp-admin__report-file"><template x-for="e in report.file_errors"><li x-text="e"></li></template></ul></template>
								<template x-if="report.errors && report.errors.length">
									<div class="sp-table-wrap sp-admin__report-rows"><table class="sp-table"><thead><tr><th><?php esc_html_e( 'Line', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Row', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Problem', 'southpoll-membership' ); ?></th></tr></thead><tbody><template x-for="e in report.errors"><tr><td x-text="e.line"></td><td x-text="e.key"></td><td x-text="e.message"></td></tr></template></tbody></table></div>
								</template>
							</div>
						</template>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
		$this->close();
	}

	private function notes( string $resource ): string {
		return match ( $resource ) {
			'users'       => __( 'Matches on email. New accounts get the buyer role and a random password (no email is sent); send a password reset from the Users screen when ready. "verified" / "suspended" / "notify_all" take yes or no.', 'southpoll-membership' ),
			'farms'       => __( 'Give farm_id to update a farm, or owner_email (an existing account without a farm) to create one. Offerings are slugs separated by | . Blank cells leave the stored value unchanged. New farms are geocoded by the Status page button later.', 'southpoll-membership' ),
			'listings'    => __( 'Give listing_id to update, or farm_id + title to create. categories are slugs separated by | ; visibility is live, draft or expired; price is in dollars (blank = call for price). meta_* columns are the configured listing fields. Publishing follows the farm’s membership and listing limit.', 'southpoll-membership' ),
			'memberships' => __( 'One row per farm_id. status active needs ends_at; expired / cancelled / pending / none are applied through the normal status engine, so farm visibility and listings follow. source is free text (e.g. migrated).', 'southpoll-membership' ),
			default       => '',
		};
	}
}
