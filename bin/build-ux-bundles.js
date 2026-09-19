#!/usr/bin/env node
/**
 * build-ux-bundles.js — HOME-SLOW fase 2: split del monolito UX.
 *
 * Genera a partir del monolito fuente `assets/js/ltms-ux-enhancements.js`
 * (fuente de verdad, NO se edita) tres bundles:
 *
 *   - assets/js/ltms-ux-shared.js      → secciones SHARED + HELPERS + INIT shared
 *   - assets/js/ltms-ux-dashboard.js   → secciones DASHBOARD (+ alias a shared)
 *   - assets/js/ltms-ux-storefront.js  → secciones STOREFRONT (+ alias a shared)
 *
 * Reglas del split:
 *   1. El monolito sigue siendo la única fuente de verdad; este script es
 *      re-ejecutable para regenerar los bundles tras cualquier cambio.
 *   2. Los cruces dashboard<->storefront (celebrateConfetti, showOrderSuccess)
 *      se mueven al bundle shared (con su exposición pública en LTMS.UX).
 *   3. Los bundles dashboard/storefront resuelven las funciones compartidas
 *      vía aliases locales (`const toast = LTMS.UX.toast;`) generados por
 *      análisis estático (identificadores usados ∩ definiciones del shared).
 *   4. Cada bundle tiene su propio `initAll()` con SOLO sus init* (las secciones
 *      se auto-desactivan por selectores, por lo que el orden no importa).
 *   5. El re-init jQuery del SPA del dashboard (`ltms:view:loaded`) solo vive
 *      en el bundle dashboard y delega a `LTMS.UX.reinit` (shared) + init local.
 *
 * Uso: node bin/build-ux-bundles.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const SRC      = path.join(__dirname, '..', 'assets', 'js', 'ltms-ux-enhancements.js');
const ASSETS   = path.join(__dirname, '..', 'assets', 'js');

const source = fs.readFileSync(SRC, 'utf8');
const lines  = source.split('\n');

/* ── Mapa de secciones: [línea de inicio (1-based), categoría] ───────────── */
const SECTION_MAP = [
  [31, 'shared'], [141, 'shared'], [179, 'dashboard'], [249, 'shared'], [350, 'shared'],
  [383, 'shared'], [412, 'shared'], [493, 'shared'], [520, 'shared'], [544, 'shared'],
  [584, 'shared'], [608, 'shared'], [665, 'shared'], [729, 'dashboard'], [747, 'shared'],
  [775, 'dashboard'], [818, 'shared'], [836, 'dashboard'], [884, 'shared'], [1018, 'dashboard'],
  [1222, 'dashboard'], [1389, 'shared'], [1420, 'dashboard'], [1651, 'dashboard'], [1864, 'shared'],
  [2015, 'dashboard'], [2244, 'dashboard'], [2355, 'shared'], [2487, 'shared'], [2573, 'shared'],
  [2627, 'shared'], [2690, 'shared'], [2773, 'shared'], [2944, 'dashboard'], [3045, 'shared'],
  [3240, 'dashboard'], [3361, 'dashboard'], [3473, 'dashboard'], [3682, 'dashboard'], [3782, 'shared'],
  [3875, 'shared'], [3993, 'shared'], [4087, 'shared'], [4193, 'shared'], [4330, 'shared'],
  [4470, 'shared'], [4565, 'shared'], [4646, 'shared'], [4890, 'dashboard'], [4967, 'dashboard'],
  [5078, 'shared'], [5232, 'shared'], [5322, 'storefront'], [5715, 'dashboard'], [5873, 'shared'],
  [5969, 'shared'], [6056, 'storefront'], [6220, 'storefront'], [6413, 'storefront'], [6502, 'storefront'],
  [6626, 'shared'], [6703, 'storefront'], [6813, 'shared'], [6899, 'storefront'], [7093, 'shared'],
  [7214, 'storefront'], [7312, 'storefront'], [7363, 'shared'], [7427, 'shared'], [7490, 'shared'],
  [7581, 'storefront'], [7683, 'storefront'], [7793, 'storefront'], [7925, 'shared'], [7974, 'shared'],
  [8009, 'storefront'], [8264, 'storefront'], [8377, 'storefront'], [8489, 'storefront'], [8634, 'storefront'],
  [8712, 'storefront'], [8771, 'storefront'], [8845, 'storefront'], [8908, 'storefront'], [9017, 'storefront'],
  [9099, 'storefront'], [9192, 'storefront'], [9286, 'storefront'], [9420, 'storefront'], [9507, 'storefront'],
  [9704, 'storefront'], [9798, 'dashboard'], [9855, 'shared'], [10024, 'storefront'], [10165, 'storefront'],
  [10281, 'storefront'], [10354, 'storefront'], [10462, 'shared'], [10510, 'shared'], [10610, 'dashboard'],
  [10844, 'dashboard'], [10924, 'storefront'], [10981, 'storefront'], [11038, 'storefront'], [11157, 'storefront'],
  [11227, 'storefront'], [11322, 'storefront'], [11489, 'storefront'], [11649, 'dashboard'], [11716, 'storefront'],
  [11774, 'storefront'], [11884, 'storefront'], [11934, 'dashboard'], [12011, 'storefront'], [12137, 'storefront'],
  [12233, 'storefront'], [12400, 'storefront'], [12509, 'dashboard'], [12570, 'dashboard'], [12602, 'dashboard'],
  [12659, 'dashboard'], [12683, 'dashboard'], [12703, 'shared'], [12729, 'shared'], [12857, 'excluded'],
];

// El INIT del monolito (12857-13013) NO es una sección extraíble: es el punto de
// entrada que llama a las 114 init* y cierra el IIFE. El script genera su propio
// initAll() por bundle, así que se excluye de la extracción.

/* ── Funciones que se mueven a shared (cruces dashboard<->storefront) ────── */
const MOVE_TO_SHARED = [
  { name: 'celebrateConfetti', start: 1798 },
  { name: 'showOrderSuccess',  start: 8642 },
];

/* Líneas de exposición que se ELIMINAN de su sección al mover la función
   (el shared las re-expone). 1-based. */
const REMOVE_EXPOSURE_LINES = new Set([1821, 8709]);

/* Alias core (cubre las funciones compartidas más usadas por ambos bundles). */
const CORE_SHARED_FNS = [
  'toast', 'toastSuccess', 'toastError', 'toastWarning', 'toastInfo',
  'trapFocus', 'announce', 'escapeHtml', 'debounce', 'confirmDialog',
  'formatCurrency', 'formatDate', 'formatNumber',
  'celebrateConfetti', 'showOrderSuccess',
  'renderEmptyState', 'createCountdown', 'renderStockIndicator',
  'createStarRating', 'openPrintPreview',
];

const KEYWORDS = new Set([
  'if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'typeof',
  'new', 'delete', 'in', 'instanceof', 'case', 'do', 'else', 'break', 'continue',
  'throw', 'try', 'yield', 'await', 'void', 'with', 'default', 'extends', 'import',
  'export', 'super', 'static', 'class', 'const', 'let', 'var', 'require', 'module',
]);

/* ── Utilidades ──────────────────────────────────────────────────────────── */
function extractBlock(startLine1, endLine1) {
  return lines.slice(startLine1 - 1, endLine1);
}

function collectInitNames(blockLines) {
  const names = [];
  // Solo funciones INIT top-level del IIFE (indentación de exactamente 4
  // espacios). Las funciones anidadas (ej. initCropBox dentro de
  // openImageCropper) NO deben llamarse desde initAll.
  const reFn    = /^ {4}function\s+(init[A-Za-z_$][\w$]*)\s*\(/g;
  const reConst = /^ {4}(?:const|let|var)\s+(init[A-Za-z_$][\w$]*)\s*=\s*(?:function|async|\(|\w+\s*=>)/g;
  for (const line of blockLines) {
    for (const m of line.matchAll(reFn)) names.push(m[1]);
    for (const m of line.matchAll(reConst)) names.push(m[1]);
  }
  if (blockLines.join('\n').includes('function loadNotifSettings')) names.push('loadNotifSettings');
  if (blockLines.join('\n').includes('function telemetryInit')) names.push('telemetryInit');
  return names;
}

function collectDefinedFunctions(blockLines) {
  const defs = new Set();
  // Solo funciones/const top-level del IIFE (indentación 4 espacios). Funciones
  // anidadas (ej. toggle, initCropBox dentro de otras funciones) NO son
  // visibles en el scope top-level y no deben considerarse "definidas" para
  // aliases ni exports.
  const reFn    = /^ {4}function\s+([A-Za-z_$][\w$]*)\s*\(/g;
  const reConst = /^ {4}(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:function|async|\(|new|\w+\s*=>)/g;
  for (const line of blockLines) {
    for (const m of line.matchAll(reFn)) defs.add(m[1]);
    for (const m of line.matchAll(reConst)) defs.add(m[1]);
  }
  return defs;
}

function collectUsedIdentifiers(blockLines) {
  const used = new Set();
  const re = /(?<![\w$.])([A-Za-z_$][\w$]*)\s*\(/g;
  const text = blockLines.join('\n');
  for (const m of text.matchAll(re)) {
    const name = m[1];
    if (KEYWORDS.has(name)) continue;
    used.add(name);
  }
  return used;
}

/* Balanceo de llaves simple: extrae `function NAME(` hasta su `}` de cierre. */
function extractFunctionByName(name, startLine1) {
  const body = [];
  let depth = 0;
  let started = false;
  for (let i = startLine1 - 1; i < lines.length; i++) {
    const line = lines[i];
    body.push(line);
    for (const ch of line) {
      if (ch === '{') { depth++; started = true; }
      else if (ch === '}') { depth--; }
    }
    if (started && depth === 0) break;
  }
  return body;
}

/* ── Armado de bundles ───────────────────────────────────────────────────── */
function buildSharedHeader() {
  // Header original del monolito: líneas 1-30 (IIFE + namespace + CONFIG).
  return lines.slice(0, 30).join('\n');
}

function buildBundleHeader() {
  return [
    '(function () {',
    "    'use strict';",
    '',
    '    // ── Namespace LTMS.UX ──────────────────────────────────────',
    '    window.LTMS = window.LTMS || {};',
    '    LTMS.UX = LTMS.UX || {};',
    '',
    '    // CONFIG del bundle shared (definido en ltms-ux-shared.js).',
    '    const CONFIG = (LTMS.UX && LTMS.UX.config) || {};',
    '',
  ].join('\n');
}

function buildAliases(aliasNames) {
  if (!aliasNames.length) return '';
  const unique = [...new Set(aliasNames)];
  return unique
    .map((n) => `    const ${n} = LTMS.UX.${n};`)
    .join('\n') + '\n\n';
}

function buildInitAll(initNames, opts = {}) {
  const calls = initNames.map((n) => `            ${n}();`).join('\n');
  let reinit = '';
  if (opts.dashboard) {
    reinit = `
            // Re-inicializar cuando el SPA del dashboard inyecta HTML nuevo.
            if (typeof jQuery !== 'undefined') {
                jQuery(document).on('ltms:view:loaded ltms:modal:open', () => {
                    if (typeof LTMS.UX.reinit === 'function') LTMS.UX.reinit();
                    initOrdersSearch();
                });
            }`;
  }
  let ready = '';
  if (opts.ready) {
    ready = `
            LTMS.UX.ready = true;
            LTMS.UX.version = '2.0.0';`;
  }
  return [
    '    // ═══════════════════════════════════════════════════════════',
    '    // INIT — Punto de entrada del bundle',
    '    // ═══════════════════════════════════════════════════════════',
    '',
    '    function init() {',
    "        if (document.readyState === 'loading') {",
    "            document.addEventListener('DOMContentLoaded', initAll);",
    '        } else {',
    '            initAll();',
    '        }',
    '    }',
    '',
    '    function initAll() {',
    '        try {',
    calls,
    reinit,
    ready,
    '        } catch (err) {',
    "            console.error('[LTMS.UX] Error inicializando:', err);",
    '        }',
    '    }',
    '',
    '    // Auto-init',
    '    init();',
    '',
    '})();',
  ].join('\n');
}

/* ── Categorización de secciones ─────────────────────────────────────────── */
const categories = { shared: [], dashboard: [], storefront: [], excluded: [] };

for (let s = 0; s < SECTION_MAP.length; s++) {
  const [start, cat] = SECTION_MAP[s];
  const end = (s + 1 < SECTION_MAP.length) ? SECTION_MAP[s + 1][0] - 1 : lines.length;
  categories[cat].push({ start, end });
}

/* Quitar de sus secciones las funciones movidas a shared y las líneas de
   exposición removidas. */
const movedFnLines = new Map(); // name -> [lineStart1, lineEnd1]
for (const mv of MOVE_TO_SHARED) {
  const block = extractFunctionByName(mv.name, mv.start);
  movedFnLines.set(mv.name, { start: mv.start, end: mv.start + block.length - 1 });
}

function sectionLines({ start, end }) {
  const out = [];
  for (let i = start - 1; i < end; i++) {
    const lineNo = i + 1;
    if (REMOVE_EXPOSURE_LINES.has(lineNo)) continue;
    let skipped = false;
    for (const { start: ms, end: me } of movedFnLines.values()) {
      if (lineNo >= ms && lineNo <= me) { skipped = true; break; }
    }
    if (skipped) continue;
    out.push(lines[i]);
  }
  return out;
}

const sharedBlock    = [];
const dashboardBlock = [];
const storefrontBlock = [];

for (const seg of categories.shared)    sharedBlock.push(...sectionLines(seg));
for (const seg of categories.dashboard) dashboardBlock.push(...sectionLines(seg));
for (const seg of categories.storefront) storefrontBlock.push(...sectionLines(seg));

/* Funciones movidas al shared (cuerpo + exposición). */
const movedShared = [];
for (const mv of MOVE_TO_SHARED) {
  const block = extractFunctionByName(mv.name, mv.start);
  movedShared.push(...block);
  movedShared.push(`    LTMS.UX.${mv.name} = ${mv.name};`);
  movedShared.push('');
}

/* ── Análisis de dependencias ────────────────────────────────────────────── */
const sharedDefs = collectDefinedFunctions([...sharedBlock, ...movedShared]);
const sharedUsed = collectUsedIdentifiers(sharedBlock);

const dashboardDefs = collectDefinedFunctions(dashboardBlock);
const storefrontDefs = collectDefinedFunctions(storefrontBlock);

const dashboardUsed = collectUsedIdentifiers(dashboardBlock);
const storefrontUsed = collectUsedIdentifiers(storefrontBlock);

function computeAliases(used, bundleDefs) {
  const needed = new Set(CORE_SHARED_FNS);
  for (const id of used) {
    if (sharedDefs.has(id) && !bundleDefs.has(id)) needed.add(id);
  }
  return [...needed];
}

const dashboardAliases = computeAliases(dashboardUsed, dashboardDefs);
const storefrontAliases = computeAliases(storefrontUsed, storefrontDefs);

/* Cruces no resueltos: identificadores usados por un bundle que están
   definidos en el OTRO bundle (deberían ser vacíos tras MOVE_TO_SHARED). */
const dashboardUsesStorefront = [...dashboardUsed].filter((id) => storefrontDefs.has(id) && !sharedDefs.has(id) && !dashboardDefs.has(id));
const storefrontUsesDashboard = [...storefrontUsed].filter((id) => dashboardDefs.has(id) && !sharedDefs.has(id) && !storefrontDefs.has(id));
const unresolved = [...dashboardUsesStorefront, ...storefrontUsesDashboard];
if (unresolved.length) {
  console.error('[build-ux-bundles] CRUCES NO RESUELTOS entre bundles:', unresolved);
  process.exit(1);
}

/* ── Exports del shared (solo funciones REALMENTE definidas en el scope) ── */
const sharedExports = new Set();
for (const id of [...CORE_SHARED_FNS, ...dashboardUsed, ...storefrontUsed]) {
  if (sharedDefs.has(id)) sharedExports.add(id);
}
sharedExports.delete('loadNotifSettings'); // vive solo en dashboard
sharedExports.delete('telemetryInit');     // vive solo en shared (se llama ahí)

// El Object.assign solo puede referenciar variables del scope del shared.
// toastSuccess/Error/Warning/Info son propiedades LTMS.UX.* (no variables) y
// se definen inline en la sección 1 — por eso se excluyen aquí.

const exportsBlock = `    // ── Exports públicos para bundles dependientes ──────────────
    Object.assign(LTMS.UX, {
        ${[...sharedExports].join(',\n        ')},
    });

    // Re-init compartido para el SPA del dashboard (elementos inyectados).
    LTMS.UX.reinit = function () {
        initPasswordStrength();
        initLazyImages();
    };

    LTMS.UX.config = CONFIG;
`;

/* ── initAll por bundle ──────────────────────────────────────────────────── */
const sharedInitNames = [...new Set(collectInitNames(sharedBlock))];
const dashboardInitNames = [...new Set(collectInitNames(dashboardBlock))];
const storefrontInitNames = [...new Set(collectInitNames(storefrontBlock))];

/* Orden del initAll del monolito original (12873-12986) como referencia para
   intercalar correctamente las init que dependen de otras ya inicializadas. */
const orderOf = (n) => source.indexOf(`            ${n}();`);
sharedInitNames.sort((a, b) => orderOf(a) - orderOf(b));
dashboardInitNames.sort((a, b) => orderOf(a) - orderOf(b));
storefrontInitNames.sort((a, b) => orderOf(a) - orderOf(b));

/* ── Generación de archivos ──────────────────────────────────────────────── */
const sharedFile = [
  buildSharedHeader(),
  '',
  '    // ═══════════════════════════════════════════════════════════',
  '    // SECCIONES SHARED (toasts, utilidades, animaciones, etc.)',
  '    // Generado por bin/build-ux-bundles.js desde ltms-ux-enhancements.js',
  '    // ═══════════════════════════════════════════════════════════',
  '',
  sharedBlock.join('\n'),
  '',
  '    // ═══════════════════════════════════════════════════════════',
  '    // FUNCIONES CRUZADAS movidas desde dashboard/storefront',
  '    // ═══════════════════════════════════════════════════════════',
  '',
  movedShared.join('\n'),
  '',
  exportsBlock,
  '',
  buildInitAll(sharedInitNames, { ready: true }),
].join('\n');

const dashboardFile = [
  buildBundleHeader(),
  '',
  '    // ── Aliases a funciones del bundle shared (ltms-ux-shared.js) ──',
  buildAliases(dashboardAliases),
  '    // ═══════════════════════════════════════════════════════════',
  '    // SECCIONES DASHBOARD (panel del vendedor + mi-cuenta)',
  '    // Generado por bin/build-ux-bundles.js desde ltms-ux-enhancements.js',
  '    // ═══════════════════════════════════════════════════════════',
  '',
  dashboardBlock.join('\n'),
  '',
  buildInitAll(dashboardInitNames, { dashboard: true }),
].join('\n');

const storefrontFile = [
  buildBundleHeader(),
  '',
  '    // ── Aliases a funciones del bundle shared (ltms-ux-shared.js) ──',
  buildAliases(storefrontAliases),
  '    // ═══════════════════════════════════════════════════════════',
  '    // SECCIONES STOREFRONT (tienda pública: home, shop, producto,',
  '    // carrito, checkout)',
  '    // Generado por bin/build-ux-bundles.js desde ltms-ux-enhancements.js',
  '    // ═══════════════════════════════════════════════════════════',
  '',
  storefrontBlock.join('\n'),
  '',
  buildInitAll(storefrontInitNames),
].join('\n');

/* ── Escritura ───────────────────────────────────────────────────────────── */
const outputs = {
  'ltms-ux-shared.js': sharedFile,
  'ltms-ux-dashboard.js': dashboardFile,
  'ltms-ux-storefront.js': storefrontFile,
};

for (const [file, content] of Object.entries(outputs)) {
  fs.writeFileSync(path.join(ASSETS, file), content);
  console.log(`[build-ux-bundles] generado assets/js/${file} (${content.split('\n').length} líneas)`);
}

console.log(`\n[build-ux-bundles] dashboard aliases: ${dashboardAliases.length}`);
console.log(`[build-ux-bundles] storefront aliases: ${storefrontAliases.length}`);
console.log(`[build-ux-bundles] shared exports: ${sharedExports.size}`);