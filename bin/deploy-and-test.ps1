# ============================================================
# LTMS Deploy Script — Ejecutar en PowerShell como Administrador
# Actualiza el plugin en el servidor de staging y ejecuta tests
# ============================================================

$SSH_HOST = "ssh.lo-tengo.com.co"
$SSH_PORT = 18765
$SSH_USER = "u1549-ruo8hvwpk9dt"
# ROTAR-TOKEN-GITHUB FIX (2026-09-13): se elimina la contraseña SSH en texto
# plano. El despliegue usa la llave privada local (id_ed25519), que ya funciona
# contra ssh.lo-tengo.com.co sin password (verificado con -o BatchMode=yes).
$SSH_KEY  = "$env:USERPROFILE\.ssh\id_ed25519"
$WP_PATH  = "/home/customer/www/lo-tengo.com.co/public_html"
$PLUGIN_DIR = "$WP_PATH/wp-content/plugins/lt-marketplace-suite"

Write-Host "============================================================" -ForegroundColor Cyan
Write-Host " LTMS Deploy + Integration Tests" -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan

# Crear script de comandos para ejecutar via SSH
$COMMANDS = @"
echo '=== LTMS DEPLOY ===' && \
cd $PLUGIN_DIR && \
echo "Branch actual: \$(git branch --show-current)" && \
git fetch origin 2>&1 && \
git reset --hard origin/main 2>&1 && \
echo "Commit actual: \$(git log --oneline -1)" && \
echo '=== DEPLOY COMPLETO ===' && \
echo '' && \
echo '=== EJECUTANDO INTEGRATION TESTS ===' && \
wp --path=$WP_PATH eval-file $PLUGIN_DIR/bin/ltms-integration-tests.php --allow-root 2>&1
"@

# Usar plink si está disponible (PuTTY), sino intentar ssh nativo
$PLINK = Get-Command plink -ErrorAction SilentlyContinue
$SSH   = Get-Command ssh   -ErrorAction SilentlyContinue

if ($PLINK) {
    Write-Host "Usando plink (PuTTY)..." -ForegroundColor Yellow
    echo y | & plink -ssh -P $SSH_PORT -i $SSH_KEY "${SSH_USER}@${SSH_HOST}" $COMMANDS
} elseif ($SSH) {
    Write-Host "Usando ssh nativo..." -ForegroundColor Yellow
    # Auth por llave privada (sin contraseña en texto plano)
    & ssh -i $SSH_KEY -o BatchMode=yes -o StrictHostKeyChecking=no -p $SSH_PORT "${SSH_USER}@${SSH_HOST}" $COMMANDS
} else {
    Write-Host "ERROR: No se encontró ssh ni plink." -ForegroundColor Red
    Write-Host "Instala PuTTY o activa OpenSSH en Windows." -ForegroundColor Red
    Write-Host ""
    Write-Host "OPCION MANUAL — Pega estos comandos en el servidor SSH:" -ForegroundColor Yellow
    Write-Host "-----------------------------------------------------------"
    Write-Host "cd $PLUGIN_DIR" -ForegroundColor Green
    Write-Host "git fetch origin && git reset --hard origin/main" -ForegroundColor Green
    Write-Host "git log --oneline -1" -ForegroundColor Green
    Write-Host ""
    Write-Host "wp --path=$WP_PATH eval-file $PLUGIN_DIR/bin/ltms-integration-tests.php --allow-root 2>&1 | tee /tmp/ltms-qa-`$(date +%Y%m%d-%H%M).log" -ForegroundColor Green
    Write-Host "-----------------------------------------------------------"
}

Write-Host ""
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host " LISTO — Revisa los resultados arriba" -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan
