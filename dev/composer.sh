#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Trust the platform's existing CA bundle for both Composer and Git; never disable TLS.
exec docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer -e COMPOSER_CAFILE=/host-ca.pem -e GIT_SSL_CAINFO=/host-ca.pem \
  -v "${SSL_CERT_FILE:-/etc/ssl/certs/ca-certificates.crt}:/host-ca.pem:ro" \
  -v "$PWD:/app" -w /app \
  composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c "$@"
