#!/usr/bin/env bash
# migrate-all.sh — run the Phase 5 migration in order. Pass --dry-run to report only; any other flags go to every script.
#   ./migrate-all.sh --dry-run
#   ./migrate-all.sh            (Local rehearsal; undo with ./wp-run.sh migrate-reset.php --yes)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
set -e -o pipefail
for s in migrate-users migrate-farms migrate-memberships migrate-listings migrate-redirects; do
	echo "################ $s $*"
	"$DIR/wp-run.sh" "$DIR/$s.php" "$@" 2>&1 | grep -v php_imagick
done
case " $* " in *" --dry-run "*) ;; *) echo "################ migrate-verify"; "$DIR/wp-run.sh" "$DIR/migrate-verify.php" 2>&1 | grep -v php_imagick;; esac
