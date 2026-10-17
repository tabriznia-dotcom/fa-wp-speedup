#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
state_dir="${FAWS_DEV_STATE:-/workspace/.faws-dev}"
mkdir -p "$state_dir"
chmod 700 "$state_dir"
if [ ! -f "$state_dir/credentials.env" ]; then
  umask 077
  python3 - "$state_dir/credentials.env" <<'PY'
import secrets, sys
with open(sys.argv[1], 'x') as f:
    f.write('FAWS_DB_PASSWORD=' + secrets.token_hex(24) + '\n')
    f.write('FAWS_ADMIN_PASSWORD=' + secrets.token_hex(24) + '\n')
PY
fi
set -a
source "$state_dir/credentials.env"
set +a
compose=(docker compose -f dev/compose.yaml)
"${compose[@]}" up -d --wait db wordpress
for attempt in {1..30}; do
  if "${compose[@]}" run --rm cli core version > /dev/null 2>&1; then break; fi
  sleep 1
done
if ! "${compose[@]}" run --rm cli core is-installed > /dev/null 2>&1; then
  "${compose[@]}" run --rm -T cli eval 'require_once ABSPATH . "wp-admin/includes/upgrade.php"; wp_install("FAWS test site", "faws-admin", "test@example.invalid", true, "", getenv("FAWS_ADMIN_PASSWORD")); update_option("home", "http://localhost:8080"); update_option("siteurl", "http://localhost:8080");'
fi
"${compose[@]}" run --rm cli plugin activate fa-wp-speedup
curl --fail --silent --show-error --max-time 15 http://127.0.0.1:8080/ -o "$state_dir/home.html"
python3 - "$state_dir/home.html" <<'PY'
import sys
assert '<html' in open(sys.argv[1]).read().lower(), 'Homepage was not HTML'
print('WordPress homepage functional request passed; credentials retained outside checkout.')
PY
