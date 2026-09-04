#!/usr/bin/env bash
set -euo pipefail

db_name="$1"; db_user="$2"; db_pass="$3"; db_host="$4"; wp_version="$5"
core_dir="/tmp/wordpress"
tests_dir="/tmp/wordpress-tests-lib"
mkdir -p "$core_dir" "$tests_dir"
curl -sSL "https://wordpress.org/wordpress-${wp_version}.tar.gz" | tar xz --strip-components=1 -C "$core_dir"
svn export -q --force "https://develop.svn.wordpress.org/tags/${wp_version}/tests/phpunit/includes" "$tests_dir/includes"
svn export -q --force "https://develop.svn.wordpress.org/tags/${wp_version}/tests/phpunit/data" "$tests_dir/data"
cp "$tests_dir/wp-tests-config-sample.php" "$tests_dir/wp-tests-config.php" 2>/dev/null || curl -sSL "https://develop.svn.wordpress.org/tags/${wp_version}/wp-tests-config-sample.php" -o "$tests_dir/wp-tests-config.php"
sed -i "s/youremptytestdbnamehere/${db_name}/; s/yourusernamehere/${db_user}/; s/yourpasswordhere/${db_pass}/; s|localhost|${db_host}|; s|dirname( __FILE__ ) . '/src/'|'${core_dir}/'|" "$tests_dir/wp-tests-config.php"

