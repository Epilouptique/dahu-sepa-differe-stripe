#!/usr/bin/env bash
# Construit dist/dahu-sepa-differe-stripe.zip prêt à installer dans WordPress.
# Le zip contient un dossier racine dahu-sepa-differe-stripe/ (attendu par WP).
set -euo pipefail

SLUG="dahu-sepa-differe-stripe"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD="$ROOT/dist/$SLUG"
ZIP="$ROOT/dist/$SLUG.zip"

rm -rf "$ROOT/dist"
mkdir -p "$BUILD"

# Fichiers embarqués dans le plugin livré.
cp "$ROOT/$SLUG.php" "$BUILD/"
cp "$ROOT/README.md" "$BUILD/"

# Zip (le -j est volontairement absent : on garde le dossier racine).
( cd "$ROOT/dist" && zip -r "$SLUG.zip" "$SLUG" >/dev/null )
rm -rf "$BUILD"

echo "OK : $ZIP"
