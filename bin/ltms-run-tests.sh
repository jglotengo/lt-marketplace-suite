#!/usr/bin/env bash
# bin/ltms-run-tests.sh — Ejecuta tests de integración + QA Alegra
# Compatible con nohup. Uso:
#   bash bin/ltms-run-tests.sh > /tmp/ltms-all.log 2>&1 &
#   tail -f /tmp/ltms-all.log

WP_PATH="/home/customer/www/lo-tengo.com.co/public_html"
PLUGIN_DIR="$WP_PATH/wp-content/plugins/lt-marketplace-suite"

# Asegurar que WP-CLI esté en el PATH (SiteGround lo instala aquí)
export PATH="$HOME/.local/bin:/usr/local/bin:$PATH"

echo "======================================"
echo "  LTMS Test Runner — $(date)"
echo "======================================"

cd "$PLUGIN_DIR" || { echo "ERROR: Plugin dir not found"; exit 1; }

echo "PHP: $(php -r 'echo PHP_VERSION;' 2>/dev/null)"
echo "WP-CLI: $(wp --version 2>/dev/null || echo 'not found')"
echo ""

echo "======================================"
echo "  Integration Tests"
echo "======================================"
php bin/ltms-integration-tests.php 2>&1
echo ""

echo "======================================"
echo "  QA Alegra"
echo "======================================"
php bin/ltms-qa-alegra.php 2>&1

echo ""
echo "======================================"
echo "  QA ZapSign"
echo "======================================"
php bin/ltms-qa-zapsign.php 2>&1

echo ""
echo "======================================"
echo "  DONE — $(date)"
echo "======================================"
