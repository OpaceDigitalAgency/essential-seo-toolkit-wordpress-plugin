#!/usr/bin/env bash
# Fallback PHPUnit runner that needs NO new Docker network (use when wp-env
# cannot start). Mirrors the wp-env tests container layout so the same
# tests/bootstrap.php and phpunit.xml.dist are used unchanged:
#   /var/www/html                                   WordPress core (ABSPATH)
#   /var/www/html/wp-content/plugins/opace-essential-seo-toolkit  plugin trunk
#   /var/www/html/wp-content/opace-eseot-dev        this workspace
#   /wordpress-phpunit  (WP_TESTS_DIR)              WP core test library
# A MariaDB container named opace-eseot-tests-db runs on Docker's default
# bridge network and is reused between runs.
#
# Usage:
#   bash scripts/phpunit-standalone.sh [phpunit args...]   e.g. -- --filter LinkResolverTest
#   bash scripts/phpunit-standalone.sh --down               remove the DB container
# Env: ESEOT_WP_CORE, ESEOT_WP_TESTS_LIB, ESEOT_TRUNK override the default paths.
set -uo pipefail
cd "$(dirname "$0")/.."
WS="$(pwd)"
TRUNK="${ESEOT_TRUNK:-$WS}"
CACHE="${ESEOT_CACHE_DIR:-$WS/.cache}"
CORE="${ESEOT_WP_CORE:-$CACHE/wordpress-7.1}"
TESTS_LIB="${ESEOT_WP_TESTS_LIB:-$CACHE/wordpress-phpunit}"
DB_NAME=opace-eseot-tests-db
IMAGE=wordpress:cli-php8.3

if [ "${1:-}" = "--down" ]; then
	docker rm -f "$DB_NAME" >/dev/null 2>&1 && echo "Removed $DB_NAME" || echo "$DB_NAME not running"
	exit 0
fi

mkdir -p "$CACHE"
if [ ! -f "$CORE/wp-includes/version.php" ]; then
	echo "Fetching WordPress 7.1 core into $CORE"
	mkdir -p "$CORE"
	curl -sSL https://wordpress.org/wordpress-7.1.tar.gz | tar xz --strip-components=1 -C "$CORE"
fi
if [ ! -f "$TESTS_LIB/includes/bootstrap.php" ]; then
	echo "Fetching WordPress 7.1 PHPUnit test library into $TESTS_LIB (svn export)"
	mkdir -p "$TESTS_LIB"
	svn export -q --force https://develop.svn.wordpress.org/tags/7.1/tests/phpunit/includes "$TESTS_LIB/includes"
	svn export -q --force https://develop.svn.wordpress.org/tags/7.1/tests/phpunit/data "$TESTS_LIB/data"
fi
if [ ! -f "$WS/vendor/bin/phpunit" ]; then
	echo "Installing composer dependencies (composer:2 image)"
	docker run --rm -v "$WS:/app" -w /app composer:2 composer install --no-interaction --prefer-dist --no-progress
fi

# Database container (default bridge network, no ports published).
if ! docker ps --format '{{.Names}}' | grep -qx "$DB_NAME"; then
	docker rm -f "$DB_NAME" >/dev/null 2>&1 || true
	echo "Starting $DB_NAME (mariadb:lts)"
	docker run -d --name "$DB_NAME" \
		-e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wptests \
		-e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:lts >/dev/null
fi
DEADLINE=$(( $(date +%s) + 90 ))
until docker exec "$DB_NAME" mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1; do
	if [ "$(date +%s)" -ge "$DEADLINE" ]; then echo "FAIL: $DB_NAME not ready after 90s" >&2; docker logs --tail 20 "$DB_NAME"; exit 1; fi
	sleep 2
done
DB_IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$DB_NAME")
echo "Database ready at $DB_IP"

cat > "$TESTS_LIB/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '/var/www/html/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_TESTS_FORCE_KNOWN_BUGS', false );
define( 'WP_DEBUG', true );
define( 'DB_NAME', 'wptests' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', 'wp' );
define( 'DB_HOST', '$DB_IP' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP

echo "Running PHPUnit ($IMAGE, PHP $(docker run --rm $IMAGE php -r 'echo PHP_VERSION;'))"
docker run --rm --user root \
	-v "$CORE:/var/www/html" \
	-v "$TRUNK:/var/www/html/wp-content/plugins/opace-essential-seo-toolkit:ro" \
	-v "$WS:/var/www/html/wp-content/opace-eseot-dev" \
	-v "$TESTS_LIB:/wordpress-phpunit" \
	-e WP_TESTS_DIR=/wordpress-phpunit \
	-w /var/www/html/wp-content/opace-eseot-dev \
	"$IMAGE" php -d memory_limit=512M vendor/bin/phpunit "$@"
