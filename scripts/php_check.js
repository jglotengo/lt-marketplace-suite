#!/usr/bin/env node
/**
 * LTMS PHP Syntax Checker — valida la sintaxis de todos los archivos PHP
 * del plugin usando php-parser (AST real, no solo balance de llaves).
 *
 * Reemplaza el antiguo target `npm run lint:php` que apuntaba a
 * `scripts/php_check.js` (nunca existió) con `$(find ...)` (incompatible
 * con Windows). Escanea internamente los mismos paths que ese find:
 *   - includes/ (excluyendo vendor/)
 *   - lt-marketplace-suite.php en la raíz del plugin
 */

const fs = require('fs');
const path = require('path');
const { Engine } = require('php-parser');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const parser = new Engine({
    parser: {
        extractDoc: true,
        php7: true,
        locations: false,
        suppressErrors: false,
    },
});

let allOk = true;
let checked = 0;
let failed = 0;

function checkFile(filePath) {
    const relPath = path.relative(PLUGIN_DIR, filePath);
    let code;
    try {
        code = fs.readFileSync(filePath, 'utf8');
    } catch (e) {
        console.log(`FAIL  ${relPath}  (read error: ${e.message})`);
        allOk = false;
        failed++;
        return;
    }

    try {
        parser.parseCode(code);
        console.log(`OK    ${relPath}`);
        checked++;
    } catch (e) {
        const line = e.lineNumber || '?';
        const col = e.columnNumber || '?';
        console.log(`FAIL  ${relPath}  line ${line}:${col}: ${e.message}`);
        allOk = false;
        failed++;
    }
}

// Recolectar archivos PHP del directorio (recursivo, sin vendor/).
function collectPhp(dir, out, skipDirs) {
    if (!fs.existsSync(dir)) {
        return;
    }
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
        const fullPath = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (skipDirs && skipDirs.includes(entry.name)) {
                continue;
            }
            collectPhp(fullPath, out, skipDirs);
        } else if (entry.name.endsWith('.php')) {
            out.push(fullPath);
        }
    }
}

const files = [];
collectPhp(path.join(PLUGIN_DIR, 'includes'), files, ['vendor']);
// Raíz: solo lt-marketplace-suite.php (evita testear vendor/ o libs ajenas).
const rootBootstrap = path.join(PLUGIN_DIR, 'lt-marketplace-suite.php');
if (fs.existsSync(rootBootstrap)) {
    files.push(rootBootstrap);
}

console.log(`\n${'='.repeat(50)}`);
console.log(`Checking ${files.length} PHP files...\n`);

for (const f of files) {
    checkFile(f);
}

console.log(`\n${'='.repeat(50)}`);
console.log(`PHP Check Summary: ${checked} OK, ${failed} failed`);

if (!allOk) {
    process.exit(1);
}