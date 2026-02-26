#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# LTMS Generate Secrets Script
# Genera claves criptográficas seguras para wp-config.php
#
# Uso: bash bin/generate-secrets.sh
# ─────────────────────────────────────────────────────────────────
set -euo pipefail

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║     LT Marketplace Suite — Generador de Secretos            ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
echo "Copia las siguientes líneas a tu wp-config.php:"
echo ""

# ── Master Encryption Key ──────────────────────────────────────────
MASTER_KEY=$(openssl rand -base64 48 | tr -d '\n/')
echo "// LT Marketplace Suite — Clave maestra AES-256"
echo "define( 'WP_LTMS_MASTER_KEY', '${MASTER_KEY}' );"
echo ""

# ── VAPID Keys for Web Push ────────────────────────────────────────
if command -v npx &>/dev/null; then
    echo "// Claves VAPID para Web Push Notifications"
    VAPID=$(npx web-push generate-vapid-keys --json 2>/dev/null || echo '{"publicKey":"GENERATE_MANUALLY","privateKey":"GENERATE_MANUALLY"}')
    VAPID_PUB=$(echo "$VAPID" | python3 -c "import sys,json; print(json.load(sys.stdin)['publicKey'])" 2>/dev/null || echo "GENERATE_MANUALLY")
    VAPID_PRIV=$(echo "$VAPID" | python3 -c "import sys,json; print(json.load(sys.stdin)['privateKey'])" 2>/dev/null || echo "GENERATE_MANUALLY")
    echo "define( 'WP_LTMS_VAPID_PUBLIC_KEY',  '${VAPID_PUB}' );"
    echo "define( 'WP_LTMS_VAPID_PRIVATE_KEY', '${VAPID_PRIV}' );"
    echo "define( 'WP_LTMS_VAPID_SUBJECT',     'mailto:admin@yoursite.com' );"
    echo ""
else
    echo "// Instala web-push para generar claves VAPID: npm install -g web-push"
    echo "// Luego ejecuta: web-push generate-vapid-keys"
    echo ""
fi

# ── Random API Salt ────────────────────────────────────────────────
API_SALT=$(openssl rand -hex 32)
echo "// Salt para endpoints de API interno"
echo "define( 'WP_LTMS_API_SALT', '${API_SALT}' );"
echo ""

echo "────────────────────────────────────────────────────────────────"
echo "⚠️  IMPORTANTE:"
echo "   - Nunca compartas estas claves"
echo "   - Nunca las subas a Git"
echo "   - Guarda una copia segura fuera del servidor"
echo "   - Cambiar WP_LTMS_MASTER_KEY invalida todos los datos cifrados"
echo "────────────────────────────────────────────────────────────────"
echo ""
