#!/usr/bin/env bash
# Builds the plugin package from the checked-out commit and checks it.
#
#   bin/package.sh <output directory> [version]
#
# The package is `git archive` of HEAD, so the export-ignore rules in .gitattributes decide what ships. The
# script fails unless the plugin header, the version constant, and the readme's stable tag name one version,
# the readme's changelog has a section for it, and the package holds the plugin files and no development file.
# With a version argument (the release tag), that version must be the one the files name.
#
# Writes <output directory>/empiriolabs-ai-provider.zip and <output directory>/notes.md (the changelog section).
set -euo pipefail

out="${1:?usage: bin/package.sh <output directory> [version]}"
expected="${2:-}"
slug="empiriolabs-ai-provider"

cd "$(git rev-parse --show-toplevel)"
mkdir -p "$out"

header="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$slug.php" | head -n 1 | tr -d '\r')"
constant="$(sed -n "s/^[[:space:]]*public const VERSION = '\([^']*\)';.*/\1/p" src/Provider/EmpirioLabsProvider.php | head -n 1)"
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -n 1 | tr -d '\r')"

fail() {
	echo "::error::$1"
	exit 1
}

[ -n "$header" ] || fail "No Version header in $slug.php"
[ "$header" = "$constant" ] || fail "The plugin header says $header and EmpirioLabsProvider::VERSION says $constant"
[ "$header" = "$stable" ] || fail "The plugin header says $header and the readme's stable tag says $stable"
if [ -n "$expected" ] && [ "$expected" != "$header" ]; then
	fail "The release is $expected and the plugin files say $header"
fi

# The changelog section of this version, as a Markdown list.
awk -v heading="= $header =" '
	{ sub(/\r$/, "") }
	$0 == heading { keep = 1; next }
	/^= .* =$/ { keep = 0 }
	/^== / { keep = 0 }
	keep && /^\* / { print "- " substr($0, 3) }
' readme.txt > "$out/notes.md"
[ -s "$out/notes.md" ] || fail "readme.txt has no changelog entries for $header"

git archive --format=zip --prefix="$slug/" -o "$out/$slug.zip" HEAD
files="$(unzip -Z1 "$out/$slug.zip")"

for required in "$slug.php" readme.txt LICENSE uninstall.php src/autoload.php src/Provider/EmpirioLabsProvider.php; do
	echo "$files" | grep -qx "$slug/$required" || fail "The package has no $required"
done
for development in .github .gitattributes .gitignore .distignore .wordpress-org README.md tests bin; do
	if echo "$files" | grep -q "^$slug/$development\(/\|\$\)"; then
		fail "The package contains $development"
	fi
done

echo "version $header"
echo "files $(echo "$files" | grep -vc '/$')"
echo "sha256 $(sha256sum "$out/$slug.zip" | cut -d ' ' -f 1)"
