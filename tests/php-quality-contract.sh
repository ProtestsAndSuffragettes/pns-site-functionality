#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
fixture_dir=$(mktemp -d "$PWD/.quality-contract.XXXXXX")
trap 'rm -rf "$fixture_dir"' EXIT
fixture="$fixture_dir/quality.php"

cat > "$fixture" <<'PHP'
<?php
/**
 * Quality contract fixture.
 *
 * @package PNS_Site_Functionality
 */

/**
 * Return example values.
 *
 * @return array<string, string> Example values.
 */
function pns_site_functionality_quality_contract() {
	$short = 'a';
	$longer_name = 'b';
	$values = array(
		'short' => $short,
		'longer_name' => $longer_name,
	);
	return $values;
}
PHP

# Both alignment rules must be blocking under the ordinary ruleset (-n included).
if php vendor/bin/phpcs --standard=.phpcs.xml -n -q --report=json "$fixture" > "$fixture_dir/report.json"; then
	echo 'Alignment violations unexpectedly passed PHPCS' >&2
	exit 1
fi
php -r '
if (!isset($argv[1])) { fwrite(STDERR, "Checker report argument is required.\n"); exit(1); }
$report_json = file_get_contents($argv[1]);
if (false === $report_json) { throw new RuntimeException("Checker report could not be read."); }
$report = json_decode($report_json, true, 512, JSON_THROW_ON_ERROR);
$found = [];
foreach ($report["files"] as $file) {
    foreach ($file["messages"] as $message) {
        if ($message["type"] === "ERROR") { $found[$message["source"]] = true; }
    }
}
foreach (["Generic.Formatting.MultipleStatementAlignment.NotSame", "WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned"] as $source) {
    if (!isset($found[$source])) { fwrite(STDERR, "Missing blocking diagnostic: $source\n"); exit(1); }
}
' "$fixture_dir/report.json"

status=0
php vendor/bin/phpcbf --standard=.phpcs.xml -q "$fixture" || status=$?
# PHPCBF returns 1 when all fixable violations were corrected.
[[ "$status" -eq 1 ]]
php vendor/bin/phpcs --standard=.phpcs.xml -q "$fixture"
cp "$fixture" "$fixture_dir/first-pass.txt"
php vendor/bin/phpcbf --standard=.phpcs.xml -q "$fixture"
cmp "$fixture" "$fixture_dir/first-pass.txt"

# Verify build sequencing for clean, fixed and failed formatter outcomes.
mkdir "$fixture_dir/bin"
cat > "$fixture_dir/bin/composer" <<'SH'
#!/usr/bin/env bash
printf '%s\n' "$1" >> "$BUILD_TEST_LOG"
case "$1" in
    standards:fix) exit "$BUILD_TEST_FIX_STATUS" ;;
    standards) exit "$BUILD_TEST_CHECK_STATUS" ;;
    *) exit 99 ;;
esac
SH
chmod +x "$fixture_dir/bin/composer"
for fix_status in 0 1 2 3; do
    : > "$fixture_dir/build.log"
    status=0
    PATH="$fixture_dir/bin:$PATH" BUILD_TEST_LOG="$fixture_dir/build.log" \
        BUILD_TEST_FIX_STATUS="$fix_status" BUILD_TEST_CHECK_STATUS=0 \
        bash scripts/format-php-for-build.sh || status=$?
    if [[ "$fix_status" -le 1 ]]; then
        [[ "$status" -eq 0 ]]
        [[ "$(cat "$fixture_dir/build.log")" == $'standards:fix\nstandards' ]]
    else
        [[ "$status" -eq "$fix_status" ]]
        [[ "$(cat "$fixture_dir/build.log")" == 'standards:fix' ]]
    fi
done
# A supposedly successful fix cannot bypass the final standards check.
status=0
PATH="$fixture_dir/bin:$PATH" BUILD_TEST_LOG="$fixture_dir/build.log" \
    BUILD_TEST_FIX_STATUS=1 BUILD_TEST_CHECK_STATUS=2 \
    bash scripts/format-php-for-build.sh || status=$?
[[ "$status" -eq 2 ]]

# Prove the actual Composer parser aggregate succeeds before the negative case.
composer lint:syntax > "$fixture_dir/syntax-valid.log" 2>&1

# Exercise the actual Composer parser aggregate, not only a standalone php -l.
printf '<?php function broken( {\n' > "$fixture"
if composer lint:syntax > "$fixture_dir/syntax.log" 2>&1; then
	echo 'Invalid PHP unexpectedly passed lint:syntax' >&2
	exit 1
fi
echo 'PHP quality contract passed'
