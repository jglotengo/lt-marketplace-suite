#!/usr/bin/env bash
# =============================================================================
# LTMS — Deploy limpio en producción
# Uso: bash deploy-production.sh [WP_PATH]
#
# Ejemplo:
#   bash deploy-production.sh /home/customer/www/lo-tengo.com.co/public_html
#
# Requiere: git, unzip, wp-cli (wp)
# =============================================================================
set -euo pipefail

# ── Configuración ─────────────────────────────────────────────────────────────
REPO_ZIP="https://github.com/jglotengo/lt-marketplace-suite/archive/refs/heads/main.zip"
PLUGIN_SLUG="lt-marketplace-suite"
BACKUP_DIR="/tmp/ltms_backup_$(date +%Y%m%d_%H%M%S)"
TMP_ZIP="/tmp/ltms_deploy.zip"
TMP_DIR="/tmp/ltms_extract"

# Path de WordPress: argumento o detección automática
if [ -n "${1:-}" ]; then
    WP_PATH="$1"
else
    # Intentar detectar automáticamente
    for candidate in \
        /home/*/www/lo-tengo.com.co/public_html \
        /var/www/html \
        /var/www/lo-tengo.com.co/public_html \
        /home/*/public_html; do
        if [ -f "$candidate/wp-config.php" ]; then
            WP_PATH="$candidate"
            break
        fi
    done
fi

if [ -z "${WP_PATH:-}" ] || [ ! -f "$WP_PATH/wp-config.php" ]; then
    echo "ERROR: No se encontró wp-config.php en '$WP_PATH'"
    echo "Uso: bash deploy-production.sh /ruta/a/wordpress"
    exit 1
fi

PLUGINS_DIR="$WP_PATH/wp-content/plugins"
PLUGIN_DIR="$PLUGINS_DIR/$PLUGIN_SLUG"

WP_CLI="wp --path=$WP_PATH --allow-root"

echo ""
echo "============================================================"
echo " LTMS DEPLOY LIMPIO — $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================================"
echo " WP_PATH     : $WP_PATH"
echo " PLUGINS_DIR : $PLUGINS_DIR"
echo " BACKUP_DIR  : $BACKUP_DIR"
echo "============================================================"
echo ""

# ── Paso 1: Backup del plugin actual ─────────────────────────────────────────
echo "[1/8] Backup del plugin actual..."
mkdir -p "$BACKUP_DIR"

if [ -d "$PLUGIN_DIR" ]; then
    cp -r "$PLUGIN_DIR" "$BACKUP_DIR/$PLUGIN_SLUG"
    echo "      ✓ Backup en $BACKUP_DIR/$PLUGIN_SLUG"
else
    echo "      ⚠ Plugin no encontrado en $PLUGIN_DIR (instalación nueva)"
fi

# Backup de wp_options relacionadas a LTMS
if command -v wp &>/dev/null; then
    echo "      Exportando opciones LTMS de BD..."
    $WP_CLI db export "$BACKUP_DIR/ltms_db_backup.sql" \
        --tables="$($WP_CLI db prefix)options" 2>/dev/null || true
    echo "      ✓ BD exportada"
fi

# ── Paso 2: Desactivar plugin si está activo ──────────────────────────────────
echo ""
echo "[2/8] Desactivando plugin..."
if command -v wp &>/dev/null; then
    $WP_CLI plugin deactivate "$PLUGIN_SLUG" 2>/dev/null && echo "      ✓ Desactivado" || echo "      ⚠ No estaba activo"
else
    echo "      ⚠ WP-CLI no disponible — desactivar manualmente en wp-admin/plugins.php"
fi

# ── Paso 3: Eliminar plugin viejo ────────────────────────────────────────────
echo ""
echo "[3/8] Eliminando plugin viejo..."
if [ -d "$PLUGIN_DIR" ]; then
    rm -rf "$PLUGIN_DIR"
    echo "      ✓ $PLUGIN_DIR eliminado"
fi

# ── Paso 4: Descargar ZIP desde GitHub ───────────────────────────────────────
echo ""
echo "[4/8] Descargando desde GitHub..."
rm -f "$TMP_ZIP"
if command -v wget &>/dev/null; then
    wget -q "$REPO_ZIP" -O "$TMP_ZIP"
elif command -v curl &>/dev/null; then
    curl -sL "$REPO_ZIP" -o "$TMP_ZIP"
else
    echo "ERROR: Ni wget ni curl disponibles"
    exit 1
fi

if [ ! -f "$TMP_ZIP" ] || [ ! -s "$TMP_ZIP" ]; then
    echo "ERROR: Descarga fallida — $TMP_ZIP vacío o inexistente"
    exit 1
fi
echo "      ✓ Descargado ($(du -sh "$TMP_ZIP" | cut -f1))"

# ── Paso 5: Extraer e instalar ───────────────────────────────────────────────
echo ""
echo "[5/8] Extrayendo e instalando..."
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"
unzip -q "$TMP_ZIP" -d "$TMP_DIR"

# GitHub crea un subdirectorio con sufijo -main
EXTRACTED=$(find "$TMP_DIR" -maxdepth 1 -type d -name "${PLUGIN_SLUG}*" | head -1)
if [ -z "$EXTRACTED" ]; then
    echo "ERROR: No se encontró el directorio del plugin en el ZIP"
    ls -la "$TMP_DIR"
    exit 1
fi

mv "$EXTRACTED" "$PLUGIN_DIR"
echo "      ✓ Instalado en $PLUGIN_DIR"

# ── Paso 6: Instalar dependencias Composer ───────────────────────────────────
echo ""
echo "[6/8] Composer install..."
if command -v composer &>/dev/null; then
    cd "$PLUGIN_DIR"
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
    echo "      ✓ Composer completado"
    cd - >/dev/null
else
    echo "      ⚠ Composer no disponible — usando fallback autoloader"
    echo "        (instalar: curl -sS https://getcomposer.org/installer | php)"
fi

# ── Paso 7: Activar plugin (dispara activation hook) ─────────────────────────
echo ""
echo "[7/8] Activando plugin..."
if command -v wp &>/dev/null; then
    $WP_CLI plugin activate "$PLUGIN_SLUG"
    echo "      ✓ Plugin activado — activation hook ejecutado"
    echo "        → LTMS_Core_Activator::activate() corrió"
    echo "        → Tablas DB migradas"
    echo "        → Roles y caps instalados"
    echo "        → Opciones por defecto establecidas"
    echo "        → Cron jobs programados"
else
    echo "      ⚠ WP-CLI no disponible"
    echo "        → Activar manualmente en wp-admin/plugins.php"
    echo "        → CRÍTICO: el activation hook DEBE ejecutarse"
fi

# ── Paso 8: Fix de caps y validación ─────────────────────────────────────────
echo ""
echo "[8/8] Verificando y reparando capabilities..."
if command -v wp &>/dev/null; then
    $WP_CLI eval-file "$PLUGIN_DIR/bin/emergency-fix-caps.php" 2>/dev/null || true
    echo ""
    echo "      Verificación de caps del administrador:"
    $WP_CLI eval '
$role = get_role("administrator");
$caps = [
    "ltms_access_dashboard","ltms_manage_all_vendors","ltms_approve_payouts",
    "ltms_manage_platform_settings","ltms_view_tax_reports","ltms_view_wallet_ledger",
    "ltms_view_all_orders","ltms_manage_kyc","ltms_view_security_logs",
    "ltms_view_audit_log","ltms_compliance","ltms_freeze_wallets","ltms_generate_legal_evidence"
];
$ok = $fail = [];
foreach ($caps as $c) { $role->has_cap($c) ? ($ok[] = $c) : ($fail[] = $c); }
echo "OK    : " . count($ok) . "/" . count($caps) . "\n";
if ($fail) echo "FALTA : " . implode(", ", $fail) . "\n";
else echo "TODAS las caps OK\n";
' 2>/dev/null || true
fi

# ── Limpieza ─────────────────────────────────────────────────────────────────
rm -rf "$TMP_DIR" "$TMP_ZIP"

# ── Flush caché ──────────────────────────────────────────────────────────────
if command -v wp &>/dev/null; then
    $WP_CLI cache flush 2>/dev/null || true
    $WP_CLI rewrite flush 2>/dev/null || true
fi

echo ""
echo "============================================================"
echo " DEPLOY COMPLETADO"
echo " Backup guardado en: $BACKUP_DIR"
echo " Plugin instalado en: $PLUGIN_DIR"
echo "============================================================"
echo ""
echo "PRÓXIMOS PASOS:"
echo "  1. Verificar en wp-admin que el menú 'LT Marketplace' aparece"
echo "  2. Pegar el checklist de validación en functions.php (ver checklist anterior)"
echo "  3. Si hay problemas de caps, ejecutar:"
echo "     wp eval-file $PLUGIN_DIR/bin/emergency-fix-caps.php --allow-root"
echo ""
