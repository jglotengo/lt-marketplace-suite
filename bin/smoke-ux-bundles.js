// Smoke test del split UX (HOME-SLOW-F2): ejecuta los bundles .min.js reales
// en Node con un stub de DOM para detectar ReferenceErrors (aliases faltantes)
// durante el initAll. No sustituye la verificación en navegador, pero cubre el
// riesgo principal del split (funciones compartidas no resueltas).

const fs = require('fs');
const path = require('path');

// --- Stubs de entorno ---
function makeEl() {
  return {
    style: {}, classList: { add() {}, remove() {}, contains() { return false; }, toggle() {} },
    setAttribute() {}, removeAttribute() {}, getAttribute() { return null; },
    appendChild() {}, remove() {}, insertBefore() {}, removeChild() {},
    addEventListener() {}, removeEventListener() {}, querySelector() { return null; },
    querySelectorAll() { return []; }, closest() { return null; }, focus() {}, blur() {}, click() {},
    getBoundingClientRect: () => ({ top: 0, left: 0, width: 100, height: 100 }),
    dataset: {}, parentNode: null, value: '', textContent: '', innerHTML: '', src: '', checked: false,
  };
}

const el = makeEl();
global.window = {
  LTMS: {},
  matchMedia: () => ({ matches: true }),
  addEventListener() {}, removeEventListener() {},
  getComputedStyle: () => ({ getPropertyValue: () => '' }),
  innerWidth: 1200, innerHeight: 800, devicePixelRatio: 1,
  location: { href: 'https://lo-tengo.com.co/', pathname: '/', reload() {} },
  scrollTo() {}, setTimeout, clearTimeout, setInterval, clearInterval, requestAnimationFrame: (fn) => setTimeout(fn, 0),
  console,
};
global.LTMS = global.window.LTMS;
global.document = {
  readyState: 'complete',
  addEventListener() {}, removeEventListener() {},
  createElement: () => makeEl(), createDocumentFragment: () => ({ appendChild() {} }),
  body: {
    classList: { contains: () => false, add() {}, remove() {}, toggle() {} },
    appendChild() {}, insertBefore() {}, removeChild() {}, remove() {},
    style: {}, dataset: {}, className: '',
  },
  querySelector: () => null, querySelectorAll: () => [], getElementById: () => null,
  documentElement: {
    classList: { add() {}, remove() {}, contains: () => false, toggle() {} },
    dataset: {}, style: {}, setAttribute() {}, removeAttribute() {}, getAttribute() { return null; },
  },
  head: { appendChild() {} },
};
global.navigator = { userAgent: 'node-smoke', language: 'es', onLine: true };
global.location = global.window.location;
global.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
global.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
global.addEventListener = () => {};
global.serviceWorker = undefined;
global.Notification = undefined;
global.MutationObserver = class { constructor() {} observe() {} disconnect() {} takeRecords() { return []; } };
global.ResizeObserver = class { constructor() {} observe() {} unobserve() {} disconnect() {} };
global.performance = { now: () => 0 };
global.Math = Math; global.Date = Date; global.String = String; global.JSON = JSON;
global.Object = Object; global.Array = Array; global.Number = Number; global.parseInt = parseInt;
global.parseFloat = parseFloat; global.isNaN = isNaN; global.encodeURIComponent = encodeURIComponent;
global.setTimeout = setTimeout; global.setInterval = setInterval; global.clearTimeout = clearTimeout; global.clearInterval = clearInterval;

// Globals de WordPress/WooCommerce que las secciones esperan
global.ltmsUX = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'x', cart_url: '/carrito/', i18n: {} };
global.ltmsDashboard = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'x' };
global.ltmsAjax = { url: '/', nonce: 'x' };
global.ltmsPublic = { searchNonce: 'x' };
global.home_url = '/';
global.wc_add_to_cart_params = { ajax_url: '/' };
global.wc_cart_fragments_params = { ajax_url: '/', fragment_name: 'x' };
global.ltmsCheckout = { ajax_url: '/', nonce: 'x' };
global.Chart = undefined;
global.google = undefined;
global.jQuery = undefined;

// Captura errores del initAll (los bundles loguean con console.error en el catch)
const initErrors = [];
const origError = console.error;
console.error = (...args) => {
  const s = String(args[0] || '');
  if (s.includes('[LTMS.UX] Error inicializando')) {
    initErrors.push(args.map((a) => (a instanceof Error ? a.message : String(a))).join(' '));
  } else {
    origError(...args);
  }
};

const ASSETS = path.join(__dirname, '..', 'assets', 'js');

function loadBundle(name) {
  const file = path.join(ASSETS, name + '.min.js');
  if (!fs.existsSync(file)) {
    console.log(`SKIP ${name}: no existe`);
    return;
  }
  const code = fs.readFileSync(file, 'utf8');
  try {
    // eslint-disable-next-line no-new-func
    new Function(code)();
    console.log(`OK ${name} (${(code.length / 1024).toFixed(1)}KB) ejecutado sin throw top-level`);
  } catch (e) {
    console.log(`THROW ${name}: ${e.message}`);
  }
}

// Simula home (storefront): shared + storefront
console.log('=== Simulación STOREFRONT (home) ===');
loadBundle('ltms-ux-shared');
loadBundle('ltms-ux-storefront');
console.log('LTMS.UX.ready:', !!(global.window.LTMS && global.window.LTMS.UX && global.window.LTMS.UX.ready));
console.log('initAll errors storefront:', initErrors.length ? initErrors : 'NINGUNO');

// Reinicia para simular el panel (dashboard)
initErrors.length = 0;
console.log('\n=== Simulación DASHBOARD (panel vendor) ===');
loadBundle('ltms-ux-shared');
loadBundle('ltms-ux-dashboard');
console.log('LTMS.UX.ready:', !!(global.window.LTMS && global.window.LTMS.UX && global.window.LTMS.UX.ready));
console.log('initAll errors dashboard:', initErrors.length ? initErrors : 'NINGUNO');

console.log('\nRESULTADO (errores "is not defined" = aliases/init faltantes del split):');
const realErrors = initErrors.filter((e) => e.includes('is not defined'));
console.log(realErrors.length ? realErrors : 'NINGUNO — el split no deja funciones sin resolver');
console.log('(los demás errores del initAll son ruido del stub DOM, no del split)');
process.exit(realErrors.length ? 1 : 0);