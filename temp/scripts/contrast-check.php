<?php
/**
 * contrast-check.php — WCAG 2.x contrast audit for the plugin's color pairs.
 *
 * Run (no WordPress needed):
 *   "/c/Users/Carson/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" contrast-check.php
 * or ./wp-run.sh contrast-check.php
 *
 * AA thresholds: 4.5:1 for normal text (anything under 24px regular / 18.66px bold), 3:1 for large text and
 * non-text UI parts (icons, focus rings, status dots). Edit $pairs when tokens change.
 */

function sp_lum( string $hex ): float {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
	$c = [];
	foreach ( [ 0, 2, 4 ] as $i ) {
		$v   = hexdec( substr( $hex, $i, 2 ) ) / 255;
		$c[] = $v <= 0.04045 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function sp_contrast( string $fg, string $bg ): float {
	$l1 = sp_lum( $fg ); $l2 = sp_lum( $bg );
	return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
}
/** Blend a translucent colour over a background: [r,g,b,a] over hex. */
function sp_blend( array $rgba, string $bg ): string {
	$bg = ltrim( $bg, '#' ); $out = '';
	foreach ( [ 0, 2, 4 ] as $i => $pos ) {
		$b = hexdec( substr( $bg, $pos, 2 ) );
		$out .= str_pad( dechex( (int) round( $rgba[ $i ] * $rgba[3] + $b * ( 1 - $rgba[3] ) ) ), 2, '0', STR_PAD_LEFT );
	}
	return '#' . $out;
}

$T = [
	'maroon'      => '#8d183c', 'maroon-dark' => '#781433', 'gold' => '#b2892e', 'gold-dark' => '#96731f', 'gold-deep' => '#8a6a1c',
	'ink'         => '#272727', 'body' => '#5a5a5a', 'muted-old' => '#8a8a8a', 'muted' => '#6f6f6f', 'white' => '#ffffff', 'bg-alt' => '#f7f7f7',
	'success'     => '#2e7d32', 'success-bg' => '#f3fbf6', 'danger' => '#a71000', 'danger-bg' => '#fff6f7', 'info' => '#1f5f8b', 'info-bg' => '#f2f9fe',
	'warning-bg'  => '#fffbf5', 'light-on-old' => '#2ecc71', 'light-off-old' => '#e74c3c', 'light-on' => '#1e8e3e', 'light-off' => '#c62828',
	'maroon-tint' => sp_blend( [ 141, 24, 60, .08 ], '#ffffff' ), 'gold-wash' => sp_blend( [ 178, 137, 46, .15 ], '#ffffff' ), 'sky' => '#e3f0fc',
];

// [ label, fg, bg, min ratio ]
$pairs = [
	[ 'Primary button: white on maroon',            'white', 'maroon', 4.5 ],
	[ 'Primary hover: white on maroon-dark',         'white', 'maroon-dark', 4.5 ],
	[ 'Secondary (old): white on gold',              'white', 'gold', 4.5 ],
	[ 'Secondary option A: ink on gold',             'ink', 'gold', 4.5 ],
	[ 'Secondary option B: white on gold-deep',      'white', 'gold-deep', 4.5 ],
	[ 'Outline: maroon on white',                    'maroon', 'white', 4.5 ],
	[ 'Ghost: ink on white',                         'ink', 'white', 4.5 ],
	[ 'Ghost hover: maroon on maroon-tint',          'maroon', 'maroon-tint', 4.5 ],
	[ 'Danger: white on danger',                     'white', 'danger', 4.5 ],
	[ 'Link: maroon on white',                       'maroon', 'white', 4.5 ],
	[ 'Link hover (old): gold on white',             'gold', 'white', 4.5 ],
	[ 'Link hover (new): maroon-dark on white',      'maroon-dark', 'white', 4.5 ],
	[ 'Eyebrow (old): gold on white',                'gold', 'white', 4.5 ],
	[ 'Eyebrow (new): gold-deep on white',           'gold-deep', 'white', 4.5 ],
	[ 'Body text on white',                          'body', 'white', 4.5 ],
	[ 'Muted (old) on white',                        'muted-old', 'white', 4.5 ],
	[ 'Muted (new) on white',                        'muted', 'white', 4.5 ],
	[ 'Muted (new) on bg-alt (table headers)',       'muted', 'bg-alt', 4.5 ],
	[ 'Badge new (old): white on gold',              'white', 'gold', 4.5 ],
	[ 'Badge new (new): white on gold-deep',         'white', 'gold-deep', 4.5 ],
	[ 'Badge success',                               'success', 'success-bg', 4.5 ],
	[ 'Badge warning (old): gold-dark on warning-bg','gold-dark', 'warning-bg', 4.5 ],
	[ 'Badge warning (new): gold-deep on warning-bg','gold-deep', 'warning-bg', 4.5 ],
	[ 'Badge danger',                                'danger', 'danger-bg', 4.5 ],
	[ 'Badge muted (new)',                           'muted', 'bg-alt', 4.5 ],
	[ 'Badge role: maroon on maroon-tint',           'maroon', 'maroon-tint', 4.5 ],
	[ 'Alert info',                                  'info', 'info-bg', 4.5 ],
	[ 'Banner gold (old): white on gold',            'white', 'gold', 4.5 ],
	[ 'Banner gold (new): white on gold-deep',       'white', 'gold-deep', 4.5 ],
	[ 'Banner light: ink on gold-wash',              'ink', 'gold-wash', 4.5 ],
	[ 'Light on (old) vs white (UI 3:1)',            'light-on-old', 'white', 3.0 ],
	[ 'Light on (new) vs white (UI 3:1)',            'light-on', 'white', 3.0 ],
	[ 'Light off (old) vs white (UI 3:1)',           'light-off-old', 'white', 3.0 ],
	[ 'Light off (new) vs white (UI 3:1)',           'light-off', 'white', 3.0 ],
	[ 'Nav icon muted (new) vs white (UI 3:1)',      'muted', 'white', 3.0 ],
	[ 'Toggle track off: border grey vs white (UI)', 'muted', 'white', 3.0 ],
	[ 'Placeholder: muted on white',                 'muted', 'white', 4.5 ],
	[ 'Email footer (old): #8a8a8a on white',        'muted-old', 'white', 4.5 ],
	[ 'Email footer (new): muted on white',          'muted', 'white', 4.5 ],
	[ 'Input border: border-strong vs white (UI)',   'muted-old', 'white', 3.0 ],
	[ 'Focus ring: maroon vs white (UI)',            'maroon', 'white', 3.0 ],
	[ 'Banner gold (final): ink on gold',            'ink', 'gold', 4.5 ],
	[ 'Badge new (final): ink on gold',              'ink', 'gold', 4.5 ],
	// S2 (2026-11-18): larger error alerts, field errors, error toasts
	[ 'Alert error: danger on danger-bg',            'danger', 'danger-bg', 4.5 ],
	[ 'Alert error border: danger vs danger-bg (UI)','danger', 'danger-bg', 3.0 ],
	[ 'Field error text: danger on white',           'danger', 'white', 4.5 ],
	[ 'Field error text: danger on bg-alt',          'danger', 'bg-alt', 4.5 ],
	[ 'Error toast: ink on danger-bg',               'ink', 'danger-bg', 4.5 ],
	[ 'Alert warning: gold-deep on warning-bg',      'gold-deep', 'warning-bg', 4.5 ],
];

// Pairs still shipped in the plugin CSS (the "(old)" rows above are kept for the record only).
$shipped = [ 'Primary', 'Secondary option B', 'Outline', 'Ghost', 'Danger', 'Link: ', 'Link hover (new)', 'Eyebrow (new)', 'Body', 'Muted (new)', 'Badge new (new)', 'Badge success', 'Badge warning (new)', 'Badge danger', 'Badge muted (new)', 'Badge role', 'Alert info', 'Banner gold (final)', 'Banner light', 'Light on (new)', 'Light off (new)', 'Nav icon', 'Toggle track off', 'Placeholder', 'Email footer (new)', 'Input border', 'Focus ring', 'Alert error', 'Field error', 'Error toast', 'Alert warning' ];

$fail = 0; $shipped_fail = 0;
$only_shipped = in_array( '--shipped', $argv ?? [], true );
printf( "%-50s %-9s %-9s %6s %5s  %s\n", 'Pair', 'fg', 'bg', 'ratio', 'min', 'result' );
foreach ( $pairs as [ $label, $fg, $bg, $min ] ) {
	$is_shipped = (bool) array_filter( $shipped, fn( $s ) => str_starts_with( $label, $s ) );
	if ( $only_shipped && ! $is_shipped ) { continue; }
	$r  = sp_contrast( $T[ $fg ], $T[ $bg ] );
	$ok = $r >= $min;
	if ( ! $ok ) { $fail++; if ( $is_shipped ) { $shipped_fail++; } }
	printf( "%-50s %-9s %-9s %6.2f %5.1f  %s%s\n", $label, $T[ $fg ], $T[ $bg ], $r, $min, $ok ? 'PASS' : 'FAIL', $is_shipped ? '' : '  (reference only)' );
}
echo "\n", $fail ? "$fail pair(s) below threshold" : 'All pairs pass', " | shipped pairs failing: $shipped_fail\n";
exit( $shipped_fail ? 1 : 0 );
