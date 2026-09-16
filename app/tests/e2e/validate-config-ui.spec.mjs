#!/usr/bin/env node
/**
 * Pruebas E2E (Playwright) para validar el UI de Configuración del módulo
 * API Transcriptor, tras los 4 changes del 2026-09-15:
 *   - purge-redis-words-transcriptor
 *   - transcriptor-pg-native-queue-tests-cleanup
 *   - expose-all-transcriptor-settings-in-config-ui
 *   - enrich-config-ui-transistor-settings (sic; el dir esta bien)
 *
 * Valida el panel /ia/api-transcriptor → Configuración:
 *   A. Login admin + smoke (server responde, no 500)
 *   B. Render: 10 grupos visibles, iconos en grupos + knobs, badges scope/state
 *   C. Acordeón "Ver detalle" expande 3 secciones (alcance, cuando, riesgos)
 *   D. Captura TODOS los warnings/errors de consola (lo que ves en DevTools)
 *      más uncaught page exceptions y failed network requests
 *
 * Requisitos:
 *   - APP_URL accesible (default: https://cloud.mediaserver.com.co).
 *   - Usuario admin (campo "login" acepta email O username — ver auth/login.blade.php).
 *
 * Uso:
 *   APP_URL=https://cloud.mediaserver.com.co \
 *   ADMIN_LOGIN=jsuarez \
 *   ADMIN_PASSWORD='...' \
 *   node tests/e2e/validate-config-ui.spec.mjs
 *
 * Salida:
 *   - PASS/FAIL por escenario en stdout
 *   - Screenshots en tests/e2e/screenshots/validate-*.png
 *   - Reporte JSON en tests/e2e/screenshots/report-config-ui-<ts>.json
 *
 * Credenciales:
 *   - NUNCA hardcodedas. ADMIN_LOGIN y ADMIN_PASSWORD son requeridos via env.
 *     Si faltan, el script aborta con codigo 2.
 *   - El reporte JSON enmascara el email (admin_email) en formato `***@dominio`
 *     para evitar leaks accidentales en CI artifacts.
 */

// Playwright vive en el cache de npx (no hay instalación local en el proyecto).
// Override con PLAYWRIGHT_MODULE si la ruta cambia.
import { mkdirSync, existsSync, writeFileSync } from 'node:fs';

const PLAYWRIGHT_MODULE = process.env.PLAYWRIGHT_MODULE
    || '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs';
const { chromium } = await import(PLAYWRIGHT_MODULE);

// --------------------------------------------------------------------------
// Configuracion via env. Defaults solo para la URL; credenciales obligatorias.
// --------------------------------------------------------------------------
const APP_URL = process.env.APP_URL || 'https://cloud.mediaserver.com.co';
const ADMIN_LOGIN = process.env.ADMIN_LOGIN || '';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';
const CHROMIUM_PATH = process.env.CHROMIUM_PATH
    || '/root/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome';
const SCREEN_DIR = '/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/tests/e2e/screenshots';

if (!ADMIN_LOGIN || !ADMIN_PASSWORD) {
    console.error('');
    console.error('  ERROR: ADMIN_LOGIN y ADMIN_PASSWORD son requeridos via env.');
    console.error('');
    console.error('  Uso:');
    console.error('    APP_URL=https://cloud.mediaserver.com.co \\');
    console.error('    ADMIN_LOGIN=usuario_o_email \\');
    console.error("    ADMIN_PASSWORD='...' \\");
    console.error('    node tests/e2e/validate-config-ui.spec.mjs');
    console.error('');
    process.exit(2);
}

if (!existsSync(SCREEN_DIR)) mkdirSync(SCREEN_DIR, { recursive: true });

// --------------------------------------------------------------------------
// Helpers de salida — mismo patron que files-storages.spec.mjs
// --------------------------------------------------------------------------
let passed = 0;
let failed = 0;
const results = [];
const log = (msg) => console.log(msg);
const step = (name) => log(`\n=== ${name} ===`);
const ok = (name, detail = '') => {
    passed++;
    results.push({ name, status: 'PASS', detail });
    log(`  ✓ ${name}${detail ? ' — ' + detail : ''}`);
};
const fail = (name, detail) => {
    failed++;
    results.push({ name, status: 'FAIL', detail });
    log(`  ✗ ${name} — ${detail}`);
};

async function screenshot(page, name) {
    const path = `${SCREEN_DIR}/${name}.png`;
    try {
        await page.screenshot({ path, fullPage: true });
        log(`  📸 ${path}`);
    } catch (e) {
        log(`  (screenshot skipped: ${e.message})`);
    }
}

// --------------------------------------------------------------------------
// Captura de se\u00f1ales del browser — focus en WARNINGS y ERRORS.
// (El operador ve "warnings y errores" en consola; este es el sensor.)
// --------------------------------------------------------------------------
const consoleWarnings = [];
const consoleErrors = [];
const pageErrors = [];
const failedRequests = [];
const consoleInfo = [];

const browser = await chromium.launch({
    headless: true,
    executablePath: CHROMIUM_PATH,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

page.on('console', (msg) => {
    const text = msg.text();
    const type = msg.type();
    if (type === 'error') consoleErrors.push(text);
    else if (type === 'warning') consoleWarnings.push(text);
    else if (type === 'info') consoleInfo.push(text);
});
page.on('pageerror', (err) => pageErrors.push(err.message));
page.on('requestfailed', (req) => {
    const failure = req.failure();
    failedRequests.push({
        url: req.url(),
        method: req.method(),
        error: failure?.errorText || 'unknown',
    });
});

// === A. Login ===============================================================
step('A.0 Login admin');
try {
    await page.goto(`${APP_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    log(`  url: ${page.url()}`);
    const title = await page.title();
    log(`  title: ${title}`);

    await page.locator('input[name="login"], input[name="email"], input[name="username"]')
        .first().fill(ADMIN_LOGIN);
    await page.locator('input[name="password"]').first().fill(ADMIN_PASSWORD);
    await page.locator('button[type="submit"], button:has-text("Iniciar")').first().click();

    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => null);
    await page.waitForTimeout(800);

    const after = page.url();
    if (after.includes('/login')) {
        await screenshot(page, 'validate-A0-login-failed');
        throw new Error(`login fall\u00f3, sigue en ${after}`);
    }
    ok('A.0 Login admin', `redirigido a ${after.replace(APP_URL, '')}`);
} catch (e) {
    fail('A.0 Login admin', e.message);
    await screenshot(page, 'validate-A0-error');
    log('\n  Server devolvi\u00f3 error antes del login. Abortando suite.');
    await browser.close();
    process.exit(2);
}

// === B. Render del panel de Configuracion ===================================
step('B. UI /ia/api-transcriptor#config');
try {
    await page.goto(`${APP_URL}/ia/api-transcriptor`, { waitUntil: 'networkidle', timeout: 15000 });
    // El tab Configuracion puede requerir click si la pagina es tabbed.
    const tabCandidate = page.locator(
        'a:has-text("Configuraci\u00f3n"), button:has-text("Configuraci\u00f3n"), [data-tour*="cfg-tab"]'
    ).first();
    if (await tabCandidate.count() > 0) {
        await tabCandidate.click().catch(() => null);
        await page.waitForTimeout(800);
    }

    await page.waitForSelector('[data-tour^="cfg-group-"]', { timeout: 10000 });
    const groupCount = await page.locator('[data-tour^="cfg-group-"]').count();
    if (groupCount < 6) {
        throw new Error(`esperaba >= 6 grupos visibles, encontr\u00e9 ${groupCount}`);
    }
    ok('B.0 Render Configuraci\u00f3n', `${groupCount} grupos visibles`);

    const knobCount = await page.locator('[data-tour^="cfg-knob-"]').count();
    log(`  knobs totales: ${knobCount}`);
    if (knobCount < 40) {
        throw new Error(`esperaba >= 40 knobs, encontr\u00e9 ${knobCount}`);
    }
    ok('B.0.b Knobs totales', `${knobCount} knobs`);
} catch (e) {
    fail('B. Render panel', e.message);
    await screenshot(page, 'validate-B-render-failed');
}

// === B.1 Iconos por grupo ===================================================
step('B.1 Iconos por grupo (cfgGroupIcons)');
try {
    const groupLocators = await page.locator('[data-tour^="cfg-group-"]').all();
    let withIcon = 0;
    const missing = [];
    for (const g of groupLocators) {
        const tourAttr = await g.getAttribute('data-tour');
        const groupName = tourAttr.replace('cfg-group-', '');
        const icon = g.locator('i.fas').first();
        const visible = await icon.isVisible().catch(() => false);
        if (visible) {
            withIcon++;
        } else {
            missing.push(groupName);
        }
    }
    if (withIcon < Math.max(6, groupLocators.length - 1)) {
        await screenshot(page, 'validate-B1-no-icons');
        throw new Error(`solo ${withIcon}/${groupLocators.length} grupos con icono; faltantes=${JSON.stringify(missing)}`);
    }
    ok('B.1 Iconos por grupo', `${withIcon}/${groupLocators.length}`);
} catch (e) {
    fail('B.1 Iconos grupo', e.message);
}

// === B.2 Iconos por knob (sample) ============================================
step('B.2 Iconos por knob (muestra 10)');
try {
    const knobLocators = await page.locator('[data-tour^="cfg-knob-"]').all();
    const sample = knobLocators.slice(0, 10);
    let withIcon = 0;
    const missing = [];
    for (const k of sample) {
        const tourAttr = await k.getAttribute('data-tour');
        const knobName = tourAttr.replace('cfg-knob-', '');
        const icon = k.locator('i.fas').first();
        const visible = await icon.isVisible().catch(() => false);
        if (visible) withIcon++; else missing.push(knobName);
    }
    if (withIcon < 8) {
        await screenshot(page, 'validate-B2-no-knob-icons');
        throw new Error(`solo ${withIcon}/10 knobs con icono; faltantes=${JSON.stringify(missing)}`);
    }
    ok('B.2 Iconos por knob', `${withIcon}/10 muestreados`);
} catch (e) {
    fail('B.2 Iconos knob', e.message);
}

// === B.3 Badges scope (LOCAL/REMOTO/MIXTO) ===================================
step('B.3 Badges de scope');
try {
    // dispatch_paused deberia ser LOCAL (es freno local).
    const dp = page.locator('[data-tour="cfg-knob-dispatch_paused"]');
    const dpSpans = await dp.locator('span').allTextContents();
    const dpHasLocal = dpSpans.some((t) => /^\s*LOCAL\s*$/i.test(t));
    if (!dpHasLocal) {
        throw new Error(`dispatch_paused sin badge LOCAL; spans=${JSON.stringify(dpSpans)}`);
    }
    ok('B.3.a LOCAL en dispatch_paused');

    // submit_with_callback deberia ser MIXTO + EXPERIMENTAL.
    const sb = page.locator('[data-tour="cfg-knob-submit_with_callback"]');
    const sbSpans = await sb.locator('span').allTextContents();
    const sbHasMixto = sbSpans.some((t) => /^\s*MIXTO\s*$/i.test(t));
    const sbHasExp = sbSpans.some((t) => /EXPERIMENTAL/i.test(t));
    if (!sbHasMixto) {
        throw new Error(`submit_with_callback sin MIXTO; spans=${JSON.stringify(sbSpans)}`);
    }
    if (!sbHasExp) {
        throw new Error(`submit_with_callback sin EXPERIMENTAL; spans=${JSON.stringify(sbSpans)}`);
    }
    ok('B.3.b MIXTO + EXPERIMENTAL en submit_with_callback');

    // Verificar que LIVE no se renderiza (el unico span "[LIVE]" seria un bug).
    const liveBadges = await page.locator('span:has-text("LIVE")').count();
    if (liveBadges > 0) {
        log(`  (warning: ${liveBadges} badges [LIVE] visibles. Por diseno LIVE no se deberia ver.)`);
    }
} catch (e) {
    fail('B.3 Badges scope', e.message);
}

// === C. Acordeon "Ver detalle" ==============================================
step('C. Acorde\u00f3n inline de detalle');
try {
    // dispatch_paused tiene detail segun schema (ver proposal.md del change).
    const knob = page.locator('[data-tour="cfg-knob-dispatch_paused"]');
    const detailBtn = knob.locator('button:has-text("detalle")');
    if ((await detailBtn.count()) === 0) {
        throw new Error('bot\u00f3n "Ver detalle" no existe en dispatch_paused');
    }

    await detailBtn.click();
    await page.waitForTimeout(600); // x-collapse animation (~250ms)

    const alcance = knob.locator('text=/Alcance/').first();
    const cuando = knob.locator('text=/Cu\u00e1ndo tocar/').first();
    const riesgos = knob.locator('text=/Riesgos/').first();

    const aVis = await alcance.isVisible().catch(() => false);
    const cVis = await cuando.isVisible().catch(() => false);
    const rVis = await riesgos.isVisible().catch(() => false);

    if (!aVis || !cVis || !rVis) {
        await screenshot(page, 'validate-C1-missing-sections');
        throw new Error(`secciones faltantes: alcance=${aVis}, cuando=${cVis}, riesgos=${rVis}`);
    }
    ok('C.1 Tres secciones visibles', 'Alcance, Cu\u00e1ndo tocar, Riesgos');

    await screenshot(page, 'validate-C1-accordion-open');

    // Re-plegar para confirmar toggle.
    await detailBtn.click();
    await page.waitForTimeout(400);
    const stillThere = await alcance.isVisible().catch(() => false);
    if (stillThere) {
        log('  (warning: el acorde\u00f3n no se re-plesga al segundo click)');
    }
} catch (e) {
    fail('C. Acorde\u00f3n', e.message);
}

// === D. Captura de console warnings + errors ===============================
step('D. Captura de warnings/errors de consola');
log(`  console.errors:     ${consoleErrors.length}`);
log(`  console.warnings:   ${consoleWarnings.length}`);
log(`  console.info:       ${consoleInfo.length}`);
log(`  page errors (uncaught): ${pageErrors.length}`);
log(`  failed requests:    ${failedRequests.length}`);

if (consoleWarnings.length > 0) {
    log('\n  WARNINGS:');
    for (const w of consoleWarnings.slice(0, 15)) {
        log(`    \u26a0 ${w.length > 200 ? w.slice(0, 200) + '\u2026' : w}`);
    }
}
if (consoleErrors.length > 0) {
    log('\n  ERRORS:');
    for (const e of consoleErrors.slice(0, 15)) {
        log(`    \u2716 ${e.length > 200 ? e.slice(0, 200) + '\u2026' : e}`);
    }
}
if (pageErrors.length > 0) {
    log('\n  PAGE ERRORS (uncaught):');
    for (const e of pageErrors.slice(0, 10)) {
        log(`    \u274c ${e.length > 200 ? e.slice(0, 200) + '\u2026' : e}`);
    }
}
if (failedRequests.length > 0) {
    log('\n  FAILED REQUESTS:');
    for (const r of failedRequests.slice(0, 10)) {
        log(`    \ud83c\udf10 ${r.method} ${r.url.slice(0, 120)} :: ${r.error}`);
    }
}

// Decidir: page errors (uncaught) son fail duro. Los console.error/warning
// se reportan pero no son fail por si solos — el operador puede verlos y
// decidir si son ruido.
if (pageErrors.length > 0) {
    fail('D.1 Page errors', `${pageErrors.length} uncaught exceptions en la p\u00e1gina`);
} else {
    ok('D.1 Sin uncaught exceptions', `console_errors=${consoleErrors.length}, console_warnings=${consoleWarnings.length}`);
}

// Reporte JSON ----
await browser.close();

const mask = (s) => {
    if (!s) return '';
    if (s.includes('@')) {
        const [u, dom] = s.split('@');
        return `${u.slice(0, 2)}***@${dom}`;
    }
    return `${s.slice(0, 2)}***`;
};

const report = {
    timestamp: new Date().toISOString(),
    app_url: APP_URL,
    admin_login: mask(ADMIN_LOGIN),
    passed,
    failed,
    results,
    console_warnings: consoleWarnings.slice(0, 50),
    console_errors: consoleErrors.slice(0, 50),
    page_errors: pageErrors.slice(0, 50),
    failed_requests: failedRequests.slice(0, 50),
};

const reportPath = `${SCREEN_DIR}/report-config-ui-${Date.now()}.json`;
writeFileSync(reportPath, JSON.stringify(report, null, 2));

log(`\n${'='.repeat(60)}`);
log(`Tests: ${passed} passed, ${failed} failed`);
log(`Reporte: ${reportPath}`);
log('='.repeat(60));

// Exit code 1 si hubo fail; 0 si todo verde.
process.exit(failed > 0 ? 1 : 0);
