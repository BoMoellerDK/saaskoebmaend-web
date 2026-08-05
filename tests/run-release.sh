#!/usr/bin/env bash
set -euo pipefail

test_temp_dir="${RUNNER_TEMP:-/tmp}"
runtime_file="$test_temp_dir/saaskoebmaend-youtube-runtime-test.json"
server_log="$test_temp_dir/saaskoebmaend-php-server.log"

SAASKOBMAEND_YOUTUBE_RUNTIME_FILE="$runtime_file" \
SAASKOBMAEND_YOUTUBE_FEED_URL='file:///dev/null' \
php -S 127.0.0.1:8877 -t public_html tests/router.php >"$server_log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT

server_ready=0
for attempt in {1..20}; do
  if curl --fail --silent http://127.0.0.1:8877/ >/dev/null; then
    server_ready=1
    break
  fi
  sleep 0.25
done

if [[ "$server_ready" != "1" ]]; then
  echo "Testserveren startede ikke."
  sed -n '1,160p' "$server_log"
  exit 1
fi

SAASKOBMAEND_YOUTUBE_RUNTIME_FILE="$runtime_file" php tests/release.php
