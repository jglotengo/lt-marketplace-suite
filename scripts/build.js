#!/usr/bin/env node
/**
 * LTMS Build Script — genera archivos .min.js y .min.css
 *
 * Uso:
 *   node scripts/build.js           — genera todos los .min
 *   node scripts/build.js --js-only — solo JS
 *   node scripts/build.js --css-only — solo CSS
 *
 * Este script reemplaza al antiguo build-minify.js y usa terser + clean-css
 * para generar archivos minificados correctamente.
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Ensure deps are installed
try {
    require('terser');
    require('clean-css');
} catch (e) {
    console.log('📦 Installing build dependencies...');
    execSync('npm install', { cwd: __dirname + '/..', stdio: 'inherit' });
}

const { minify: terserMinify } = require('terser');
const CleanCSS = require('clean-css');
const glob = require('glob');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const ASSETS_DIR = path.join(PLUGIN_DIR, 'assets');

const args = process.argv.slice(2);
const jsOnly = args.includes('--js-only');
const cssOnly = args.includes('--css-only');

let stats = { js: { total: 0, minified: 0, skipped: 0, errors: 0 }, css: { total: 0, minified: 0, skipped: 0, errors: 0 } };

async function minifyJS(filePath) {
    const relPath = path.relative(PLUGIN_DIR, filePath);
    const outPath = filePath.replace(/\.js$/, '.min.js');

    // Skip files that are already minified or third-party
    if (filePath.includes('.min.js') || filePath.includes('chart.umd') || filePath.includes('jquery')) {
        console.log(`  ⏭️  Skip (already min/3rd party): ${relPath}`);
        stats.js.skipped++;
        return;
    }

    stats.js.total++;
    console.log(`  📦 Minifying: ${relPath}`);

    try {
        const code = fs.readFileSync(filePath, 'utf8');
        const result = await terserMinify(code, {
            compress: { drop_console: false, drop_debugger: true },
            mangle: { reserved: ['LTMS', 'ltmsDashboard', 'ltmsUX', 'jQuery', '$', 'wp'] },
            format: { comments: false },
        });

        if (result.code) {
            const originalSize = Buffer.byteLength(code);
            const minSize = Buffer.byteLength(result.code);
            const reduction = ((1 - minSize / originalSize) * 100).toFixed(1);
            fs.writeFileSync(outPath, result.code);
            console.log(`     ✅ ${(originalSize/1024).toFixed(1)}KB → ${(minSize/1024).toFixed(1)}KB (${reduction}% reduction)`);
            stats.js.minified++;
        }
    } catch (err) {
        console.error(`     ❌ Error: ${err.message}`);
        stats.js.errors++;
    }
}

function minifyCSS(filePath) {
    const relPath = path.relative(PLUGIN_DIR, filePath);
    const outPath = filePath.replace(/\.css$/, '.min.css');

    if (filePath.includes('.min.css') || filePath.includes('.map')) {
        console.log(`  ⏭️  Skip (already min): ${relPath}`);
        stats.css.skipped++;
        return;
    }

    stats.css.total++;
    console.log(`  📦 Minifying: ${relPath}`);

    try {
        const source = fs.readFileSync(filePath, 'utf8');
        const result = new CleanCSS({
            level: 2,
            returnPromise: false,
        }).minify(source);

        if (result.styles) {
            const originalSize = Buffer.byteLength(source);
            const minSize = Buffer.byteLength(result.styles);
            const reduction = ((1 - minSize / originalSize) * 100).toFixed(1);
            fs.writeFileSync(outPath, result.styles);
            console.log(`     ✅ ${(originalSize/1024).toFixed(1)}KB → ${(minSize/1024).toFixed(1)}KB (${reduction}% reduction)`);
            stats.css.minified++;
        } else {
            console.error(`     ❌ Error: ${result.errors.join(', ')}`);
            stats.css.errors++;
        }
    } catch (err) {
        console.error(`     ❌ Error: ${err.message}`);
        stats.css.errors++;
    }
}

async function main() {
    console.log('🚀 LTMS Build Script');
    console.log('====================\n');

    if (!cssOnly) {
        console.log('\n📦 JavaScript files:');
        const jsFiles = glob.sync('assets/js/*.js', { cwd: PLUGIN_DIR, absolute: true, ignore: ['**/*.min.js', '**/chart.umd*', '**/jquery*'] });
        for (const f of jsFiles) {
            await minifyJS(f);
        }
    }

    if (!jsOnly) {
        console.log('\n🎨 CSS files:');
        const cssFiles = glob.sync('assets/css/*.css', { cwd: PLUGIN_DIR, absolute: true, ignore: ['**/*.min.css', '**/*.map'] });
        for (const f of cssFiles) {
            minifyCSS(f);
        }
    }

    console.log('\n' + '='.repeat(50));
    console.log('📊 Build Summary:');
    if (!cssOnly) {
        console.log(`  JS:  ${stats.js.minified}/${stats.js.total} minified, ${stats.js.skipped} skipped, ${stats.js.errors} errors`);
    }
    if (!jsOnly) {
        console.log(`  CSS: ${stats.css.minified}/${stats.css.total} minified, ${stats.css.skipped} skipped, ${stats.css.errors} errors`);
    }

    const totalErrors = stats.js.errors + stats.css.errors;
    if (totalErrors > 0) {
        console.log(`\n❌ Build completed with ${totalErrors} error(s)`);
        process.exit(1);
    } else {
        console.log('\n✅ Build completed successfully!');
    }
}

main().catch(err => {
    console.error('Fatal error:', err);
    process.exit(1);
});
