<?php
/**
 * js-balance-check.php — Crude syntax guard for the plugin's JS when Node is not installed: strips comments and string
 * literals, then checks that (), [], {} balance per file. Catches the typical "missing brace" slip; not a real parser.
 *
 * Run: php js-balance-check.php [file ...]   (defaults to every assets/js/*.js in the plugin)
 */
$files = array_slice( $argv, 1 );
if ( empty( $files ) ) {
	$files = glob( __DIR__ . '/../../wp-content/plugins/southpoll-membership/assets/js/*.js' ) ?: [];
}
$bad = 0;
foreach ( $files as $file ) {
	$src = (string) file_get_contents( $file );
	$src = preg_replace( '#/\*.*?\*/#s', '', $src );
	$src = preg_replace( '#(^|[^:\\\\])//[^\n]*#', '$1', $src );
	$src = preg_replace( "#'(?:[^'\\\\\n]|\\\\.)*'#", "''", $src );
	$src = preg_replace( '#"(?:[^"\\\\\n]|\\\\.)*"#', '""', $src );
	$src = preg_replace( '#`(?:[^`\\\\]|\\\\.)*`#s', '``', $src );
	$pairs  = [ '(' => ')', '[' => ']', '{' => '}' ];
	$stack  = [];
	$error  = '';
	$line   = 1;
	$length = strlen( $src );
	for ( $i = 0; $i < $length; $i++ ) {
		$ch = $src[ $i ];
		if ( "\n" === $ch ) {
			$line++;
			continue;
		}
		if ( isset( $pairs[ $ch ] ) ) {
			$stack[] = [ $ch, $line ];
		} elseif ( in_array( $ch, $pairs, true ) ) {
			$top = array_pop( $stack );
			if ( ! $top || $pairs[ $top[0] ] !== $ch ) {
				$error = "unexpected '{$ch}' at line {$line}" . ( $top ? " (open '{$top[0]}' from line {$top[1]})" : '' );
				break;
			}
		}
	}
	if ( '' === $error && ! empty( $stack ) ) {
		$top   = end( $stack );
		$error = "unclosed '{$top[0]}' from line {$top[1]}";
	}
	printf( "%s %s%s\n", '' === $error ? 'OK  ' : 'FAIL', basename( $file ), '' === $error ? '' : ' — ' . $error );
	if ( '' !== $error ) {
		$bad++;
	}
}
exit( $bad ? 1 : 0 );
