<?php
/**
 * CSV import (staff only). Export/template downloads go through admin-post.php (ImportExportPage).
 *
 *   POST /sp/v1/admin/import/{resource}   multipart { file, dry_run=1|0 } → validation report (and imports when dry_run=0)
 *   GET  /sp/v1/admin/import/{resource}   columns + required + example (for the help panel)
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Rest\Admin;

use SouthPoll\Membership\Admin\Csv\Csv;
use SouthPoll\Membership\Rest\BaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminImportController extends BaseController {

	public function __construct( private Csv $csv ) {}

	public function register_routes(): void {
		$this->route( '/admin/import/(?P<resource>users|farms|listings|memberships)', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'describe' ], 'permission_callback' => [ self::class, 'require_staff' ] ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'import' ], 'permission_callback' => [ self::class, 'require_staff' ] ],
		] );
	}

	public function describe( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$r = $this->csv->resource( (string) $request['resource'] );
		if ( ! $r ) {
			return $this->fail( 'sp_not_found', __( 'Unknown resource.', 'southpoll-membership' ), 404 );
		}
		return $this->ok( [ 'resource' => $r->name(), 'label' => $r->label(), 'columns' => array_keys( $r->template_columns() ), 'required' => $r->required(), 'example' => $r->example(), 'export_only' => $r->export_only() ] );
	}

	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$r = $this->csv->resource( (string) $request['resource'] );
		if ( ! $r ) {
			return $this->fail( 'sp_not_found', __( 'Unknown resource.', 'southpoll-membership' ), 404 );
		}
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return $this->fail( 'sp_file_required', __( 'Choose a CSV file.', 'southpoll-membership' ), 422 );
		}
		if ( (int) $file['size'] > 20 * MB_IN_BYTES ) {
			return $this->fail( 'sp_file_size', __( 'CSV files up to 20 MB are accepted.', 'southpoll-membership' ), 422 );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) {
			return $this->fail( 'sp_file_type', __( 'Please upload a .csv file.', 'southpoll-membership' ), 422 );
		}
		$dry    = $request->has_param( 'dry_run' ) ? rest_sanitize_boolean( $request->get_param( 'dry_run' ) ) : true;
		$parsed = $this->csv->parse( (string) $file['tmp_name'], $r );
		if ( empty( $parsed['rows'] ) && $parsed['errors'] ) {
			return $this->fail( 'sp_parse', implode( ' ', $parsed['errors'] ), 422 );
		}
		$report = $this->csv->run( $r, $parsed['rows'], $dry, $parsed['errors'] );
		$report['message'] = $dry
			? sprintf( /* translators: 1: rows, 2: invalid */ __( 'Checked %1$d rows: %2$d need attention. Nothing has been changed yet.', 'southpoll-membership' ), $report['total'], $report['invalid'] )
			: sprintf( /* translators: 1: imported, 2: failed */ __( 'Imported %1$d rows (%2$d failed).', 'southpoll-membership' ), $report['imported'], $report['failed'] + $report['invalid'] );
		return $this->ok( $report );
	}
}
