#!/usr/bin/env bash
# wp-run.sh — run a PHP script with WordPress loaded, using Local's PHP + this site's php.ini
# Usage: ./wp-run.sh <script.php> [args...]
PHP_BIN="/c/Users/Carson/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
PHP_INI="/c/Users/Carson/AppData/Roaming/Local/run/v-sOBIKnr/conf/php/php.ini"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "$PHP_BIN" -c "$PHP_INI" -d display_errors=1 "$DIR/wp-run.php" "$@"
