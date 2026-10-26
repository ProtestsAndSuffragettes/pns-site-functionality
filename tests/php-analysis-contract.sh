#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
fixture_dir=$(mktemp -d "${TMPDIR:-/tmp}/pns-site-functionality-contract.XXXXXX")
trap 'status=$?; if [[ $status -ne 0 ]]; then tail -n 40 "$fixture_dir"/repo/*.log >&2; fi; rm -rf "$fixture_dir"' EXIT
mkdir "$fixture_dir/repo"
for item in src scripts tests .github .phpcs.xml phpunit.xml composer.json phpstan.neon pns-site-functionality.php; do
    cp -a "$item" "$fixture_dir/repo/"
done
ln -s "$PWD/vendor" "$fixture_dir/repo/vendor"
cd "$fixture_dir/repo"
composer analyze > clean.log 2>&1

# Literal fixture is valid PHP at Level 8. Mutating its native nullable producer
# below creates deliberate Level 8-only errors through the actual runner.
cat > ../probe.txt <<'PHP'
<?php
function pns_site_functionality_analysis_probe(string $value): string {
    return strtoupper($value);
}
PHP
for role in src scripts tests; do
    mkdir -p "$role/analysis-contract/vendor/node_modules/nested"
    sed "s/analysis_probe/analysis_probe_$role/; s/string \$value/?string \$value/" ../probe.txt > "$role/analysis-contract/vendor/node_modules/nested/probe_$role.php"
done
if composer analyze > nullable.log 2>&1; then
    echo 'Nullable maintained PHP unexpectedly passed the canonical runner' >&2; exit 1
fi
for role in src scripts tests; do grep -q "probe_$role" nullable.log; done
grep -q 'string|null given' nullable.log
sed 's/level: 8/level: 7/' phpstan.neon > level7.neon
php vendor/bin/phpstan analyse -c level7.neon --no-progress --memory-limit=1G > level7.log 2>&1
rm -rf src/analysis-contract scripts/analysis-contract tests/analysis-contract

# A lower configured floor cannot turn the blocking runner green.
cp phpstan.neon original.neon
sed 's/level: 8/level: 7/' original.neon > phpstan.neon
if composer analyze > floor.log 2>&1; then echo 'Downgraded floor passed' >&2; exit 1; fi
grep -q 'requires Level 8' floor.log
cp original.neon phpstan.neon

# Automatic directory selection includes new PHP; otherwise new file forms fail closed.
cp ../probe.txt src/automatic.php
composer analyze > included.log 2>&1
cp ../probe.txt outside.php
if composer analyze > outside.log 2>&1; then echo 'Unselected root PHP passed' >&2; exit 1; fi
grep -q 'outside.php' outside.log
rm outside.php
cp ../probe.txt src/extensionless
if composer analyze > extensionless.log 2>&1; then echo 'Unselected extensionless PHP passed' >&2; exit 1; fi
grep -q 'src/extensionless' extensionless.log
rm src/extensionless src/automatic.php

# The literal executable shell snippet is also maintained and cannot hide errors.
cp tests/php-analysis-contract.sh extra.sh
sed 's/analysis_probe/analysis_extra_probe/g; s/string $value/?string $value/' extra.sh > extra.tmp && mv extra.tmp extra.sh
if composer analyze > embedded.log 2>&1; then echo 'Embedded nullable PHP passed' >&2; exit 1; fi
grep -q 'extra.sh' embedded.log
grep -q 'string|null given' embedded.log
rm extra.sh
# Separate executable snippets cannot satisfy each other's declarations.
cp tests/php-analysis-contract.sh extra.sh
sed 's/function pns_site_functionality_analysis_probe(string $value): string {/pns_site_functionality_analysis_probe("fixture");\nfunction pns_site_functionality_separate_probe(string $value): string {/' extra.sh > extra.tmp && mv extra.tmp extra.sh
if composer analyze > isolation.log 2>&1; then echo 'Separate snippet symbols leaked' >&2; exit 1; fi
grep -q 'extra.sh' isolation.log
grep -q 'function.notFound' isolation.log
rm extra.sh
printf '%s\n' '#!/usr/bin/env bash' "php -d display_errors=1 -r 'exit(0);'" > unsupported
if composer analyze > unsupported.log 2>&1; then echo 'Unsupported inline PHP passed' >&2; exit 1; fi
grep -q 'Embedded PHP form requires review: unsupported' unsupported.log
rm unsupported
for command in "php --process-code 'echo 1;'" "php --run 'exit(0);'" 'if php -r "exit(0);"; then true; fi'; do
    printf '%s\n' '#!/usr/bin/env bash' "$command" > unsupported
    if composer analyze > unsupported.log 2>&1; then echo 'Unsupported PHP invocation passed' >&2; exit 1; fi
    grep -q 'Embedded PHP form requires review: unsupported' unsupported.log
    rm unsupported
done

# Executable PHP renamed as metadata must not disappear behind data classification.
cp ../probe.txt disguised.json
if composer analyze > disguised.log 2>&1; then echo 'Disguised PHP passed' >&2; exit 1; fi
grep -q 'disguised.json' disguised.log
rm disguised.json
printf '\357\273\277' > disguised.json
cat ../probe.txt >> disguised.json
if composer analyze > disguised.log 2>&1; then echo 'BOM-prefixed PHP passed' >&2; exit 1; fi
grep -q 'disguised.json' disguised.log
rm disguised.json
sed '1s/php//' ../probe.txt > src/bare.php
if composer analyze > bare.log 2>&1; then echo 'Bare PHP tag passed' >&2; exit 1; fi
grep -q 'Unsupported PHP opening tag' bare.log
rm src/bare.php
sed '1s/php/xml bogus/' ../probe.txt > false-xml.json
if composer analyze > false-xml.log 2>&1; then echo 'False XML declaration passed' >&2; exit 1; fi
grep -q 'Unsupported PHP opening tag' false-xml.log
rm false-xml.json
printf '%s\n' '<html>' > mixed.json
cat ../probe.txt >> mixed.json
if composer analyze > mixed.log 2>&1; then echo 'Mixed HTML PHP escaped coverage' >&2; exit 1; fi
grep -q 'mixed.json' mixed.log
rm mixed.json
printf "cat <<'PHP'\n" > extra.sh
sed '1s/php//' ../probe.txt >> extra.sh
printf '%s\n' PHP >> extra.sh
if composer analyze > bare-heredoc.log 2>&1; then echo 'Bare heredoc PHP passed' >&2; exit 1; fi
grep -q 'Unsupported PHP opening tag' bare-heredoc.log
rm extra.sh
printf "cat <<'PHP'\n" > extra.sh
sed '1s/php/= "value"; ?>/' ../probe.txt >> extra.sh
printf '%s\n' PHP >> extra.sh
composer analyze > echo-heredoc.log 2>&1
rm extra.sh
cp phpunit.xml valid.xml
composer analyze > restored.log 2>&1
echo 'PHP analysis contract passed'
