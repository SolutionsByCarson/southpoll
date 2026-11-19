<?php
/**
 * Values of admin-configured farm fields (sp_farm_meta).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Core\Schema;

final class FarmMetaRepository {

	public function __construct( private MetaFieldRepository $fields ) {}

	private function table(): string {
		return Schema::table( 'sp_farm_meta' );
	}

	/** @return object[] active farm fields in order */
	public function fields(): array {
		return $this->fields->for_scope( 'farm' );
	}

	/** @return array<string, string> field_key => value */
	public function values( int $farm_id ): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT field_key, value FROM {$this->table()} WHERE farm_id = %d", $farm_id ) ) ?: [] as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (string) $row->field_key ] = (string) $row->value;
		}
		return $out;
	}

	/**
	 * Replace the farm's values from raw form input (keys = field_key). Missing/empty values clear the row.
	 *
	 * @param array<string, mixed> $raw
	 */
	public function save( int $farm_id, array $raw ): void {
		global $wpdb;
		foreach ( $this->fields() as $field ) {
			$key   = (string) $field->field_key;
			$value = MetaFieldRepository::sanitize_value( $field, $raw[ $key ] ?? ( 'bool' === $field->type ? '0' : '' ) );
			if ( null === $value || ( 'bool' === $field->type && '0' === $value ) ) {
				$wpdb->delete( $this->table(), [ 'farm_id' => $farm_id, 'field_key' => $key ] );
				continue;
			}
			$wpdb->replace( $this->table(), [ 'farm_id' => $farm_id, 'field_key' => $key, 'value' => $value ] );
		}
	}

	/**
	 * Rows for the public meta table: label => display value (empty values omitted).
	 *
	 * @return array<int, array{label:string, value:string, key:string}>
	 */
	public function display_rows( int $farm_id ): array {
		$values = $this->values( $farm_id );
		$out    = [];
		foreach ( $this->fields() as $field ) {
			$display = MetaFieldRepository::display_value( $field, $values[ (string) $field->field_key ] ?? null );
			if ( '' !== $display ) {
				$out[] = [ 'key' => (string) $field->field_key, 'label' => (string) $field->label, 'value' => $display ];
			}
		}
		return $out;
	}
}
