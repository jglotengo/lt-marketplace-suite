#!/usr/bin/env node
/**
 * minify-css.js — regenera el .min.css de una hoja del design system.
 * Uso: node bin/minify-css.js assets/css/ltms-plaza-viva.css
 * Estrategia offline (mismas reglas del min actual): stripear comentarios
 * /* *\/, colapsar whitespace, quitar espacios alrededor de :;,{} y quitar
 * el ';' final de cada bloque. Determinista y sin dependencias.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const srcPath = process.argv[2];
if (!srcPath || !fs.existsSync(srcPath)) {
    console.error('Uso: node bin/minify-css.js <archivo.css>');
    process.exit(1);
}

let css = fs.readFileSync(srcPath, 'utf8');

// 1. Comentarios /* ... */ (la hoja no contiene strings con "/*" — verificado).
css = css.replace(/\/\*[\s\S]*?\*\//g, '');

// 2. Espacios alrededor de separadores de bloque/declaración.
css = css.replace(/\s*([{}:;,])\s*/g, '$1');

// 3. ';' final antes de '}' (redundante) + espacios antes de '!important'
// (paridad con el formato del min histórico — los tests lo asertan sin espacio).
css = css.replace(/;}/g, '}');
css = css.replace(/\s*!important/g, '!important');

// 4. Colapsar saltos de línea y espacios múltiples restantes.
css = css.replace(/\s+/g, ' ').trim();

const dstPath = srcPath.replace(/\.css$/, '.min.css');
fs.writeFileSync(dstPath, css);
console.log(path.basename(dstPath) + ' regenerado: ' + css.length + ' bytes');
