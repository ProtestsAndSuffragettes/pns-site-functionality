#!/usr/bin/env bash
set -euo pipefail

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
quality="$repo_root/.github/workflows/quality.yml"
release="$repo_root/.github/workflows/release-please.yml"

require() {
	local file=$1 text=$2
	grep -Fq -- "$text" "$file" || {
		printf 'Missing release workflow contract in %s: %s\n' "$file" "$text" >&2
		exit 1
	}
}
reject() {
	local file=$1 text=$2
	if grep -Fq -- "$text" "$file"; then
		printf 'Forbidden release workflow contract in %s: %s\n' "$file" "$text" >&2
		exit 1
	fi
}
require_before() {
	local file=$1 earlier=$2 later=$3
	local earlier_line later_line
	earlier_line=$(grep -Fn -- "$earlier" "$file" | head -n 1 | cut -d: -f1)
	later_line=$(grep -Fn -- "$later" "$file" | head -n 1 | cut -d: -f1)
	if [ -z "$earlier_line" ] || [ -z "$later_line" ] || [ "$earlier_line" -ge "$later_line" ]; then
		printf 'Expected workflow preparation before download in %s: %s before %s\n' "$file" "$earlier" "$later" >&2
		exit 1
	fi
}

require "$quality" 'push:'
require "$quality" '- main'
require "$quality" 'workflow_dispatch:'
require "$quality" "github.event_name == 'pull_request' && github.event.pull_request.head.sha || github.sha"
require "$quality" 'bash tests/release-workflow-contract.sh'
require_before "$quality" 'mkdir -p build/wordpress' 'wp core download --path=build/wordpress --version=7.0'
require "$quality" 'PNS_ROLE_TEST_PHASE=active wp eval-file "$GITHUB_WORKSPACE/tests/wordpress/draft-editor-proof.php" --use-include --path=build/wordpress'
require "$quality" 'PNS_ROLE_TEST_PHASE=inactive wp eval-file "$GITHUB_WORKSPACE/tests/wordpress/draft-editor-proof.php" --use-include --path=build/wordpress'

require "$release" 'workflow_run:'
require "$release" 'workflows: [Quality]'
require "$release" 'types: [completed]'
require "$release" 'branches: [main]'
require "$release" 'permissions: {}'
require "$release" 'contents: write'
require "$release" 'issues: write'
require "$release" 'pull-requests: write'
require "$release" 'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-a.yml@289352e08cdf10b15d07c4e1c890f385afc3d3f5'
require "$release" 'expected-workflow-path: .github/workflows/quality.yml'
require "$release" 'release-pr-head: release-please--branches--main--components--pns-site-functionality'
require "$release" 'actions: write'

reject "$release" 'gh api'
reject "$release" 'release-please-action'
reject "$release" 'autorelease:'
reject "$release" 'RAN_RELEASE'
reject "$release" 'secrets: inherit'

printf 'Release workflow contract tests passed.\n'
