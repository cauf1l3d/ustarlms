#!/bin/bash

set -e

ROOT=$(pwd)
OUT="$ROOT/context/code_map/generated"

mkdir -p "$OUT"

echo "# PHP files" > "$OUT/php_files.md"

find moodle/local/ustar \
-type f \
-name "*.php" \
| sort >> "$OUT/php_files.md"


echo "# Classes" > "$OUT/classes.md"

grep -R "class " moodle/local/ustar/classes \
--include="*.php" \
| sed 's/^/- /' \
>> "$OUT/classes.md"


echo "# Database tables" > "$OUT/database_tables.md"

grep -R "local_ustar_" moodle/local/ustar \
--include="*.php" \
| sed 's/^/- /' \
| sort -u \
>> "$OUT/database_tables.md"


echo "# Generated"

date > "$OUT/generated_at.txt"

git rev-parse HEAD > "$OUT/git_commit.txt"

echo "Code map generated"
