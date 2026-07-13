#!/usr/bin/env node
/**
 * LTMS JS Syntax Checker — valida sintaxis de todos los archivos JS
 * Usa Node.js built-in parser (no requiere dependencias externas).
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const JS_DIR = path.join(PLUGIN_DIR, 'assets', 'js');

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
        // Use vm.Script to parse (not execute) the code
        new vm.Script(code, { filename: filePath });
        console.log(`OK    ${relPath}`);
        checked++;
    } catch (e) {
        console.log(`FAIL  ${relPath}  line ${e.lineNumber || '?'}: ${e.message}`);
        allOk = false;
        failed++;
    }
}

// Find all .js files (excluding .min.js)
const files = [];
function scanDir(dir) {
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
        const fullPath = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            scanDir(fullPath);
        } else if (entry.name.endsWith('.js') && !entry.name.endsWith('.min.js')) {
            files.push(fullPath);
        }
    }
}

if (fs.existsSync(JS_DIR)) {
    scanDir(JS_DIR);
}

console.log(`\n🔍 Checking ${files.length} JS files...\n`);

for (const f of files) {
    checkFile(f);
}

console.log(`\n${'='.repeat(50)}`);
console.log(`📊 JS Check Summary: ${checked} OK, ${failed} failed`);

if (!allOk) {
    process.exit(1);
}
