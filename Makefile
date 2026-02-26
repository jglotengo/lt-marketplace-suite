# LT Marketplace Suite — Makefile
# Automatización de tareas de desarrollo, build y deploy
# Uso: make <target>
# Version: 1.5.0

PLUGIN_SLUG   = lt-marketplace-suite
PLUGIN_VERSION = $(shell grep "Version:" lt-marketplace-suite.php | awk '{print $$2}' | tr -d '\r')
BUILD_DIR     = /tmp/ltms-build
DIST_DIR      = ./dist
WP_TESTS_DIR  = /tmp/wordpress-tests-lib
WP_CORE_DIR   = /tmp/wordpress

.PHONY: help install build clean test lint phpcs phpstan i18n dist zip deploy dev-up dev-down db-reset

# ── Help ─────────────────────────────────────────────────────────

help: ## Muestra esta ayuda
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ── Dependencies ─────────────────────────────────────────────────

install: ## Instala dependencias PHP y Node
	composer install --no-interaction
	npm install

install-prod: ## Instala dependencias de producción únicamente
	composer install --no-dev --optimize-autoloader --no-interaction
	npm ci --production

# ── Code Quality ──────────────────────────────────────────────────

lint: phpcs phpstan ## Ejecuta todos los linters

phpcs: ## PHP CodeSniffer (WordPress coding standards)
	vendor/bin/phpcs --standard=WordPress --extensions=php \
		--ignore=vendor,node_modules,assets/js,tests/e2e \
		includes/ lt-marketplace-suite.php uninstall.php

phpcs-fix: ## Corrige automáticamente los problemas de estilo
	vendor/bin/phpcbf --standard=WordPress --extensions=php \
		--ignore=vendor,node_modules,assets/js,tests/e2e \
		includes/ lt-marketplace-suite.php uninstall.php

phpstan: ## Análisis estático con PHPStan (nivel 6)
	vendor/bin/phpstan analyse includes lt-marketplace-suite.php \
		--level=6 --memory-limit=512M

# ── Tests ─────────────────────────────────────────────────────────

test: ## Ejecuta las pruebas PHPUnit
	vendor/bin/phpunit \
		--bootstrap tests/test-bootstrap.php \
		tests/Unit/ \
		--colors=always

test-coverage: ## PHPUnit con reporte de cobertura HTML
	vendor/bin/phpunit \
		--bootstrap tests/test-bootstrap.php \
		tests/Unit/ \
		--coverage-html reports/coverage \
		--coverage-clover reports/clover.xml

test-e2e: ## Ejecuta pruebas E2E con Cypress (headless)
	npx cypress run \
		--spec "tests/e2e/*.spec.js" \
		--browser chrome \
		--headless

test-e2e-open: ## Abre Cypress UI para pruebas E2E interactivas
	npx cypress open

test-unit-watch: ## PHPUnit con file-watcher (requiere phpunit-watcher)
	vendor/bin/phpunit-watcher watch \
		--bootstrap tests/test-bootstrap.php \
		tests/Unit/

# ── Internationalization ───────────────────────────────────────────

i18n: ## Genera el archivo .pot y recompila .mo desde los .po
	wp i18n make-pot . languages/ltms.pot \
		--domain=ltms \
		--exclude=vendor,node_modules,tests,dist,docs
	for po in languages/*.po; do \
		msgfmt -o "$${po%.po}.mo" "$$po"; \
	done
	@echo "✅ i18n files updated"

# ── Build ─────────────────────────────────────────────────────────

build: ## Construye los assets JS/CSS de producción
	@echo "Building production assets..."
	@if [ -f package.json ] && grep -q '"build"' package.json; then \
		npm run build; \
	else \
		echo "Minifying CSS..."; \
		for f in assets/css/*.css; do \
			npx cleancss -o "$${f%.css}.min.css" "$$f" 2>/dev/null || cp "$$f" "$${f%.css}.min.css"; \
		done; \
		echo "Minifying JS..."; \
		for f in assets/js/*.js; do \
			npx terser "$$f" -o "$${f%.js}.min.js" --compress --mangle 2>/dev/null || cp "$$f" "$${f%.js}.min.js"; \
		done; \
	fi
	@echo "✅ Build complete"

# ── Distribution ──────────────────────────────────────────────────

dist: clean install-prod build i18n ## Crea el paquete de distribución
	@mkdir -p $(DIST_DIR)/$(PLUGIN_SLUG)
	@rsync -av --progress \
		--exclude='.git' \
		--exclude='.github' \
		--exclude='.vscode' \
		--exclude='node_modules' \
		--exclude='tests' \
		--exclude='docs' \
		--exclude='bin' \
		--exclude='reports' \
		--exclude='.gitignore' \
		--exclude='.distignore' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='package.json' \
		--exclude='package-lock.json' \
		--exclude='Makefile' \
		--exclude='phpcs.xml' \
		--exclude='phpunit.xml' \
		--exclude='*.spec.js' \
		--exclude='*.test.php' \
		--exclude='cypress.config.js' \
		--exclude='docker-compose*.yml' \
		--exclude='nginx.conf' \
		--exclude='php.ini' \
		. $(DIST_DIR)/$(PLUGIN_SLUG)/
	@echo "✅ Distribution package ready at $(DIST_DIR)/$(PLUGIN_SLUG)"

zip: dist ## Crea un ZIP del plugin listo para subir a WordPress
	@cd $(DIST_DIR) && zip -r $(PLUGIN_SLUG)-$(PLUGIN_VERSION).zip $(PLUGIN_SLUG)/
	@echo "✅ ZIP created: $(DIST_DIR)/$(PLUGIN_SLUG)-$(PLUGIN_VERSION).zip"

clean: ## Limpia archivos de build y caché
	@rm -rf $(DIST_DIR) $(BUILD_DIR) reports vendor/
	@find . -name "*.min.css" -not -path "*/node_modules/*" -delete
	@find . -name "*.min.js" -not -path "*/node_modules/*" -delete
	@echo "✅ Clean complete"

# ── Docker Dev Environment ────────────────────────────────────────

dev-up: ## Inicia el entorno de desarrollo con Docker
	docker-compose up -d
	@echo "⏳ Waiting for WordPress to be ready..."
	@sleep 10
	@docker-compose exec wordpress wp core is-installed --allow-root 2>/dev/null && \
		echo "✅ WordPress is ready at http://localhost:8080" || \
		echo "⚠️  WordPress may still be initializing. Check logs: make dev-logs"

dev-down: ## Detiene el entorno de desarrollo
	docker-compose down

dev-logs: ## Muestra los logs del entorno de desarrollo
	docker-compose logs -f

dev-shell: ## Abre shell en el contenedor de WordPress
	docker-compose exec wordpress bash

# ── Database ──────────────────────────────────────────────────────

db-reset: ## Reinstala WordPress (DESTRUCTIVE - pierde todos los datos)
	@echo "⚠️  WARNING: This will destroy all data!"
	@read -p "Type 'yes' to continue: " confirm && [ "$$confirm" = "yes" ]
	docker-compose exec wordpress wp core install \
		--url="http://localhost:8080" \
		--title="LTMS Dev" \
		--admin_user="admin" \
		--admin_password="admin123" \
		--admin_email="admin@ltms.test" \
		--allow-root

db-export: ## Exporta la base de datos de desarrollo
	@mkdir -p backups
	docker-compose exec db mysqldump -u root -proot wordpress > backups/db-$(shell date +%Y%m%d-%H%M%S).sql
	@echo "✅ Database exported to backups/"

# ── WP Test Suite ─────────────────────────────────────────────────

setup-tests: ## Configura el entorno de pruebas de WordPress
	@bash bin/install-wp-tests.sh wordpress_test root root localhost latest

# ── Security ──────────────────────────────────────────────────────

secrets: ## Genera secretos seguros para wp-config.php
	@bash bin/generate-secrets.sh

integrity: ## Verifica la integridad del plugin
	@php bin/master-integrity-check.php

# ── Versioning ────────────────────────────────────────────────────

version-bump: ## Incrementa la versión del plugin (PATCH)
	@current=$$(grep "Version:" lt-marketplace-suite.php | awk '{print $$2}' | tr -d '\r'); \
	new=$$(echo $$current | awk -F. '{print $$1"."$$2"."$$3+1}'); \
	sed -i "s/Version: $$current/Version: $$new/" lt-marketplace-suite.php; \
	sed -i "s/'LTMS_VERSION', '$$current'/'LTMS_VERSION', '$$new'/" lt-marketplace-suite.php; \
	echo "✅ Version bumped from $$current to $$new"
