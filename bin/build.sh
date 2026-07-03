#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# LTMS Build Script
# Construye los assets de producción y genera el ZIP del plugin.
#
# Uso: bash bin/build.sh [--version X.Y.Z] [--skip-tests] [--skip-lint]
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

# ── Colors ────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()    { echo -e "${CYAN}[INFO]${NC} $*"; }
success() { echo -e "${GREEN}[OK]${NC} $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# ── Config ────────────────────────────────────────────────────────
PLUGIN_SLUG="lt-marketplace-suite"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
DIST_DIR="$PLUGIN_DIR/dist"
SKIP_TESTS=false
SKIP_LINT=false
VERSION=""

# ── Parse arguments ────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case $1 in
        --version)
            VERSION="$2"
            shift 2
            ;;
        --skip-tests)
            SKIP_TESTS=true
            shift
            ;;
        --skip-lint)
            SKIP_LINT=true
            shift
            ;;
        *)
            error "Unknown argument: $1"
            ;;
    esac
done

# ── Get version ────────────────────────────────────────────────────
if [[ -z "$VERSION" ]]; then
    VERSION=$(grep "Version:" "$PLUGIN_DIR/lt-marketplace-suite.php" | awk '{print $2}' | tr -d '\r')
fi

if [[ -z "$VERSION" ]]; then
    error "Could not determine plugin version. Use --version X.Y.Z"
fi

info "Building $PLUGIN_SLUG v$VERSION"

cd "$PLUGIN_DIR"

# ── Step 1: Install production dependencies ────────────────────────
info "Installing production Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction --quiet
success "Composer install complete"

# ── Step 2: Lint ───────────────────────────────────────────────────
if [[ "$SKIP_LINT" == false ]]; then
    info "Running PHP CodeSniffer..."
    if vendor/bin/phpcs --standard=WordPress --extensions=php \
        --ignore=vendor,node_modules,assets/js,tests \
        includes/ lt-marketplace-suite.php uninstall.php \
        --report=summary -q; then
        success "PHPCS: No errors"
    else
        error "PHPCS: Errors found. Fix them or use --skip-lint"
    fi
else
    warn "Skipping lint checks"
fi

# ── Step 3: Tests ──────────────────────────────────────────────────
if [[ "$SKIP_TESTS" == false ]]; then
    info "Running PHPUnit tests..."
    if [[ -d "/tmp/wordpress-tests-lib" ]]; then
        if vendor/bin/phpunit --bootstrap tests/test-bootstrap.php tests/Unit/ --colors=never --testdox; then
            success "Tests: All passed"
        else
            error "Tests failed. Fix them or use --skip-tests"
        fi
    else
        warn "WordPress test environment not found at /tmp/wordpress-tests-lib. Skipping tests."
        warn "Run 'make setup-tests' to configure the test environment."
    fi
else
    warn "Skipping tests"
fi

# ── Step 4: Rebuild Composer for production ────────────────────────
info "Optimizing autoloader for production..."
composer dump-autoload --no-dev --optimize --quiet

# ── Step 5: Build assets ───────────────────────────────────────────
info "Building CSS/JS assets..."

# Minify CSS if cleancss is available
if command -v cleancss &>/dev/null; then
    for f in assets/css/*.css; do
        [[ "$f" == *".min.css" ]] && continue
        cleancss -o "${f%.css}.min.css" "$f" 2>/dev/null && info "  Minified: $f"
    done
    success "CSS minification complete"
else
    warn "cleancss not found. Copying CSS files as-is."
    for f in assets/css/*.css; do
        [[ "$f" == *".min.css" ]] && continue
        cp "$f" "${f%.css}.min.css"
    done
fi

# Minify JS if terser is available
if command -v terser &>/dev/null; then
    for f in assets/js/*.js; do
        [[ "$f" == *".min.js" ]] && continue
        terser "$f" -o "${f%.js}.min.js" --compress --mangle 2>/dev/null && info "  Minified: $f"
    done
    success "JS minification complete"
else
    warn "terser not found. Copying JS files as-is."
    for f in assets/js/*.js; do
        [[ "$f" == *".min.js" ]] && continue
        cp "$f" "${f%.js}.min.js"
    done
fi

# ── Step 6: Generate .mo language files ───────────────────────────
info "Compiling .po → .mo language files..."
if command -v msgfmt &>/dev/null; then
    for po_file in languages/*.po; do
        mo_file="${po_file%.po}.mo"
        msgfmt -o "$mo_file" "$po_file" && info "  Compiled: $po_file"
    done
    success "Language files compiled"
else
    warn "msgfmt not found. Language .mo files may be missing."
fi

# ── Step 7: Create distribution package ───────────────────────────
info "Creating distribution package..."

mkdir -p "$DIST_DIR/$PLUGIN_SLUG"

rsync -av --quiet \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.vscode' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='docs' \
    --exclude='bin' \
    --exclude='reports' \
    --exclude='.gitignore' \
    --exclude='.gitattributes' \
    --exclude='.distignore' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='Makefile' \
    --exclude='phpcs.xml' \
    --exclude='phpunit.xml' \
    --exclude='*.spec.js' \
    --exclude='cypress.config.js' \
    --exclude='docker-compose*.yml' \
    --exclude='nginx.conf' \
    --exclude='php.ini' \
    --exclude='*.sh' \
    --exclude='*.md' \
    --exclude='dist' \
    --exclude='wp-config-sample-snippet.php' \
    . "$DIST_DIR/$PLUGIN_SLUG/"

# ── Step 8: Create ZIP ─────────────────────────────────────────────
info "Creating ZIP archive..."
cd "$DIST_DIR"
zip -r "${PLUGIN_SLUG}-${VERSION}.zip" "$PLUGIN_SLUG/" -q

success "Build complete!"
echo ""
echo -e "  ${GREEN}Package:${NC} $DIST_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
echo -e "  ${GREEN}Version:${NC} $VERSION"
echo ""
