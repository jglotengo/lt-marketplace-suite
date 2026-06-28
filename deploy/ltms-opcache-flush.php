<?php
/**
 * LTMS OPcache flush script.
 * Ejecutar: wp eval-file deploy/ltms-opcache-flush.php --allow-root --path=...
 * Borra el bytecode compilado de los archivos PHP del plugin para forzar
 * que PHP recompile desde disco (necesario tras git reset en SiteGround).
 */
if ( ! defined( 'ABSPATH' ) ) {
	// Permitir ejecución vía wp eval-file (no tiene ABSPATH)
}

if ( ! function_exists( 'opcache_invalidate' ) ) {
	echo "opcache_invalidate() no disponible en este entorno.\n";
	exit( 1 );
}

$plugin_dir = __DIR__ . '/..';
$count      = 0;
$errors     = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( $file->getExtension() === 'php' ) {
		$result = opcache_invalidate( $file->getPathname(), true );
		if ( $result ) {
			$count++;
		} else {
			$errors++;
		}
	}
}

// Reset completo si está disponible
if ( function_exists( 'opcache_reset' ) ) {
	opcache_reset();
	echo "opcache_reset() ejecutado.\n";
}

echo "Archivos invalidados: {$count}\n";
echo "Errores: {$errors}\n";
echo "Done.\n";
