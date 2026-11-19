<?php
/**
 * A CSV-able resource: export rows (respecting the admin table filters), a downloadable template, and row-by-row
 * import with validation (dry run first).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Csv;

use WP_Error;

abstract class Resource {

	abstract public function name(): string;

	abstract public function label(): string;

	/** @return array<string, string> column key => heading (export order; import accepts any subset) */
	abstract public function columns(): array;

	/** @return string[] keys that must be present in an import file */
	abstract public function required(): array;

	/** @return array<string, string> one illustrative row for the template */
	abstract public function example(): array;

	/**
	 * @param array<string, mixed> $args table filters
	 * @return iterable<array<string, mixed>> rows keyed by column key
	 */
	abstract public function export( array $args ): iterable;

	/**
	 * Validate one import row. Return [ 'action' => create|update|skip, 'errors' => [], 'key' => 'what identifies it' ].
	 *
	 * @param array<string, string> $row
	 * @return array{action:string, errors:string[], key:string}
	 */
	abstract public function validate( array $row ): array;

	/**
	 * Perform one import row (after validate() passed).
	 *
	 * @param array<string, string> $row
	 * @return array{action:string, id:int}|WP_Error
	 */
	abstract public function import( array $row ): array|WP_Error;

	/** Which columns a template includes (import-relevant). Default: all except export-only ones. */
	public function template_columns(): array {
		return array_diff_key( $this->columns(), array_flip( $this->export_only() ) );
	}

	/** @return string[] columns that are exported but ignored on import */
	public function export_only(): array {
		return [];
	}

	/* ---------------------------------------------------------------- helpers */

	protected static function yes( mixed $v ): bool {
		return in_array( strtolower( trim( (string) $v ) ), [ '1', 'yes', 'y', 'true', 'on', 'x' ], true );
	}

	protected static function yn( mixed $v ): string {
		return $v ? 'yes' : 'no';
	}

	protected static function has( array $row, string $key ): bool {
		return array_key_exists( $key, $row ) && '' !== trim( (string) $row[ $key ] );
	}

	protected static function date( mixed $v ): ?string {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return null;
		}
		$ts = strtotime( $v );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
}
