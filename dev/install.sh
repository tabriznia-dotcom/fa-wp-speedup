#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
state_dir="${FAWS_DEV_STATE:-/workspace/.faws-dev}"
mkdir -p "$state_dir/artifacts"
chmod 700 "$state_dir"
chmod 755 "$state_dir/artifacts"
# Download only immutable versioned fixtures; TLS and SRI remain enabled.
for kind in css js; do
  if [ "$kind" = css ]; then filename=bootstrap.min.css; subdir=css; else filename=bootstrap.bundle.min.js; subdir=js; fi
  fixture_tmp=$(mktemp "$state_dir/artifacts/.download-XXXXXX")
  curl --fail --silent --show-error --max-time 45 --max-filesize 1048576 "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/$subdir/$filename" -o "$fixture_tmp"
  python3 - "$fixture_tmp" "$kind" <<'PY'
import base64, hashlib, sys
expected = {'css':'QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH', 'js':'YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz'}
assert base64.b64encode(hashlib.sha384(open(sys.argv[1],'rb').read()).digest()).decode() == expected[sys.argv[2]], 'Official SRI mismatch'
PY
  chmod 644 "$fixture_tmp"
  mv "$fixture_tmp" "$state_dir/artifacts/bootstrap.$kind"
done
if [ ! -f "$state_dir/artifacts/woocommerce.zip" ]; then
  fixture_tmp=$(mktemp "$state_dir/artifacts/.woocommerce-XXXXXX")
  curl --fail --location --silent --show-error --max-time 45 --max-filesize 104857600 https://github.com/woocommerce/woocommerce/releases/download/9.9.5/woocommerce.zip -o "$fixture_tmp"
  python3 - "$fixture_tmp" <<'PY'
import zipfile,sys,re
with zipfile.ZipFile(sys.argv[1]) as z:
    assert re.search(rb'Version:\s+9\.9\.5\b', z.read('woocommerce/woocommerce.php')), 'Unexpected WooCommerce archive'
PY
  chmod 644 "$fixture_tmp"
  mv "$fixture_tmp" "$state_dir/artifacts/woocommerce.zip"
fi
# Compose interpolation needs runtime credentials; start.sh owns initialization.
docker pull php:8.1-cli@sha256:76e563191d1ade120313a8736df24154d21da5155c0756f147c0b01bd19d9087
./dev/composer.sh install --no-interaction --prefer-source
npm ci --cache "$state_dir/npm-cache" --ignore-scripts --no-audit --no-fund
./dev/start.sh
./dev/wp.sh plugin is-installed woocommerce >/dev/null 2>&1 || ./dev/wp.sh plugin install /fixtures/woocommerce.zip --activate
./dev/wp.sh plugin activate woocommerce
