#!/usr/bin/env bash
# ==============================================================================
# install-wp-tests.sh — Instala WordPress Test Suite para PHPUnit
#
# USO:
#   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# EJEMPLOS:
#   bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
#   bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 6.3
#   bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
#
# VARIABLES DE ENTORNO (sobrescriben argumentos):
#   WP_TESTS_DIR  — dónde instalar la test suite (default: /tmp/wordpress-tests-lib)
#   WP_CORE_DIR   — dónde instalar WordPress core (default: /tmp/wordpress)
#
# REQUISITOS:
#   - SVN (subversion): sudo apt-get install subversion
#   - MySQL/MariaDB corriendo y accesible
#
# ==============================================================================

set -e  # Salir inmediatamente si un comando falla
set -u  # Tratar variables no definidas como error

# ─── Argumentos ─────────────────────────────────────────────────────────────
DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-root}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-latest}"

# ─── Directorios ────────────────────────────────────────────────────────────
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

# ─── Colores para output ─────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info()    { echo -e "${BLUE}[INFO]${NC} $*"; }
log_success() { echo -e "${GREEN}[OK]${NC} $*"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $*" >&2; }

# ─── Verificar SVN ───────────────────────────────────────────────────────────
if ! command -v svn &>/dev/null; then
    log_error "SVN no encontrado. Instala con: sudo apt-get install subversion"
    exit 1
fi

# ─── Resolver versión de WordPress ──────────────────────────────────────────
install_wp() {
    log_info "Instalando WordPress core en ${WP_CORE_DIR}..."

    if [[ -d "${WP_CORE_DIR}/wp-includes" ]]; then
        log_info "WordPress ya instalado en ${WP_CORE_DIR} — omitiendo."
        return
    fi

    mkdir -p "${WP_CORE_DIR}"

    if [[ "${WP_VERSION}" == 'latest' ]]; then
        local ARCHIVE_NAME='wordpress-latest'
    elif [[ "${WP_VERSION}" == 'trunk' ]]; then
        local ARCHIVE_NAME='wordpress-trunk'
    else
        local ARCHIVE_NAME="wordpress-${WP_VERSION}"
    fi

    if [[ "${WP_VERSION}" == 'trunk' ]]; then
        svn export --quiet "https://develop.svn.wordpress.org/trunk/src/" "${WP_CORE_DIR}/src"
        svn export --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/" "${WP_CORE_DIR}/tests/phpunit/includes"
    else
        # Descargar desde WordPress.org
        local WP_URL
        if [[ "${WP_VERSION}" == 'latest' ]]; then
            WP_URL='https://wordpress.org/latest.tar.gz'
        else
            WP_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
        fi

        log_info "Descargando ${WP_URL}..."
        curl -sL "${WP_URL}" -o /tmp/wp.tar.gz
        tar -xzf /tmp/wp.tar.gz --strip-components=1 -C "${WP_CORE_DIR}"
        rm /tmp/wp.tar.gz
    fi

    log_success "WordPress core instalado en ${WP_CORE_DIR}"
}

# ─── Instalar WP Test Suite ──────────────────────────────────────────────────
install_test_suite() {
    log_info "Instalando WP Test Suite en ${WP_TESTS_DIR}..."

    if [[ -d "${WP_TESTS_DIR}/includes" ]]; then
        log_info "WP Test Suite ya instalada en ${WP_TESTS_DIR} — omitiendo."
        return
    fi

    mkdir -p "${WP_TESTS_DIR}"

    local SVN_URL
    if [[ "${WP_VERSION}" == 'latest' ]]; then
        # Obtener la versión exacta desde la API de WordPress
        local WP_VERSION_EXACT
        WP_VERSION_EXACT=$(curl -s "https://api.wordpress.org/core/version-check/1.7/" \
            | grep -oP '"version":"\K[^"]+' | head -1)

        if [[ -z "${WP_VERSION_EXACT}" ]]; then
            log_warn "No se pudo obtener versión exacta de WP — usando trunk"
            SVN_URL='https://develop.svn.wordpress.org/trunk'
        else
            # Usar el tag de la versión mayor.menor (ej: 6.4 de 6.4.3)
            local WP_MAJOR_MINOR
            WP_MAJOR_MINOR=$(echo "${WP_VERSION_EXACT}" | cut -d. -f1,2)
            SVN_URL="https://develop.svn.wordpress.org/tags/${WP_VERSION_EXACT}"
            log_info "Versión WP detectada: ${WP_VERSION_EXACT} (tag: ${WP_MAJOR_MINOR})"
        fi
    elif [[ "${WP_VERSION}" == 'trunk' ]]; then
        SVN_URL='https://develop.svn.wordpress.org/trunk'
    else
        SVN_URL="https://develop.svn.wordpress.org/tags/${WP_VERSION}"
    fi

    log_info "SVN checkout desde: ${SVN_URL}"
    svn export --quiet "${SVN_URL}/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes/"
    svn export --quiet "${SVN_URL}/tests/phpunit/data/"     "${WP_TESTS_DIR}/data/" 2>/dev/null || true

    log_success "WP Test Suite instalada en ${WP_TESTS_DIR}"
}

# ─── Crear wp-tests-config.php ───────────────────────────────────────────────
install_wp_config() {
    log_info "Creando wp-tests-config.php..."

    # Extraer host y puerto si el host tiene formato host:puerto
    local DB_HOST_ONLY="${DB_HOST%%:*}"
    local DB_PORT=""
    if [[ "${DB_HOST}" == *":"* ]]; then
        DB_PORT="${DB_HOST##*:}"
    fi

    cat > "${WP_TESTS_DIR}/wp-tests-config.php" <<PHP
<?php
/* wp-tests-config.php — generado por install-wp-tests.sh */
define( 'ABSPATH',           '${WP_CORE_DIR}/' );
define( 'DB_NAME',           '${DB_NAME}' );
define( 'DB_USER',           '${DB_USER}' );
define( 'DB_PASSWORD',       '${DB_PASS}' );
define( 'DB_HOST',           '${DB_HOST}' );
define( 'DB_CHARSET',        'utf8mb4' );
define( 'DB_COLLATE',        '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN',   'example.org' );
define( 'WP_TESTS_EMAIL',    'admin@example.org' );
define( 'WP_TESTS_TITLE',    'LTMS Test Site' );
define( 'WP_PHP_BINARY',     'php' );
define( 'WPLANG',             '' );
define( 'WP_DEBUG',           true );
define( 'WP_DEBUG_LOG',       false );
define( 'SCRIPT_DEBUG',       false );
/* Keys de seguridad (dummy para tests) */
define( 'AUTH_KEY',         'ltms-test-auth-key' );
define( 'SECURE_AUTH_KEY',  'ltms-test-secure-auth-key' );
define( 'LOGGED_IN_KEY',    'ltms-test-logged-in-key' );
define( 'NONCE_KEY',        'ltms-test-nonce-key' );
define( 'AUTH_SALT',        'ltms-test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'ltms-test-secure-auth-salt' );
define( 'LOGGED_IN_SALT',   'ltms-test-logged-in-salt' );
define( 'NONCE_SALT',       'ltms-test-nonce-salt' );
PHP

    log_success "wp-tests-config.php creado en ${WP_TESTS_DIR}"
}

# ─── Crear base de datos de test ─────────────────────────────────────────────
create_db() {
    log_info "Creando base de datos '${DB_NAME}'..."

    # Separar host:puerto para mysql
    local MYSQL_HOST="${DB_HOST%%:*}"
    local MYSQL_PORT="3306"
    if [[ "${DB_HOST}" == *":"* ]]; then
        MYSQL_PORT="${DB_HOST##*:}"
    fi

    local MYSQL_CMD="mysql -u${DB_USER} -h${MYSQL_HOST} -P${MYSQL_PORT}"
    if [[ -n "${DB_PASS}" ]]; then
        MYSQL_CMD="${MYSQL_CMD} -p${DB_PASS}"
    fi

    # Verificar conexión
    if ! ${MYSQL_CMD} -e "SELECT 1;" &>/dev/null; then
        log_error "No se puede conectar a MySQL en ${DB_HOST} con usuario ${DB_USER}"
        exit 1
    fi

    # Crear DB si no existe
    ${MYSQL_CMD} -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` 
                     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null

    log_success "Base de datos '${DB_NAME}' lista"
}

# ─── Instalar functions.php de bootstrap si no existe ────────────────────────
install_bootstrap_functions() {
    local FUNCTIONS_FILE="${WP_TESTS_DIR}/includes/functions.php"

    if [[ ! -f "${FUNCTIONS_FILE}" ]]; then
        log_warn "functions.php no encontrado — creando stub mínimo"
        cat > "${FUNCTIONS_FILE}" <<'PHP'
<?php
/**
 * Stub mínimo de functions.php de WP Test Suite.
 * Reemplazar con el archivo real de SVN si es posible.
 */
function tests_add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
    add_filter( $hook, $callback, $priority, $args );
}
PHP
    fi
}

# ─── Main ────────────────────────────────────────────────────────────────────
echo ""
echo "========================================================"
echo "  LTMS — WordPress Test Suite Installer"
echo "========================================================"
echo "  DB Name:     ${DB_NAME}"
echo "  DB User:     ${DB_USER}"
echo "  DB Host:     ${DB_HOST}"
echo "  WP Version:  ${WP_VERSION}"
echo "  Tests Dir:   ${WP_TESTS_DIR}"
echo "  WP Core Dir: ${WP_CORE_DIR}"
echo "========================================================"
echo ""

create_db
install_wp
install_test_suite
install_wp_config
install_bootstrap_functions

echo ""
echo -e "${GREEN}✅ WP Test Suite instalada exitosamente.${NC}"
echo ""
echo "Para ejecutar los tests:"
echo "  vendor/bin/phpunit --testsuite=unit"
echo "  vendor/bin/phpunit --testsuite=integration"
echo "  vendor/bin/phpunit"
echo ""
