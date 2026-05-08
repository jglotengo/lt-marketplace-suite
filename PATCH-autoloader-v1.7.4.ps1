# PATCH-autoloader-v1.7.4.ps1
# Agrega 14 excepciones faltantes al autoloader SPL de lt-marketplace-suite.php
# Ejecutar desde: C:\Users\User\LTMS\lt-marketplace-suite

$plugin_file = "lt-marketplace-suite.php"

if (-not (Test-Path $plugin_file)) {
    Write-Host "ERROR: No se encontro $plugin_file en esta carpeta." -ForegroundColor Red
    Write-Host "Asegurate de ejecutar desde C:\Users\User\LTMS\lt-marketplace-suite" -ForegroundColor Yellow
    exit 1
}

# Backup
Copy-Item $plugin_file "$plugin_file.bak"
Write-Host "Backup creado: $plugin_file.bak" -ForegroundColor DarkGray

$content = Get-Content $plugin_file -Raw -Encoding UTF8

# Texto a buscar (el bloque que ya existe en el archivo)
$old_text = "                // API webhooks -- subdir 'stripe'/'uber' no existe; archivo en api/webhooks/
                'ltms-stripe-webhook-handler'       => 'api/webhooks/class-ltms-stripe-webhook-handler.php',
                'ltms-uber-direct-webhook-handler'  => 'api/webhooks/class-ltms-uber-direct-webhook-handler.php',
                // Frontend -- subdir derivado ('dashboard', 'public') no coincide con 'frontend/'
                'ltms-dashboard-logic'          => 'frontend/class-ltms-dashboard-logic.php',
                'ltms-public-auth-handler'      => 'frontend/class-ltms-public-auth-handler.php',"

# Verificar que el texto existe en el archivo
if ($content -notmatch [regex]::Escape("'ltms-stripe-webhook-handler'")) {
    Write-Host "ERROR: No se encontro el bloque de excepciones en el archivo." -ForegroundColor Red
    Write-Host "Es posible que el archivo ya fue parcheado o tiene una version diferente." -ForegroundColor Yellow
    exit 1
}

# Texto de reemplazo (agrega las 14 excepciones nuevas)
$new_text = "                // API webhooks -- subdir 'stripe'/'uber' no existe; archivo en api/webhooks/
                'ltms-stripe-webhook-handler'       => 'api/webhooks/class-ltms-stripe-webhook-handler.php',
                'ltms-uber-direct-webhook-handler'  => 'api/webhooks/class-ltms-uber-direct-webhook-handler.php',
                // API webhooks v1.7.4 -- handlers individuales (subdir = proveedor, no existe como dir)
                'ltms-addi-webhook-handler'         => 'api/webhooks/class-ltms-addi-webhook-handler.php',
                'ltms-openpay-webhook-handler'      => 'api/webhooks/class-ltms-openpay-webhook-handler.php',
                'ltms-siigo-webhook-handler'        => 'api/webhooks/class-ltms-siigo-webhook-handler.php',
                'ltms-aveonline-webhook-handler'    => 'api/webhooks/class-ltms-aveonline-webhook-handler.php',
                'ltms-zapsign-webhook-handler'      => 'api/webhooks/class-ltms-zapsign-webhook-handler.php',
                // API gateways v1.7.4 -- autoloader busca en api/, archivo en api/gateways/
                'ltms-api-gateway-openpay'          => 'api/gateways/class-ltms-api-gateway-openpay.php',
                'ltms-api-gateway-addi'             => 'api/gateways/class-ltms-api-gateway-addi.php',
                // Business listeners v1.7.4 -- subdir = proveedor, archivo en business/listeners/
                'ltms-tptc-listener'                => 'business/listeners/class-ltms-tptc-listener.php',
                'ltms-coupon-attribution-listener'  => 'business/listeners/class-ltms-coupon-attribution-listener.php',
                // Frontend v1.7.4 -- subdir 'kitchen'/'vendor'/'secure' no existen como dirs
                'ltms-kitchen-ajax'                 => 'frontend/class-ltms-kitchen-ajax.php',
                'ltms-vendor-settings-saver'        => 'frontend/class-ltms-vendor-settings-saver.php',
                'ltms-secure-downloads'             => 'frontend/class-ltms-secure-downloads.php',
                // Admin v1.7.4 -- subdir 'bank'/'legal' no existen como dirs
                'ltms-bank-reconciler'              => 'admin/class-ltms-bank-reconciler.php',
                'ltms-legal-evidence-handler'       => 'admin/class-ltms-legal-evidence-handler.php',
                // Frontend -- subdir derivado ('dashboard', 'public') no coincide con 'frontend/'
                'ltms-dashboard-logic'          => 'frontend/class-ltms-dashboard-logic.php',
                'ltms-public-auth-handler'      => 'frontend/class-ltms-public-auth-handler.php',"

$new_content = $content.Replace($old_text, $new_text)

if ($new_content -eq $content) {
    Write-Host ""
    Write-Host "AVISO: El reemplazo exacto no funciono (diferencia de saltos de linea)." -ForegroundColor Yellow
    Write-Host "Intentando metodo alternativo..." -ForegroundColor Yellow

    # Metodo alternativo: insertar despues de la linea de uber-direct
    $lines = Get-Content $plugin_file -Encoding UTF8
    $new_lines = @()
    $inserted = $false

    foreach ($line in $lines) {
        $new_lines += $line
        if ($line -match "ltms-uber-direct-webhook-handler" -and -not $inserted) {
            $new_lines += "                // API webhooks v1.7.4 -- handlers individuales"
            $new_lines += "                'ltms-addi-webhook-handler'         => 'api/webhooks/class-ltms-addi-webhook-handler.php',"
            $new_lines += "                'ltms-openpay-webhook-handler'      => 'api/webhooks/class-ltms-openpay-webhook-handler.php',"
            $new_lines += "                'ltms-siigo-webhook-handler'        => 'api/webhooks/class-ltms-siigo-webhook-handler.php',"
            $new_lines += "                'ltms-aveonline-webhook-handler'    => 'api/webhooks/class-ltms-aveonline-webhook-handler.php',"
            $new_lines += "                'ltms-zapsign-webhook-handler'      => 'api/webhooks/class-ltms-zapsign-webhook-handler.php',"
            $new_lines += "                // API gateways v1.7.4"
            $new_lines += "                'ltms-api-gateway-openpay'          => 'api/gateways/class-ltms-api-gateway-openpay.php',"
            $new_lines += "                'ltms-api-gateway-addi'             => 'api/gateways/class-ltms-api-gateway-addi.php',"
            $new_lines += "                // Business listeners v1.7.4"
            $new_lines += "                'ltms-tptc-listener'                => 'business/listeners/class-ltms-tptc-listener.php',"
            $new_lines += "                'ltms-coupon-attribution-listener'  => 'business/listeners/class-ltms-coupon-attribution-listener.php',"
            $new_lines += "                // Frontend v1.7.4"
            $new_lines += "                'ltms-kitchen-ajax'                 => 'frontend/class-ltms-kitchen-ajax.php',"
            $new_lines += "                'ltms-vendor-settings-saver'        => 'frontend/class-ltms-vendor-settings-saver.php',"
            $new_lines += "                'ltms-secure-downloads'             => 'frontend/class-ltms-secure-downloads.php',"
            $new_lines += "                // Admin v1.7.4"
            $new_lines += "                'ltms-bank-reconciler'              => 'admin/class-ltms-bank-reconciler.php',"
            $new_lines += "                'ltms-legal-evidence-handler'       => 'admin/class-ltms-legal-evidence-handler.php',"
            $inserted = $true
        }
    }

    if ($inserted) {
        $new_lines | Set-Content $plugin_file -Encoding UTF8
        Write-Host "Patch aplicado con metodo alternativo." -ForegroundColor Green
    } else {
        Write-Host "ERROR: No se encontro la linea de uber-direct-webhook-handler." -ForegroundColor Red
        exit 1
    }
} else {
    $new_content | Set-Content $plugin_file -Encoding UTF8
    Write-Host "Patch aplicado correctamente." -ForegroundColor Green
}

# Verificar que las nuevas entradas quedaron en el archivo
$verify = Get-Content $plugin_file -Raw
$checks = @(
    "ltms-addi-webhook-handler",
    "ltms-kitchen-ajax",
    "ltms-bank-reconciler",
    "ltms-api-gateway-openpay",
    "ltms-tptc-listener"
)

Write-Host ""
Write-Host "=== Verificacion del patch ===" -ForegroundColor Cyan
$all_ok = $true
foreach ($check in $checks) {
    if ($verify -match [regex]::Escape($check)) {
        Write-Host "  OK  $check" -ForegroundColor Green
    } else {
        Write-Host "  FALTA  $check" -ForegroundColor Red
        $all_ok = $false
    }
}

if ($all_ok) {
    Write-Host ""
    Write-Host "Patch completo. Ahora ejecuta:" -ForegroundColor Cyan
    Write-Host "  git add lt-marketplace-suite.php" -ForegroundColor White
    Write-Host "  git commit -m `"fix: agregar 14 excepciones autoloader para clases v1.7.4`"" -ForegroundColor White
    Write-Host "  git push origin main" -ForegroundColor White
} else {
    Write-Host ""
    Write-Host "Algunas entradas no quedaron. Revisa el archivo manualmente." -ForegroundColor Red
}
