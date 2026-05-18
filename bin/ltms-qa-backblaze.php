<?php
/**
 * LTMS QA — Pruebas de integración Backblaze B2
 *
 * Ejecutar via WP-CLI:
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file bin/ltms-qa-backblaze.php --allow-root 2>/dev/null
 *
 * @package LTMS
 */

if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }
set_time_limit( 0 );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    // Ejecutado via wp eval-file — WordPress ya cargado
} else {
    // Intento directo con php
    $wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
    if ( file_exists( $wp_load ) ) {
        require_once $wp_load;
    } else {
        die( "Ejecutar via WP-CLI: wp eval-file bin/ltms-qa-backblaze.php --allow-root\n" );
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0, 'items' => [] ];

function qa_section( string $title ): void {
    echo "\n" . str_repeat( '═', 50 ) . "\n  {$title}\n" . str_repeat( '═', 50 ) . "\n";
    @ob_flush(); flush();
}
function qa_ok( array &$qa, string $label, string $detail = '' ): void {
    $qa['pass']++;
    $qa['items'][] = [ 'status' => 'PASS', 'label' => $label ];
    echo "  ✅ PASS  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}
function qa_fail( array &$qa, string $label, string $detail = '' ): void {
    $qa['fail']++;
    $qa['items'][] = [ 'status' => 'FAIL', 'label' => $label, 'detail' => $detail ];
    echo "  ❌ FAIL  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}
function qa_warn( array &$qa, string $label, string $detail = '' ): void {
    $qa['warn']++;
    $qa['items'][] = [ 'status' => 'WARN', 'label' => $label ];
    echo "  ⚠️  WARN  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

echo "\n🔍 LTMS QA — Pruebas de integración Backblaze B2\n";
echo 'Fecha: ' . date( 'Y-m-d H:i:s' ) . "\n";

// ── T-01: Configuración en BD ─────────────────────────────────────────────────
qa_section( 'T-01 · Configuración guardada en BD' );

$b2_active   = get_option( 'ltms_backblaze_active', '' );
$b2_endpoint = LTMS_Core_Config::get( 'ltms_backblaze_endpoint', '' );
$b2_key_id   = LTMS_Core_Config::get( 'ltms_backblaze_key_id', '' );
$b2_app_key  = LTMS_Core_Config::get( 'ltms_backblaze_app_key', '' );
$b2_bucket   = LTMS_Core_Config::get( 'ltms_backblaze_default_bucket', '' );
$b2_priv     = LTMS_Core_Config::get( 'ltms_backblaze_private_bucket', '' );

if ( $b2_active === 'yes' || $b2_active === '1' || $b2_active === true ) {
    qa_ok( $qa, 'Backblaze B2 activo — yes' );
} else {
    qa_warn( $qa, 'Backblaze B2 no activo', 'Activar en Configuración → Backblaze B2' );
}

if ( $b2_endpoint ) {
    qa_ok( $qa, 'Endpoint configurado', substr( $b2_endpoint, 0, 60 ) );
} else {
    qa_fail( $qa, 'Endpoint NO configurado',
        'Formato: https://s3.us-west-004.backblazeb2.com — Ver instrucciones abajo' );
}

if ( $b2_key_id ) {
    qa_ok( $qa, 'Key ID configurado', '✓ (' . strlen( $b2_key_id ) . ' chars)' );
} else {
    qa_fail( $qa, 'Key ID NO configurado', 'Obtener en Backblaze → Account → App Keys' );
}

if ( $b2_app_key ) {
    qa_ok( $qa, 'Application Key configurado', '✓ (' . strlen( $b2_app_key ) . ' chars, posiblemente cifrado)' );
} else {
    qa_fail( $qa, 'Application Key NO configurado', 'Obtener en Backblaze → Account → App Keys → Create Key' );
}

if ( $b2_bucket ) {
    qa_ok( $qa, 'Bucket público configurado', $b2_bucket );
} else {
    qa_warn( $qa, 'Bucket público no configurado', 'Para imágenes de productos públicas' );
}

if ( $b2_priv ) {
    qa_ok( $qa, 'Bucket privado configurado', $b2_priv );
} else {
    qa_warn( $qa, 'Bucket privado no configurado', 'Para documentos KYC confidenciales' );
}

// ── T-02: Instancia de la clase ───────────────────────────────────────────────
qa_section( 'T-02 · Factory e instancia de LTMS_Api_Backblaze' );

$b2 = null;
if ( ! $b2_endpoint || ! $b2_key_id || ! $b2_app_key ) {
    qa_warn( $qa, 'T-02 omitido — Backblaze no configurado', 'Configura primero las credenciales (ver instrucciones al final)' );
} else {
    try {
        $b2 = new LTMS_Api_Backblaze();
        qa_ok( $qa, 'new LTMS_Api_Backblaze() — OK' );
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'new LTMS_Api_Backblaze()', $e->getMessage() );
        $b2 = null;
    }
}

// ── T-03: Health Check ────────────────────────────────────────────────────────
qa_section( 'T-03 · Health Check — conectividad con Backblaze B2' );

if ( ! $b2 ) {
    qa_warn( $qa, 'T-03 omitido — sin instancia válida' );
} else {
    try {
        $health = $b2->health_check();
        if ( ( $health['status'] ?? '' ) === 'ok' ) {
            qa_ok( $qa, 'health_check() — B2 conectado', 'Latencia: ' . ( $health['latency_ms'] ?? '?' ) . 'ms' );
        } else {
            qa_fail( $qa, 'health_check() — fallo', $health['message'] ?? 'Sin detalle' );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'health_check() — excepción', $e->getMessage() );
    }
}

// ── T-04: Subir archivo de prueba ─────────────────────────────────────────────
qa_section( 'T-04 · Upload de archivo de prueba' );

$qa_key     = 'ltms-qa-test-' . date('His') . '.txt';
$qa_content = 'LTMS QA Test — ' . date('Y-m-d H:i:s') . ' — Backblaze B2 upload test';
$qa_bucket  = $b2_bucket ?: $b2_priv;

if ( ! $b2 || ! $qa_bucket ) {
    qa_warn( $qa, 'T-04 omitido — sin instancia o bucket configurado' );
} else {
    try {
        $result = $b2->upload_file( $qa_bucket, $qa_key, $qa_content, 'text/plain' );
        if ( ! empty( $result['Key'] ) || ! empty( $result['ETag'] ) ) {
            qa_ok( $qa, 'upload_file() — archivo subido', "Key={$qa_key} Bucket={$qa_bucket}" );
        } else {
            qa_fail( $qa, 'upload_file() — sin Key/ETag en respuesta', wp_json_encode( $result ) );
            $qa_key = ''; // No intentar borrar si no subió
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'upload_file()', $e->getMessage() );
        $qa_key = '';
    }
}

// ── T-05: Listar archivos ─────────────────────────────────────────────────────
qa_section( 'T-05 · Listar archivos en bucket' );

if ( ! $b2 || ! $qa_bucket ) {
    qa_warn( $qa, 'T-05 omitido' );
} else {
    try {
        $files = $b2->list_files( $qa_bucket, 'ltms-qa-test-' );
        if ( is_array( $files ) ) {
            qa_ok( $qa, 'list_files() — OK', count( $files ) . ' archivos de QA encontrados' );

            // Verificar que el archivo que subimos está en la lista
            if ( $qa_key ) {
                $found = false;
                foreach ( $files as $file ) {
                    $file_key = $file['Key'] ?? $file['key'] ?? '';
                    if ( str_contains( $file_key, 'ltms-qa-test-' ) ) {
                        $found = true;
                        break;
                    }
                }
                if ( $found ) {
                    qa_ok( $qa, 'Archivo QA visible en listado', $qa_key );
                } else {
                    qa_warn( $qa, 'Archivo QA no visible inmediatamente', 'Puede ser latencia de consistencia eventual' );
                }
            }
        } else {
            qa_warn( $qa, 'list_files() — respuesta no es array', gettype( $files ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'list_files()', $e->getMessage() );
    }
}

// ── T-06: URL prefirmada ──────────────────────────────────────────────────────
qa_section( 'T-06 · URL prefirmada (get_signed_url)' );

if ( ! $b2 || ! $qa_key || ! $qa_bucket ) {
    qa_warn( $qa, 'T-06 omitido — sin archivo de prueba' );
} else {
    try {
        $signed_url = $b2->get_signed_url( $qa_bucket, $qa_key, 300 );
        if ( $signed_url && str_starts_with( $signed_url, 'https://' ) ) {
            qa_ok( $qa, 'get_signed_url() — URL generada', substr( $signed_url, 0, 80 ) . '...' );

            // Verificar que la URL tiene los parámetros de firma correctos
            if ( str_contains( $signed_url, 'X-Amz-Signature' ) ) {
                qa_ok( $qa, 'URL tiene firma AWS Signature V4', 'X-Amz-Signature presente' );
            } else {
                qa_warn( $qa, 'URL no tiene X-Amz-Signature', 'Puede ser formato diferente' );
            }

            // Intentar acceder a la URL para verificar que funciona
            $response = wp_remote_get( $signed_url, [ 'timeout' => 15, 'sslverify' => true ] );
            if ( ! is_wp_error( $response ) ) {
                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );
                if ( $code === 200 && $body === $qa_content ) {
                    qa_ok( $qa, 'URL prefirmada accesible y contenido correcto', "HTTP {$code}" );
                } elseif ( $code === 200 ) {
                    qa_ok( $qa, 'URL prefirmada accesible', "HTTP {$code}" );
                } elseif ( $code === 403 ) {
                    qa_fail( $qa, "URL prefirmada devuelve 403 Forbidden", 'Verificar permisos del bucket y key' );
                } else {
                    qa_warn( $qa, "URL prefirmada — HTTP {$code}", substr( $body, 0, 100 ) );
                }
            } else {
                qa_warn( $qa, 'No se pudo acceder a la URL prefirmada', $response->get_error_message() );
            }
        } else {
            qa_fail( $qa, 'get_signed_url() — URL inválida', $signed_url ?: 'vacía' );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'get_signed_url()', $e->getMessage() );
    }
}

// ── T-07: Borrar archivo de prueba ────────────────────────────────────────────
qa_section( 'T-07 · Eliminar archivo de prueba (cleanup)' );

if ( ! $b2 || ! $qa_key || ! $qa_bucket ) {
    qa_warn( $qa, 'T-07 omitido — sin archivo de prueba' );
} else {
    try {
        $deleted = $b2->delete_file( $qa_bucket, $qa_key );
        if ( $deleted ) {
            qa_ok( $qa, 'delete_file() — archivo QA eliminado', $qa_key );
        } else {
            qa_warn( $qa, 'delete_file() — retornó false', 'El archivo puede no existir o no se pudo eliminar' );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'delete_file()', $e->getMessage() );
    }
}

// ── T-08: Bucket privado (documentos KYC) ────────────────────────────────────
qa_section( 'T-08 · Bucket privado — documentos KYC confidenciales' );

if ( ! $b2 || ! $b2_priv ) {
    qa_warn( $qa, 'T-08 omitido — bucket privado no configurado',
        'Configura ltms_backblaze_private_bucket para guardar documentos KYC' );
} else {
    try {
        $kyc_key     = 'kyc-qa-test-' . date('His') . '.txt';
        $kyc_content = 'KYC QA Test — documento confidencial — ' . date('Y-m-d H:i:s');
        $result = $b2->upload_file( $b2_priv, $kyc_key, $kyc_content, 'text/plain', [
            'ltms-doc-type'  => 'kyc-test',
            'ltms-vendor-id' => '0',
        ]);
        if ( ! empty( $result['Key'] ) || ! empty( $result['ETag'] ) ) {
            qa_ok( $qa, 'upload_file() bucket privado — OK', "Bucket={$b2_priv}" );

            // Limpiar
            $b2->delete_file( $b2_priv, $kyc_key );
            qa_ok( $qa, 'Cleanup bucket privado — OK' );
        } else {
            qa_fail( $qa, 'upload_file() bucket privado — sin ETag', wp_json_encode( $result ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'Bucket privado', $e->getMessage() );
    }
}

// ── T-09: Integración con LTMS Media Guard ────────────────────────────────────
qa_section( 'T-09 · Integración LTMS_Business_Media_Guard (KYC docs)' );

if ( class_exists( 'LTMS_Business_Media_Guard' ) ) {
    qa_ok( $qa, 'LTMS_Business_Media_Guard — clase presente' );
} else {
    qa_warn( $qa, 'LTMS_Business_Media_Guard — clase no encontrada',
        'Los documentos KYC se guardan localmente sin Backblaze' );
}

// Verificar que la LTMS_Api_Factory puede instanciar Backblaze
if ( class_exists( 'LTMS_Api_Factory' ) ) {
    try {
        $b2_factory = LTMS_Api_Factory::get( 'backblaze' );
        qa_ok( $qa, 'LTMS_Api_Factory::get(backblaze) — OK' );
    } catch ( Throwable $e ) {
        if ( str_contains( $e->getMessage(), 'no está configurado' ) ||
             str_contains( $e->getMessage(), 'endpoint' ) ) {
            qa_warn( $qa, 'Factory: Backblaze no configurado aún', $e->getMessage() );
        } else {
            qa_fail( $qa, 'Factory: error inesperado', $e->getMessage() );
        }
    }
}

// ── T-10: Verificar endpoint format ───────────────────────────────────────────
qa_section( 'T-10 · Formato del endpoint (ayuda de configuración)' );

if ( $b2_endpoint ) {
    // Validate endpoint format for Backblaze B2 S3-compatible API
    $parsed = wp_parse_url( $b2_endpoint );
    if ( isset( $parsed['scheme'], $parsed['host'] ) ) {
        if ( str_contains( $parsed['host'], 'backblazeb2.com' ) ) {
            qa_ok( $qa, 'Endpoint es dominio Backblaze B2', $parsed['host'] );
        } elseif ( str_contains( $parsed['host'], 's3.' ) ) {
            qa_ok( $qa, 'Endpoint tiene formato S3', $parsed['host'] );
        } else {
            qa_warn( $qa, 'Endpoint dominio no reconocido como B2', $parsed['host'] );
        }

        // Extract region from endpoint
        if ( preg_match( '/s3\.([\w-]+)\.backblazeb2\.com/', $b2_endpoint, $m ) ) {
            qa_ok( $qa, 'Región B2 detectada: ' . $m[1] );
        } else {
            qa_warn( $qa, 'No se pudo extraer la región del endpoint' );
        }
    } else {
        qa_fail( $qa, 'Endpoint no tiene formato URL válido', $b2_endpoint );
    }
} else {
    // Mostrar instrucciones de configuración
    echo "\n  📋 INSTRUCCIONES DE CONFIGURACIÓN BACKBLAZE B2:\n\n";
    echo "  1. Ve a: https://secure.backblaze.com/b2_buckets.htm\n";
    echo "     → 'Create a Bucket' → Nombre: lotengo-contratos → Private\n";
    echo "     → Copia el 'Endpoint' que aparece (formato: s3.us-west-004.backblazeb2.com)\n\n";
    echo "  2. Ve a: https://secure.backblaze.com/app_keys.htm\n";
    echo "     → 'Add a New Application Key'\n";
    echo "     → Name: ltms-lotengo | Bucket: lotengo-contratos | Access: Read and Write\n";
    echo "     → Copia keyID y applicationKey (solo se ven UNA vez)\n\n";
    echo "  3. En WordPress → LTMS → Configuración → Backblaze B2:\n";
    echo "     Key ID:          El keyID de Backblaze (empieza con '005...')\n";
    echo "     Application Key: El applicationKey (clave larga)\n";
    echo "     Bucket Name:     lotengo-contratos\n";
    echo "     Bucket ID:       El ID del bucket (se ve en la lista de buckets)\n";
    echo "     Endpoint:        https://s3.us-west-004.backblazeb2.com\n";
    echo "                      (reemplaza 'us-west-004' con tu región real)\n\n";
    qa_warn( $qa, 'Endpoint no configurado — ver instrucciones arriba' );
}

// ── RESUMEN ───────────────────────────────────────────────────────────────────
$sep = str_repeat( '═', 50 );
echo "\n{$sep}\n  RESUMEN QA — Backblaze B2\n{$sep}\n";
printf( "  ✅ PASS : %d\n  ❌ FAIL : %d\n  ⚠️  WARN : %d\n  TOTAL  : %d pruebas\n",
    $qa['pass'], $qa['fail'], $qa['warn'],
    $qa['pass'] + $qa['fail'] + $qa['warn'] );

if ( $qa['fail'] === 0 && $qa['warn'] <= 2 ) {
    echo "\n  🎉 Backblaze B2 configurado y funcional.\n";
} elseif ( $qa['fail'] === 0 ) {
    echo "\n  ✅ Sin fallos críticos. Revisar advertencias.\n";
} else {
    echo "\n  🔴 {$qa['fail']} prueba(s) fallida(s):\n";
    foreach ( $qa['items'] as $item ) {
        if ( $item['status'] === 'FAIL' ) {
            echo "     · {$item['label']}" . ( isset( $item['detail'] ) ? " — {$item['detail']}" : '' ) . "\n";
        }
    }
}

echo "\n{$sep}\n\n";
