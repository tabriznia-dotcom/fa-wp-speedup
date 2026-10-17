#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
cleanup() {
  ./dev/wp.sh eval 'if(home_url()!=="http://localhost:8080"){return;} delete_option("faws_advanced_fixture"); update_option("faws_advanced",array("mode"=>"off")); update_option("faws_media_options",array());' >/dev/null
  ./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/cdn-fixture.php disable >/dev/null
}
trap cleanup EXIT
image=php:8.1-cli@sha256:76e563191d1ade120313a8736df24154d21da5155c0756f147c0b01bd19d9087
docker run --rm -v "$PWD:/app" -w /app "$image" php vendor/bin/phpcs
docker run --rm -v "$PWD:/app" -w /app "$image" sh -ec 'find includes tests -name "*.php" -print0 | xargs -0 -n1 php -l; php -l fa-wp-speedup.php; php -l uninstall.php'
docker run --rm -v "$PWD:/app" -v "${FAWS_DEV_STATE:-/workspace/.faws-dev}/artifacts:/fixtures:ro" -w /app "$image" php tests/catalog.php
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/integration.php
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/woo-fixtures.php > "${FAWS_DEV_STATE:-/workspace/.faws-dev}/woo-fixtures.json"
python3 tests/http-smoke.py
npm run test:browser
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/media-advanced.php > "${FAWS_DEV_STATE:-/workspace/.faws-dev}/media-fixtures.json"
cat "${FAWS_DEV_STATE:-/workspace/.faws-dev}/media-fixtures.json"
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/advanced-fixture.php
node tests/media-advanced-browser.mjs
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/cdn-fixture.php setup
node tests/cdn-browser.mjs origin
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/cdn-fixture.php local
node tests/cdn-browser.mjs local
