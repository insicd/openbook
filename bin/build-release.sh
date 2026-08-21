#!/usr/bin/env bash
#
# Costruisce lo zip di release per shared hosting (con vendor/) e stampa
# il manifesto JSON da pubblicare su https://about.openb.app/releases/
#
# Uso:
#   ./bin/build-release.sh
#   ./bin/build-release.sh 26.34
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
  # Non includere config/openbook.php: usa env() di Laravel e fallirebbe fuori dal bootstrap.
  VERSION="$(php -r '
    $src = file_get_contents("config/openbook.php");
    if (!is_string($src) || !preg_match("/\\\$version\s*=\s*'\''([^'\'']+)'\''/", $src, $m)) {
      fwrite(STDERR, "Impossibile leggere \$version da config/openbook.php\n");
      exit(1);
    }
    echo $m[1];
  ')"
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+(\.rc[0-9]+)?$ ]] && [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then
  echo "Versione non valida: $VERSION" >&2
  exit 1
fi

DIST_DIR="$ROOT/dist"
STAGE="$DIST_DIR/openbook-$VERSION"
ZIP="$DIST_DIR/openbook-$VERSION.zip"

echo "==> Building Openbook $VERSION shared-hosting release"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

echo "==> composer install --no-dev"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Copying tree"
rsync -a \
  --exclude '.git/' \
  --exclude '.cursor/' \
  --exclude 'node_modules/' \
  --exclude 'dist/' \
  --exclude 'tests/' \
  --exclude 'storage/installed.lock' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  --exclude 'storage/pail/' \
  --exclude 'storage/app/public/**' \
  --exclude 'bootstrap/cache/*.php' \
  --exclude '.env' \
  --exclude '.env.backup' \
  --exclude '.env.production' \
  --exclude 'phpunit.xml' \
  --exclude 'phpunit.xml.dist' \
  --exclude '.phpunit.result.cache' \
  --exclude '.phpunit.cache/' \
  "$ROOT/" "$STAGE/"

# Scaffold storage vuoto ma presente
mkdir -p \
  "$STAGE/storage/app/public" \
  "$STAGE/storage/framework/cache" \
  "$STAGE/storage/framework/sessions" \
  "$STAGE/storage/framework/views" \
  "$STAGE/storage/logs" \
  "$STAGE/bootstrap/cache"
touch \
  "$STAGE/storage/app/.gitignore" \
  "$STAGE/storage/logs/.gitignore" \
  "$STAGE/bootstrap/cache/.gitignore"

# .htaccess root pronto all'uso (layout public_html piatto)
cp "$ROOT/distribution/htaccess.root" "$STAGE/.htaccess"

# Non includere il bootstrap setup nello zip applicativo
rm -f "$STAGE/setup-openbook.php"

echo "==> Creating zip"
(
  cd "$DIST_DIR"
  zip -qr "openbook-$VERSION.zip" "openbook-$VERSION"
)

SHA="$(shasum -a 256 "$ZIP" | awk '{print $1}')"
SIZE="$(wc -c < "$ZIP" | tr -d ' ')"
CHANGELOG_MD="$DIST_DIR/openbook-$VERSION-changelog.md"

echo "==> Extracting release notes from CHANGELOG.md"
php -r '
  $version = $argv[1];
  $src = file_get_contents("CHANGELOG.md");
  if (!is_string($src)) {
    fwrite(STDERR, "CHANGELOG.md non leggibile\n");
    exit(1);
  }
  $pattern = "/^## \\[" . preg_quote($version, "/") . "\\][^\\n]*\\n(?:.*?)(?=^## \\[|\\z)/ms";
  if (!preg_match($pattern, $src, $m)) {
    fwrite(STDERR, "Sezione ## [$version] non trovata in CHANGELOG.md\n");
    exit(1);
  }
  $notes = trim($m[0]) . "\n";
  if (file_put_contents($argv[2], $notes) === false) {
    fwrite(STDERR, "Impossibile scrivere le note di rilascio\n");
    exit(1);
  }
' "$VERSION" "$CHANGELOG_MD"

cat > "$DIST_DIR/latest.json" <<EOF
{
  "schema_version": 1,
  "version": "$VERSION",
  "min_php": "8.2.0",
  "released_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "download_url": "https://about.openb.app/releases/openbook-$VERSION.zip",
  "sha256": "$SHA",
  "changelog_url": "https://about.openb.app/releases/openbook-$VERSION-changelog.md",
  "requires_migration": true,
  "notes": "Pacchetto shared hosting (include vendor/)."
}
EOF

echo
echo "OK: $ZIP ($SIZE bytes)"
echo "SHA256: $SHA"
echo "Manifest: $DIST_DIR/latest.json"
echo "Changelog: $CHANGELOG_MD"
echo
echo "Pubblica su about.openb.app:"
echo "  /releases/openbook-$VERSION.zip"
echo "  /releases/openbook-$VERSION-changelog.md"
echo "  /releases/latest.json"
echo "  /setup-openbook.php  (copia dalla root del repo)"
