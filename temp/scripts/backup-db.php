<?php
/**
 * backup-db.php — dump the Local database with Local's own mysqldump before a destructive step (cutover, reset).
 *
 *   ./wp-run.sh backup-db.php [--label=before-cutover]
 *
 * Writes app/backups/<date>-<label>.sql.gz (outside the web root; never under temp/). The DB password is passed to
 * mysqldump through the MYSQL_PWD environment variable and is never printed or written anywhere.
 */
if ( ! defined( 'SP_WP_RUN' ) ) { die( "Run via wp-run.sh\n" ); }

$label = 'backup';
foreach ( $argv as $a ) { if ( str_starts_with( $a, '--label=' ) ) { $label = sanitize_file_name( substr( $a, 8 ) ) ?: 'backup'; } }

$bin = 'C:/Users/Carson/AppData/Roaming/Local/lightning-services/mysql-8.0.35+4/bin/win64/bin/mysqldump.exe';
if ( ! file_exists( $bin ) ) { fwrite( STDERR, "mysqldump not found at $bin — update the path for this Local MySQL version\n" ); exit( 1 ); }

$dir = dirname( ABSPATH ) . '/backups';
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0750, true ) ) { fwrite( STDERR, "cannot create $dir\n" ); exit( 1 ); }
$file = $dir . '/' . gmdate( 'Ymd-His' ) . "-$label.sql.gz";

$port = (int) ini_get( 'mysqli.default_port' ) ?: 3306;
$cmd  = sprintf( '"%s" --host=127.0.0.1 --port=%d --user=%s --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 --no-tablespaces %s', $bin, $port, escapeshellarg( DB_USER ), escapeshellarg( DB_NAME ) );
$env  = array_merge( $_SERVER, [ 'MYSQL_PWD' => DB_PASSWORD ] );
unset( $env['argv'], $env['argc'] );
$env  = array_filter( $env, 'is_string' );

$proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, ABSPATH, $env );
if ( ! is_resource( $proc ) ) { fwrite( STDERR, "could not start mysqldump\n" ); exit( 1 ); }
$gz = gzopen( $file, 'wb6' );
$bytes = 0;
while ( ! feof( $pipes[1] ) ) { $chunk = fread( $pipes[1], 1 << 20 ); if ( '' !== $chunk && false !== $chunk ) { gzwrite( $gz, $chunk ); $bytes += strlen( $chunk ); } }
gzclose( $gz );
$err = stream_get_contents( $pipes[2] );
fclose( $pipes[1] ); fclose( $pipes[2] );
$code = proc_close( $proc );
$err  = trim( (string) preg_replace( '/.*Using a password on the command line.*\n?/', '', $err ) );
if ( 0 !== $code || $bytes < 1024 ) { fwrite( STDERR, "mysqldump failed (exit $code, $bytes bytes): $err\n" ); @unlink( $file ); exit( 1 ); }
printf( "backup written: %s (%s raw, %s gzipped)%s\n", $file, size_format( $bytes ), size_format( filesize( $file ) ), $err ? " — notes: $err" : '' );
