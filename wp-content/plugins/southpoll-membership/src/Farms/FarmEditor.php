<?php
/**
 * Validates and saves the My Farm editor form.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Farms;

use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Members\MemberContext;
use SouthPoll\Membership\Support\Str;
use WP_Error;

final class FarmEditor {

	public const TEXT_FIELDS = [ 'name', 'slogan', 'breeder_number', 'address1', 'city', 'state', 'zip', 'phone', 'email', 'website', 'owner_name' ];

	public function __construct( private FarmRepository $farms, private Offerings $offerings, private FarmMetaRepository $meta, private Logger $logger ) {}

	/**
	 * @param array<string, mixed> $in Raw (unslashed) form input.
	 */
	public function save( MemberContext $ctx, array $in ): \stdClass|WP_Error {
		if ( ! $ctx->can( 'edit_farm' ) || ! $ctx->has_farm() ) {
			return new WP_Error( 'sp_forbidden', __( 'You cannot edit this farm right now.', 'southpoll-membership' ) );
		}
		return $this->save_farm( $ctx->farm_id(), $in, $ctx->is_owner() || $ctx->is_staff(), $ctx );
	}

	/**
	 * Staff path (admin farm screen / CSV import): same validation, any farm, owner-level rights.
	 *
	 * @param array<string, mixed> $in
	 */
	public function save_farm( int $farm_id, array $in, bool $owner_rights = true, ?MemberContext $ctx = null ): \stdClass|WP_Error {
		if ( ! $this->farms->find( $farm_id ) ) {
			return new WP_Error( 'sp_not_found', __( 'Farm not found.', 'southpoll-membership' ) );
		}
		$errors = new WP_Error();
		$data   = [];
		foreach ( self::TEXT_FIELDS as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$data[ $key ] = trim( sanitize_text_field( (string) $in[ $key ] ) );
			}
		}
		if ( isset( $data['name'] ) && mb_strlen( $data['name'] ) < 2 ) {
			$errors->add( 'name', __( 'Please enter your farm or ranch name.', 'southpoll-membership' ) );
		}
		if ( isset( $data['slogan'] ) && mb_strlen( $data['slogan'] ) > 255 ) {
			$errors->add( 'slogan', __( 'Keep the slogan under 255 characters.', 'southpoll-membership' ) );
		}
		if ( isset( $data['email'] ) && '' !== $data['email'] && ! is_email( $data['email'] ) ) {
			$errors->add( 'email', __( 'Please enter a valid contact email address.', 'southpoll-membership' ) );
		}
		if ( isset( $data['website'] ) && '' !== $data['website'] ) {
			$site = preg_match( '#^https?://#i', $data['website'] ) ? $data['website'] : 'https://' . $data['website'];
			$site = esc_url_raw( $site );
			if ( '' === $site || ! wp_http_validate_url( $site ) ) {
				$errors->add( 'website', __( 'Please enter a valid website address (for example https://example.com).', 'southpoll-membership' ) );
			}
			$data['website'] = $site;
		}
		if ( isset( $data['state'] ) && '' !== $data['state'] && ! isset( Str::us_states()[ Str::state( $data['state'] ) ] ) ) {
			$errors->add( 'state', __( 'Please choose a state.', 'southpoll-membership' ) );
		}
		if ( isset( $data['zip'] ) && '' !== $data['zip'] && ! preg_match( '/^\d{5}(-\d{4})?$/', $data['zip'] ) ) {
			$errors->add( 'zip', __( 'Please enter a 5-digit ZIP code.', 'southpoll-membership' ) );
		}
		if ( isset( $data['breeder_number'] ) && ! $owner_rights ) {
			unset( $data['breeder_number'] ); // registry data: owner only
		}
		if ( array_key_exists( 'about', $in ) ) {
			$data['about'] = wp_kses_post( (string) $in['about'] );
		}
		if ( array_key_exists( 'social', $in ) ) {
			$data['social'] = FarmRepository::clean_social( $in['social'] );
			foreach ( (array) $in['social'] as $k => $v ) {
				if ( '' !== trim( (string) $v ) && in_array( $k, FarmRepository::SOCIAL_KEYS, true ) && ! isset( $data['social'][ $k ] ) ) {
					$errors->add( 'social_' . $k, sprintf( /* translators: %s: network */ __( 'The %s link does not look like a valid URL.', 'southpoll-membership' ), ucfirst( (string) $k ) ) );
				}
			}
		}
		if ( array_key_exists( 'hours', $in ) ) {
			$data['hours'] = FarmRepository::clean_hours( $in['hours'] );
			foreach ( $data['hours'] as $day => $h ) {
				if ( $h['open'] && $h['from'] >= $h['to'] ) {
					$errors->add( 'hours_' . $day, sprintf( /* translators: %s: day */ __( 'Closing time must be after opening time on %s.', 'southpoll-membership' ), ucfirst( (string) $day ) ) );
				}
			}
		}
		if ( array_key_exists( 'contact_form_enabled', $in ) || ! empty( $in['_contact_form_submitted'] ) ) {
			if ( $owner_rights ) {
				$data['contact_form_enabled'] = ! empty( $in['contact_form_enabled'] );
			}
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$this->farms->update( $farm_id, $data );
		if ( array_key_exists( 'offerings', $in ) ) {
			$this->offerings->set_for_farm( $farm_id, (array) $in['offerings'] );
		} elseif ( ! empty( $in['_offerings_submitted'] ) ) {
			$this->offerings->set_for_farm( $farm_id, [] );
		}
		if ( array_key_exists( 'meta', $in ) && is_array( $in['meta'] ) ) {
			$this->meta->save( $farm_id, $in['meta'] );
		}
		$farm = $this->farms->find( $farm_id );
		$this->logger->info( "Farm {$farm_id} saved by user " . ( $ctx ? $ctx->user_id : get_current_user_id() ) );
		do_action( 'sp/farm/saved', $farm, $ctx );
		return $farm;
	}
}
