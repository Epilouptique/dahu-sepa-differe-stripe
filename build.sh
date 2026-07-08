#!/usr/bin/env bash
# Construit dahu-sepa-differe-stripe.zip à la racine du projet, prêt à installer
# dans WordPress. Le zip contient un dossier racine dahu-sepa-differe-stripe/
# (attendu par WP). Le zip n'est jamais poussé sur GitHub (voir .gitignore).
set -euo pipefail

SLUG="dahu-sepa-differe-stripe"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD="$ROOT/$SLUG"
ZIP="$ROOT/$SLUG.zip"

rm -rf "$BUILD" "$ZIP"
mkdir -p "$BUILD"

# Fichiers embarqués dans le plugin livré.
cp "$ROOT/$SLUG.php" "$BUILD/"
cp "$ROOT/README.md" "$BUILD/"
cp "$ROOT/README.txt" "$BUILD/"
cp "$ROOT/LICENSE" "$BUILD/"

# Zip (le -j est volontairement absent : on garde le dossier racine).
( cd "$ROOT" && zip -r "$SLUG.zip" "$SLUG" >/dev/null )
rm -rf "$BUILD"

echo "OK : $ZIP"
