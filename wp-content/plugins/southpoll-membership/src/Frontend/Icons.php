<?php
/**
 * Inline SVG icons shared by templates (no icon font dependency).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Frontend;

final class Icons {

	private const PATHS = [
		'star'       => '<path d="m12 3 2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.4 6.3 20.5l1.2-6.4L2.8 9.7l6.4-.8z"/>',
		'pin'        => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
		'phone'      => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
		'mail'       => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'globe'      => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		'share'      => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
		'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'left'       => '<path d="m15 5-7 7 7 7"/>',
		'right'      => '<path d="m9 5 7 7-7 7"/>',
		'close'      => '<path d="M6 6l12 12M18 6 6 18"/>',
		'play'       => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z" fill="currentColor"/>',
		'image'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="m21 16-5-5-8 8"/>',
		'upload'     => '<path d="M12 16V4m0 0-4 4m4-4 4 4"/><path d="M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2"/>',
		'trash'      => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		'grip'       => '<circle cx="9" cy="6" r="1.3" fill="currentColor"/><circle cx="15" cy="6" r="1.3" fill="currentColor"/><circle cx="9" cy="12" r="1.3" fill="currentColor"/><circle cx="15" cy="12" r="1.3" fill="currentColor"/><circle cx="9" cy="18" r="1.3" fill="currentColor"/><circle cx="15" cy="18" r="1.3" fill="currentColor"/>',
		'map'        => '<path d="m3 6 6-2 6 2 6-2v14l-6 2-6-2-6 2z"/><path d="M9 4v14M15 6v14"/>',
		'list'       => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1" fill="currentColor"/><circle cx="4" cy="12" r="1" fill="currentColor"/><circle cx="4" cy="18" r="1" fill="currentColor"/>',
		'check'      => '<path d="m5 12 4 4L19 6"/>',
		'external'   => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
		'directions' => '<path d="m12 2 10 10-10 10L2 12z"/><path d="M9 13v-2h5l-1.5-1.5M14 11l-1.5 1.5"/>',
		'edit'       => '<path d="M4 20h4l10-10-4-4L4 16z"/><path d="m13 7 4 4"/>',
		'barn'       => '<path d="M3 10 12 3l9 7v11H3z"/><path d="M9 21v-7h6v7"/>',
		'lock'       => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'user'       => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'tag'        => '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
		'bell'       => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
		'facebook'   => '<path d="M14 8h3V4h-3c-2.8 0-5 2.2-5 5v2H6v4h3v7h4v-7h3l1-4h-4V9c0-.6.4-1 1-1z" fill="currentColor" stroke="none"/>',
		'instagram'  => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/>',
		'youtube'    => '<path d="M22 8.5a3 3 0 0 0-2-2C18 6 12 6 12 6s-6 0-8 .5a3 3 0 0 0-2 2A31 31 0 0 0 2 12a31 31 0 0 0 0 3.5 3 3 0 0 0 2 2c2 .5 8 .5 8 .5s6 0 8-.5a3 3 0 0 0 2-2 31 31 0 0 0 0-7z"/><path d="m10 9.5 5 2.5-5 2.5z" fill="currentColor"/>',
		'x'          => '<path d="M17.5 3h3l-7.3 8.3L21.7 21h-6.3l-4.9-6.4L4.8 21h-3l7.8-8.9L1 3h6.4l4.4 5.9zm-1.1 16.2h1.7L6.9 4.7H5.1z" fill="currentColor" stroke="none"/>',
		'tiktok'     => '<path d="M14 3v10.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 3a5 5 0 0 0 5 5"/>',
		'linkedin'   => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 10v7M8 7v.5M12 17v-4a2 2 0 0 1 4 0v4M12 10v7"/>',
		'other'      => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
		'spinner'    => '<circle cx="12" cy="12" r="9" stroke-opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9"/>',
		'info'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.5"/>',
		'alert'      => '<path d="M12 3 2.5 20h19L12 3z"/><path d="M12 10v4M12 17v.5"/>',
	];

	public static function svg( string $name, int $size = 20, string $class = '', bool $filled = false ): string {
		$path = self::PATHS[ $name ] ?? self::PATHS['tag'];
		$fill = $filled ? 'currentColor' : 'none';
		return '<svg class="sp-icon' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '" viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="' . $fill . '" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/** @return string[] */
	public static function names(): array {
		return array_keys( self::PATHS );
	}
}
