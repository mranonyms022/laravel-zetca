#!/usr/bin/env bash
# Runs the test suite against several Laravel majors using locally installed PHP binaries.
# Each cell runs in an isolated copy so the working tree's vendor/ is untouched.
# Usage: COMPOSER_PHAR=/path/composer.phar tools/local-matrix.sh <workdir> "<laravel>:<testbench>:<php-binary>" ...
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$1"; shift
PHAR="${COMPOSER_PHAR:-$(dirname "$(command -v composer)")/composer.phar}"
mkdir -p "$WORK"
STATUS=0

for cell in "$@"; do
  IFS=':' read -r LARAVEL TESTBENCH PHPBIN <<< "$cell"
  DIR="$WORK/laravel-${LARAVEL//[^0-9]/}"
  rm -rf "$DIR" && mkdir -p "$DIR"
  cp -r "$ROOT/src" "$ROOT/tests" "$ROOT/config" "$ROOT/database" "$ROOT/tools" "$ROOT/composer.json" "$ROOT/phpunit.xml.dist" "$DIR/"
  echo "=== Laravel $LARAVEL / Testbench $TESTBENCH / PHP $("$PHPBIN" -r 'echo PHP_VERSION;')"
  cd "$DIR" || exit 1
  "$PHPBIN" "$PHAR" require --dev --no-interaction --no-update "orchestra/testbench:$TESTBENCH" -q &&
  "$PHPBIN" "$PHAR" require --no-interaction --no-update "illuminate/support:$LARAVEL" -q &&
  "$PHPBIN" "$PHAR" update --no-interaction --prefer-dist -W -q
  if [ $? -ne 0 ]; then echo "composer resolution FAILED"; STATUS=1; cd "$ROOT"; continue; fi
  echo "framework $("$PHPBIN" "$PHAR" show laravel/framework 2>/dev/null | grep -m1 '^versions') | $("$PHPBIN" vendor/phpunit/phpunit/phpunit --version | head -1)"
  "$PHPBIN" tools/check-core-boundary.php || STATUS=1
  "$PHPBIN" vendor/phpunit/phpunit/phpunit --colors=never > phpunit.log 2>&1
  RC=$?
  tail -3 phpunit.log
  if [ $RC -ne 0 ]; then STATUS=1; grep -E -A12 "^[0-9]+\) " phpunit.log | head -60; fi
  cd "$ROOT"
done
echo "matrix exit status: $STATUS"
exit $STATUS
