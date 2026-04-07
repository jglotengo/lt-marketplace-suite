#!/usr/bin/env bash
# Instala el entorno de test de WordPress.
# Uso: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]

set -e

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

install_wp() {
    if [ -d "$WP_CORE_DIR/src" ]; then
        return
    fi
    mkdir -p "$WP_CORE_DIR"
    if [[ "$WP_VERSION" == 'latest' ]]; then
        local ARCHIVE_NAME='latest'
    else
        local ARCHIVE_NAME="wordpress-${WP_VERSION}"
    fi
    curl -s https://wordpress.org/${ARCHIVE_NAME}.tar.gz | tar --strip-components=1 -zxmf - -C "$WP_CORE_DIR"
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR" ]; then
        return
    fi
    mkdir -p "$WP_TESTS_DIR"
    svn co --quiet --ignore-externals https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
    svn co --quiet --ignore-externals https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "$WP_TESTS_DIR/data"

    curl -s https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php > "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|dirname( __FILE__ ) . '/src/'|'$WP_CORE_DIR/src/'|" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
}

create_db() {
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" "--host=${DB_HOST%%:*}" "--port=${DB_HOST##*:}" 2>/dev/null || true
}

install_wp
install_test_suite
create_db
echo "WP test environment ready."
