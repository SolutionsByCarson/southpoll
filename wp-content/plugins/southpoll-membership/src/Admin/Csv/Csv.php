<?php
/**
 * CSV plumbing: resource registry, export streaming, template files, parsing + dry-run/import runner.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Csv;

use SouthPoll\Membership\Core\Container;
use SouthPoll\Membership\Core\Logger;
use WP_Error;

final class Csv {

	public const RESOURCES = [ 'users' => UsersResource::class, 'farms' => FarmsResource::class, 'listings' => ListingsResource::class, 'memberships' => MembershipsResource::class ];

	public const MAX_ROWS = 5000;

	public function __construct( private Container $container, private Logger $logger ) {}

	public function resource( string $name ): ?Resource {
		$class = self::RESOURCES[ sanitize_key( $name ) ] ?? null;
		return $class ? $this->container->get( $class ) : null;
	}

	/** @return array<string, Resource> */
	public function resources(): array {
		$out = [];
		foreach ( array_keys( self::RESOURCES ) as $name ) {
			$out[ $name ] = $this->resource( $name );
		}
		return $out;
	}

	/* ---------------------------------------------------------------- export */

	/** Send CSV headers and stream the rows (BOM so Excel reads UTF-8). */
	public function stream( Resource $resource, array $args, bool $template = false ): void {
		$filename = 'southpoll-' . $resource->name() . ( $template ? '-template' : '' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$out = fopen( 'php://output', 'w' );
		if ( $template ) {
			$cols = $resource->template_columns();
			fputcsv( $out, array_values( $cols ) );
			fputcsv( $out, array_map( static fn( $k ) => $resource->example()[ $k ] ?? '', array_keys( $cols ) ) );
		} else {
			$cols = $resource->columns();
			fputcsv( $out, array_values( $cols ) );
			foreach ( $resource->export( $args ) as $row ) {
				fputcsv( $out, array_map( static fn( $k ) => self::cell( $row[ $k ] ?? '' ), array_keys( $cols ) ) );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/** Build the CSV as a string (tests / previews). */
	public function render( Resource $resource, array $args, bool $template = false ): string {
		ob_start();
		$out  = fopen( 'php://temp', 'w+' );
		$cols = $template ? $resource->template_columns() : $resource->columns();
		fputcsv( $out, array_values( $cols ) );
		if ( $template ) {
			fputcsv( $out, array_map( static fn( $k ) => $resource->example()[ $k ] ?? '', array_keys( $cols ) ) );
		} else {
			foreach ( $resource->export( $args ) as $row ) {
				fputcsv( $out, array_map( static fn( $k ) => self::cell( $row[ $k ] ?? '' ), array_keys( $cols ) ) );
			}
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		ob_end_clean();
		return $csv;
	}

	private static function cell( mixed $v ): string {
		$s = is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) ( $v ?? '' );
		return preg_match( '/^[=+\-@]/', $s ) ? "'" . $s : $s; // neutralise spreadsheet formula injection
	}

	/* ---------------------------------------------------------------- import */

	/**
	 * Parse a CSV file into rows keyed by (normalised) heading.
	 *
	 * @return array{headers:string[], rows:array<int, array<string, string>>, errors:string[]}
	 */
	public function parse( string $path, Resource $resource ): array {
		$errors = [];
		$fh     = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return [ 'headers' => [], 'rows' => [], 'errors' => [ __( 'Could not read the uploaded file.', 'southpoll-membership' ) ] ];
		}
		$first = fgets( $fh );
		if ( false === $first ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return [ 'headers' => [], 'rows' => [], 'errors' => [ __( 'The file is empty.', 'southpoll-membership' ) ] ];
		}
		$first     = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		$headers   = array_map( static fn( $h ) => strtolower( trim( str_replace( [ ' ', '-' ], '_', (string) $h ) ) ), str_getcsv( rtrim( $first, "\r\n" ), $delimiter ) );
		$known     = array_keys( $resource->columns() );
		$unknown   = array_values( array_diff( array_filter( $headers ), $known ) );
		if ( $unknown ) {
			$errors[] = sprintf( /* translators: %s: list */ __( 'Ignored unknown columns: %s', 'southpoll-membership' ), implode( ', ', $unknown ) );
		}
		$missing = array_values( array_diff( $resource->required(), $headers ) );
		if ( $missing ) {
			$errors[] = sprintf( /* translators: %s: list */ __( 'Missing required columns: %s', 'southpoll-membership' ), implode( ', ', $missing ) );
		}
		$rows = [];
		$line = 1;
		while ( ( $data = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
			$line++;
			if ( 1 === count( $data ) && null === $data[0] ) {
				continue; // blank line
			}
			$row = [];
			foreach ( $headers as $i => $h ) {
				if ( '' !== $h && in_array( $h, $known, true ) ) {
					$row[ $h ] = isset( $data[ $i ] ) ? trim( (string) $data[ $i ] ) : '';
				}
			}
			$row['_line'] = (string) $line;
			$rows[]       = $row;
			if ( count( $rows ) > self::MAX_ROWS ) {
				$errors[] = sprintf( /* translators: %d: max */ __( 'Only the first %d rows are processed per file.', 'southpoll-membership' ), self::MAX_ROWS );
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return [ 'headers' => $headers, 'rows' => $rows, 'errors' => $errors ];
	}

	/**
	 * Validate every row; when $dry_run is false, import the valid ones.
	 *
	 * @param array<int, array<string, string>> $rows
	 * @return array{dry_run:bool, total:int, create:int, update:int, skip:int, invalid:int, imported:int, failed:int, errors:array<int, array{line:int, key:string, message:string}>, file_errors:string[]}
	 */
	public function run( Resource $resource, array $rows, bool $dry_run, array $file_errors = [] ): array {
		$report = [ 'dry_run' => $dry_run, 'resource' => $resource->name(), 'total' => count( $rows ), 'create' => 0, 'update' => 0, 'skip' => 0, 'invalid' => 0, 'imported' => 0, 'failed' => 0, 'errors' => [], 'file_errors' => $file_errors ];
		$missing = array_diff( $resource->required(), $rows ? array_keys( $rows[0] ) : [] );
		if ( $rows && $missing ) {
			$report['invalid'] = count( $rows );
			return $report;
		}
		foreach ( $rows as $row ) {
			$line = (int) ( $row['_line'] ?? 0 );
			unset( $row['_line'] );
			$v = $resource->validate( $row );
			if ( $v['errors'] ) {
				$report['invalid']++;
				$report['errors'][] = [ 'line' => $line, 'key' => $v['key'], 'message' => implode( '; ', $v['errors'] ) ];
				continue;
			}
			$report[ $v['action'] ] = ( $report[ $v['action'] ] ?? 0 ) + 1;
			if ( $dry_run || 'skip' === $v['action'] ) {
				continue;
			}
			$res = $resource->import( $row );
			if ( is_wp_error( $res ) ) {
				$report['failed']++;
				$report['errors'][] = [ 'line' => $line, 'key' => $v['key'], 'message' => $res->get_error_message() ];
				continue;
			}
			$report['imported']++;
		}
		if ( ! $dry_run ) {
			$this->logger->info( sprintf( 'CSV import (%s): %d imported, %d failed, %d invalid of %d rows by user %d', $resource->name(), $report['imported'], $report['failed'], $report['invalid'], $report['total'], get_current_user_id() ) );
		}
		return $report;
	}
}
