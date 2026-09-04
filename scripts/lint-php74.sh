#!/usr/bin/env bash
# Syntax-lint every PHP file in the plugin trunk under PHP 7.4 and PHP 8.4
# using the official php:*-cli Docker images. Exits non-zero on the first
# failing PHP version. Pulls each image with a 5 minute deadline.
# Usage: bash scripts/lint-php74.sh [path-to-trunk]
set -uo pipefail
cd "$(dirname "$0")/.."
TRUNK="${1:-$(pwd)}"
if [ ! -d "$TRUNK" ]; then
	echo "Trunk not found: $TRUNK" >&2
	exit 2
fi
echo "Linting: $TRUNK"
STATUS=0
for IMG in php:7.4-cli php:8.4-cli; do
	echo
	echo "=== $IMG ==="
	if ! docker image inspect "$IMG" >/dev/null 2>&1; then
		echo "Pulling $IMG (deadline 5 min)..."
		docker pull "$IMG" >/dev/null 2>&1 &
		PULL_PID=$!
		DEADLINE=$(( $(date +%s) + 300 ))
		while kill -0 "$PULL_PID" 2>/dev/null; do
			if [ "$(date +%s)" -ge "$DEADLINE" ]; then
				kill "$PULL_PID" 2>/dev/null || true
				echo "FAIL: could not pull $IMG within 5 minutes" >&2
				STATUS=1
				continue 2
			fi
			sleep 5
		done
		wait "$PULL_PID" || { echo "FAIL: docker pull $IMG failed" >&2; STATUS=1; continue; }
	fi
	OUT=$(docker run --rm -v "$TRUNK:/app:ro" "$IMG" sh -c 'find /app -name "*.php" -not -path "*/vendor/*" -print0 | xargs -0 -n1 php -l' 2>&1)
	echo "$OUT"
	if echo "$OUT" | grep -q -E "Parse error|Fatal error|Errors parsing"; then
		echo "FAIL under $IMG" >&2
		STATUS=1
	else
		echo "OK under $IMG ($(echo "$OUT" | grep -c 'No syntax errors') files)"
	fi
done
exit $STATUS
