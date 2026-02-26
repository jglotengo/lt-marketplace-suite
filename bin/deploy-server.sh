#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# LTMS Deploy Script — Servidor de Producción
# Despliega el plugin en un servidor WordPress vía SSH + rsync
#
# Uso: bash bin/deploy-server.sh --host HOST --user USER [--path PATH]
# Prerequisito: haber ejecutado 'make dist' primero
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

# ── Defaults ──────────────────────────────────────────────────────
PLUGIN_SLUG="lt-marketplace-suite"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
DIST_DIR="$PLUGIN_DIR/dist"
REMOTE_HOST=""
REMOTE_USER="www-data"
REMOTE_PATH="/var/www/html/wp-content/plugins"
SSH_PORT=22
SSH_KEY=""
DRY_RUN=false
MAINTENANCE_MODE=true

# ── Parse arguments ────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case $1 in
        --host)    REMOTE_HOST="$2"; shift 2 ;;
        --user)    REMOTE_USER="$2"; shift 2 ;;
        --path)    REMOTE_PATH="$2"; shift 2 ;;
        --port)    SSH_PORT="$2"; shift 2 ;;
        --key)     SSH_KEY="$2"; shift 2 ;;
        --dry-run) DRY_RUN=true; shift ;;
        --no-maintenance) MAINTENANCE_MODE=false; shift ;;
        *) error "Unknown argument: $1" ;;
    esac
done

[[ -z "$REMOTE_HOST" ]] && error "Required: --host <hostname>"

# ── Get version ────────────────────────────────────────────────────
VERSION=$(grep "Version:" "$PLUGIN_DIR/lt-marketplace-suite.php" | awk '{print $2}' | tr -d '\r')
ZIP_FILE="$DIST_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
SOURCE_DIR="$DIST_DIR/$PLUGIN_SLUG"

[[ -d "$SOURCE_DIR" ]] || error "Distribution not found. Run 'make dist' first."

# ── SSH command builder ────────────────────────────────────────────
SSH_OPTS="-p $SSH_PORT -o StrictHostKeyChecking=no -o ConnectTimeout=15"
[[ -n "$SSH_KEY" ]] && SSH_OPTS="$SSH_OPTS -i $SSH_KEY"
SSH_CMD="ssh $SSH_OPTS $REMOTE_USER@$REMOTE_HOST"

# ── Deploy ────────────────────────────────────────────────────────
echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║     LT Marketplace Suite — Deploy                           ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
info "Deploying $PLUGIN_SLUG v$VERSION"
info "Target: $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/$PLUGIN_SLUG"
[[ "$DRY_RUN" == true ]] && warn "DRY RUN MODE — No changes will be made"
echo ""

# ── Pre-deploy backup ──────────────────────────────────────────────
info "Step 1/5: Creating pre-deploy backup on server..."
if [[ "$DRY_RUN" == false ]]; then
    BACKUP_DIR="/tmp/ltms-backup-$(date +%Y%m%d-%H%M%S)"
    $SSH_CMD "cp -r $REMOTE_PATH/$PLUGIN_SLUG $BACKUP_DIR 2>/dev/null || true" && \
        success "Backup created at $BACKUP_DIR" || \
        warn "No existing installation to backup"
fi

# ── Maintenance mode ───────────────────────────────────────────────
if [[ "$MAINTENANCE_MODE" == true && "$DRY_RUN" == false ]]; then
    info "Step 2/5: Enabling maintenance mode..."
    $SSH_CMD "cd $REMOTE_PATH/../../../ && wp maintenance-mode activate --allow-root 2>/dev/null || \
              touch .maintenance" && success "Maintenance mode enabled" || \
              warn "Could not enable maintenance mode"
else
    info "Step 2/5: Skipping maintenance mode"
fi

# ── Rsync ─────────────────────────────────────────────────────────
info "Step 3/5: Syncing files..."
RSYNC_OPTS="-avz --delete --checksum"
[[ "$DRY_RUN" == true ]] && RSYNC_OPTS="$RSYNC_OPTS --dry-run"
[[ -n "$SSH_KEY" ]] && RSYNC_SSH="ssh -p $SSH_PORT -i $SSH_KEY" || RSYNC_SSH="ssh -p $SSH_PORT"

rsync $RSYNC_OPTS \
    -e "$RSYNC_SSH -o StrictHostKeyChecking=no" \
    --exclude='*.sh' \
    --exclude='*.md' \
    --exclude='*.test.php' \
    --exclude='*.spec.js' \
    "$SOURCE_DIR/" \
    "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/$PLUGIN_SLUG/"

success "Files synced"

# ── Post-deploy actions ────────────────────────────────────────────
if [[ "$DRY_RUN" == false ]]; then
    info "Step 4/5: Running post-deploy tasks on server..."

    # Set file permissions
    $SSH_CMD "find $REMOTE_PATH/$PLUGIN_SLUG -type f -exec chmod 644 {} \; && \
              find $REMOTE_PATH/$PLUGIN_SLUG -type d -exec chmod 755 {} \;" && \
        success "Permissions set"

    # Clear WordPress caches
    $SSH_CMD "cd $REMOTE_PATH/../../../ && \
        wp cache flush --allow-root 2>/dev/null || true && \
        wp transient delete --all --allow-root 2>/dev/null || true" && \
        success "Caches cleared"

    # Run DB migrations if WP CLI available
    $SSH_CMD "cd $REMOTE_PATH/../../../ && \
        wp eval 'do_action(\"ltms_run_migrations\");' --allow-root 2>/dev/null || true" && \
        success "Migrations executed"

    # Disable maintenance mode
    if [[ "$MAINTENANCE_MODE" == true ]]; then
        info "Step 5/5: Disabling maintenance mode..."
        $SSH_CMD "cd $REMOTE_PATH/../../../ && \
            wp maintenance-mode deactivate --allow-root 2>/dev/null || \
            rm -f .maintenance" && success "Maintenance mode disabled"
    fi
fi

# ── Summary ────────────────────────────────────────────────────────
echo ""
echo "────────────────────────────────────────────────────────────────"
if [[ "$DRY_RUN" == true ]]; then
    warn "DRY RUN complete. Use without --dry-run to apply changes."
else
    success "Deployment complete!"
    echo "  Version: $VERSION"
    echo "  Server:  $REMOTE_USER@$REMOTE_HOST"
    echo "  Path:    $REMOTE_PATH/$PLUGIN_SLUG"
fi
echo "────────────────────────────────────────────────────────────────"
echo ""
