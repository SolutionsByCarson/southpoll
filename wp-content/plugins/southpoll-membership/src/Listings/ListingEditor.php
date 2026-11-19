<?php
/**
 * Validates the listing editor form and hands clean data to ListingService.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Listings;

use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Members\MemberContext;
use WP_Error;

final class ListingEditor {

	public function __construct( private ListingService $service, private ListingTypes $types, private MetaFieldRepository $fields ) {}

	/**
	 * @param array<string, mixed> $in Raw (unslashed) form input: title, excerpt, content, price, price_label, categories[], status, meta[key], featured_id
	 * @param string $action save|publish|draft
	 */
	public function save( MemberContext $ctx, Listing $listing, array $in, string $action = 'save' ): Listing|WP_Error {
		$errors = new WP_Error();
		$data   = [];

		$title = trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) );
		if ( mb_strlen( $title ) < 3 ) {
			$errors->add( 'title', __( 'Please give the listing a title (at least 3 characters).', 'southpoll-membership' ) );
		} elseif ( mb_strlen( $title ) > 190 ) {
			$errors->add( 'title', __( 'Keep the title under 190 characters.', 'southpoll-membership' ) );
		}
		$data['title'] = $title;

		$excerpt = trim( sanitize_textarea_field( (string) ( $in['excerpt'] ?? '' ) ) );
		if ( mb_strlen( $excerpt ) > 400 ) {
			$errors->add( 'excerpt', __( 'Keep the short description under 400 characters.', 'southpoll-membership' ) );
		}
		$data['excerpt'] = $excerpt;
		$data['content'] = wp_kses_post( (string) ( $in['content'] ?? '' ) );
		if ( 'publish' === $action && '' === trim( wp_strip_all_tags( $data['content'] ) ) && '' === $excerpt ) {
			$errors->add( 'content', __( 'Please describe what you are selling before publishing.', 'southpoll-membership' ) );
		}

		$price_raw = trim( (string) ( $in['price'] ?? '' ) );
		if ( '' === $price_raw ) {
			$data['price_cents'] = null;
		} else {
			$clean = preg_replace( '/[^0-9.]/', '', $price_raw ) ?? '';
			if ( '' === $clean || ! is_numeric( $clean ) || (float) $clean < 0 || (float) $clean > 9999999 ) {
				$errors->add( 'price', __( 'Please enter a price in dollars (numbers only), or leave it blank and use the price note.', 'southpoll-membership' ) );
			} else {
				$data['price_cents'] = (int) round( (float) $clean * 100 );
			}
		}
		$data['price_label'] = mb_substr( trim( sanitize_text_field( (string) ( $in['price_label'] ?? '' ) ) ), 0, 60 );

		$valid_ids  = array_map( static fn( $c ) => $c->id, $this->types->categories() );
		$categories = array_values( array_intersect( array_map( 'intval', (array) ( $in['categories'] ?? [] ) ), $valid_ids ) );
		if ( empty( $categories ) ) {
			$errors->add( 'categories', __( 'Choose at least one category.', 'southpoll-membership' ) );
		}
		$data['categories'] = $categories;

		$status = sanitize_key( (string) ( $in['status'] ?? $listing->status ) );
		if ( ! in_array( $status, ListingTypes::STATUSES, true ) ) {
			$errors->add( 'status', __( 'Please choose a status.', 'southpoll-membership' ) );
			$status = $listing->status;
		}
		$data['status'] = $status;

		// Meta fields: only groups that apply to the chosen categories are saved; others are cleared.
		$applicable = $this->fields->groups_for_categories( $categories, 'listing' );
		$keep       = [];
		$meta_in    = is_array( $in['meta'] ?? null ) ? $in['meta'] : [];
		$meta       = [];
		foreach ( $applicable as $group ) {
			foreach ( $this->fields->fields_for_group( (int) $group->id ) as $field ) {
				$key          = (string) $field->field_key;
				$keep[ $key ] = true;
				$value        = MetaFieldRepository::sanitize_value( $field, $meta_in[ $key ] ?? ( 'bool' === $field->type ? '0' : '' ) );
				if ( (int) $field->required && 'publish' === $action && ( null === $value || '' === $value || ( 'bool' === $field->type && '0' === $value ) ) ) {
					$errors->add( 'meta_' . $key, sprintf( /* translators: %s: field label */ __( '%s is required.', 'southpoll-membership' ), (string) $field->label ) );
				}
				$meta[ $key ] = null === $value || ( 'bool' === $field->type && '0' === $value ) ? null : $value;
			}
		}
		foreach ( $listing->meta as $key => $value ) {
			if ( ! isset( $keep[ $key ] ) ) {
				$meta[ $key ] = null;
			}
		}
		$data['meta'] = $meta;

		if ( array_key_exists( 'featured_id', $in ) ) {
			$fid = (int) $in['featured_id'];
			if ( $fid > 0 && ! $this->attachment_allowed( $fid, $listing ) ) {
				$fid = 0;
			}
			$data['featured_id'] = $fid ?: null;
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}
		return $this->service->save( $ctx, $listing, $data, $action );
	}

	/** Attachments must have been uploaded for this listing (or this farm) through the plugin. */
	public function attachment_allowed( int $attachment_id, Listing $listing ): bool {
		return (int) get_post_meta( $attachment_id, '_sp_listing_id', true ) === $listing->id || (int) get_post_meta( $attachment_id, '_sp_farm_id', true ) === $listing->farm_id;
	}
}
