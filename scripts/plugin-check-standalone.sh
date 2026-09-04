#!/usr/bin/env bash
# Fallback Plugin Check runner that needs NO new Docker network: one
# throwaway wordpress:cli container on the default bridge network, with
# WordPress running on the SQLite drop-in. Use when wp-env cannot start.
# Usage: bash scripts/plugin-check-standalone.sh [plugin-zip] [reports-dir] [label]
set -u
cd "$(dirname "$0")/.."
PLUGIN_ZIP="${1:-$(pwd)/release/opace-essential-seo-toolkit-2.0.0.zip}"
OUT="${2:-$(pwd)/reports}"
LABEL="${3:-baseline}"
if [ ! -f "$PLUGIN_ZIP" ]; then
	echo "Release ZIP not found: $PLUGIN_ZIP" >&2
	exit 2
fi
mkdir -p "$OUT"
docker run --rm --user root \
	-v "$PLUGIN_ZIP:/plugin.zip:ro" \
	-v "$OUT:/out" \
	-e LABEL="$LABEL" \
	wordpress:cli-php8.3 bash -c '
set -e
cd /var/www/html
wp() { php -d memory_limit=512M /usr/local/bin/wp --allow-root "$@"; }
wp cli info | grep -i -E "memory|php binary" || true
echo "[$(date +%T)] php $(php -r "echo PHP_VERSION;") | $(wp --version)"
php -m | grep -i -E "pdo_sqlite|sqlite3" || { echo "No sqlite extension in image"; exit 3; }
echo "[$(date +%T)] downloading WordPress 7.1"
curl -sSL https://wordpress.org/wordpress-7.1.tar.gz | tar xz --strip-components=1
ls wp-includes/version.php >/dev/null && grep -m1 "wp_version =" wp-includes/version.php
echo "[$(date +%T)] installing SQLite drop-in"
curl -sSL -o /tmp/sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
unzip -q -o /tmp/sqlite.zip -d wp-content/plugins/
COPY=wp-content/plugins/sqlite-database-integration/db.copy
grep -o "{[A-Z_]*}" "$COPY" | sort -u
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#/var/www/html/wp-content/plugins/sqlite-database-integration#g" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$COPY" > wp-content/db.php
mkdir -p wp-content/database
wp config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost=localhost --skip-check --quiet \
   --extra-php <<PHP
define( "WP_DEBUG", true );
define( "WP_DEBUG_LOG", true );
define( "WP_DEBUG_DISPLAY", false );
define( "SCRIPT_DEBUG", false );
PHP
echo "[$(date +%T)] wp core install"
wp core install --url=http://localhost:8888 --title="ESEOT Plugin Check" --admin_user=admin --admin_password=password --admin_email=admin@example.org --skip-email --quiet
wp core version --extra
echo "[$(date +%T)] installing Plugin Check 2.1.0"
wp plugin install plugin-check --version=2.1.0 --activate --quiet
wp plugin install /plugin.zip --activate --quiet
wp plugin list --fields=name,status,version
wp plugin check --help > /out/plugin-check-help.txt 2>&1 || true
echo "[$(date +%T)] running plugin check (table)"
wp plugin check opace-essential-seo-toolkit --format=table | tee /out/plugin-check-$LABEL.txt
echo "[$(date +%T)] running plugin check (json)"
wp plugin check opace-essential-seo-toolkit --format=json > /out/plugin-check-$LABEL.json || true
echo "[$(date +%T)] plugin check with experimental checks (table)"
wp plugin check opace-essential-seo-toolkit --format=table --include-experimental > /out/plugin-check-$LABEL-experimental.txt 2>&1 || true
echo "--- debug.log ---"
if [ -f wp-content/debug.log ]; then cp wp-content/debug.log /out/debug-$LABEL.log; cat wp-content/debug.log; else echo "(no debug.log written)"; fi
echo "[$(date +%T)] done"
'
