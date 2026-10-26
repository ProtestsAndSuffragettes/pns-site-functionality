#!/usr/bin/env bash
set -euo pipefail

status=0
composer standards:fix || status=$?
if [[ "$status" -gt 1 ]]; then
	exit "$status"
fi
composer standards
