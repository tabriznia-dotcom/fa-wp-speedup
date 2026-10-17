#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
set -a
source "${FAWS_DEV_STATE:-/workspace/.faws-dev}/credentials.env"
set +a
exec docker compose -f dev/compose.yaml run --rm -T cli "$@"
