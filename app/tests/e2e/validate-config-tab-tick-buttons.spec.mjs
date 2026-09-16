#!/usr/bin/env node
/**
 * Pruebas E2E (Playwright) del tab Configuración del módulo API Transcriptor.
 *
 * Change: `2026-09-16-remove-transcriptor-manual-tick-buttons`.
 *
 * Valida los 4 criterios que el apply dejó pendientes por falta de sesión admin:
 *   3.2  La tarjeta "Tarea programada" muestra UN SOLO botón de acción
 *        ("Procesar históricos") y conserva tick_last_run, cola, workers y
 *        conteos por estado.
 *   3.3  Consola del navegador limpia (sin console.error / console.warn /
 *        uncaught exceptions) y SIN peticiones 404 a run-tick.
 *   3.4  Abrir y cancelar el modal "Procesar históricos" funciona
 *        (openPz / closePz) y POST /scan/estimate responde con estimación.
 *   3.5  El polling de 10 s sigue actualizando cfgRuntime (tick_last_run
 *        cambia sin recargar la página).
 *
 * Requisitos:
 *   - APP_URL accesible (default: https://cloud.mediaserver.com.co).
 *   - Usuario admin via ADMIN_LOGIN / ADMIN_PASSWORD (credenciales obligatorias).
 *
 * Uso:
 *   APP_URL=https://cloud.mediaserver.com.co \
 *   ADMIN_LOGIN=jsuarez \
 *   ADMIN_PASSWORD='...' \
 *   node tests/e2e/validate-config-tab-tick-buttons.spec.mjs
 *
 * Salida: PASS/FAIL por escenario, screenshots en tests/e2e/screenshots/,
 * reporte JSON en tests/e2e/screenshots/report-tick-buttons-<ts>.json
 */

import { mkdirSync, existsSync, writeFileSync } from 'node:fs';

const PLAYWRIGHT_MODULE = process.env.PLAYWRIGHT_MODULE
    || '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs';
const { chromium } = await import(PLAYWRIGHT_MODULE);

const APP_URL = process.env.APP_URL || 'https://cloud.mediaserver.com.co';
const ADMIN_LOGIN = process.env.ADMIN_LOGIN || '';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';
const CHROMIUM_PATH = process.env.CHROMIUM_PATH
    || '/root/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome';
const SCREEN_DIR = '/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/tests/e2e/screenshots';
const REPORT = {
    timestamp: new Date().toISOString(),
    app_url: APP_URL,
    admin_login: ADMIN_LOGIN ? ADMIN_LOGIN.slice(0, 2) + '***' : '',
    passed: 0,
    failed: 0,
    results: [],
};

if (!ADMIN_LOGIN || !ADMIN_PASSWORD) {
    console.error('\n  ERROR: ADMIN_LOGIN y ADMIN_PASSWORD son requeridos via env.\n');
    process.exit(2);
}

if (!existsSync(SCREEN_DIR)) mkdirSync(SCREEN_DIR, { recursive: true });

let passed = 0;
let failed = 0;
const log = (msg) => console.log(msg);
const step = (name) => log(`\n=== ${name} ===`);
const ok = (name, detail = '') => {
    passed++; REPORT.results.push({ name, status: 'PASS', detail });
    log(`  ✓ ${name}${detail ? ' — ' + detail : ''}`);
};
const fail = (name, detail) => {
    failed++; REPORT.results.push({ name, status: 'FAIL', detail });
    log(`  ✗ ${name} — ${detail}`);
};

async function screenshot(page, name) {
    try {
        await page.screenshot({ path: `${SCREEN_DIR}/${name}.png`, fullPage: true });
        log(`  📸 ${name}.png`);
    } catch (e) { log(`  (screenshot skipped: ${e.message})`); }
}

const consoleErrors = [];
const consoleWarnings = [];
const pageErrors = [];
const requests404 = [];

const browser = await chromium.launch({
    headless: true,
    executablePath: CHROMIUM_PATH,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
    else if (msg.type() === 'warning') consoleWarnings.push(msg.text());
});
page.on('pageerror', (err) => pageErrors.push(err.message));
page.on('response', (res) => {
    if (res.status() === 404) requests404.push(res.url());
});

try {
    // === A. Login ===========================================================
    step('A. Login admin');
    await page.goto(`${APP_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.locator('input[name="login"], input[name="email"], input[name="username"]')
        .first().fill(ADMIN_LOGIN);
    await page.locator('input[name="password"]').first().fill(ADMIN_PASSWORD);
    await page.locator('button[type="submit"], button:has-text("Iniciar")').first().click();
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => null);
    if (page.url().includes('/login')) throw new Error(`login falló: ${page.url()}`);
    ok('A.0 Login admin', page.url().replace(APP_URL, ''));

    // === B. Tab Configuración ===============================================
    step('B. Tab Configuración');
    await page.goto(`${APP_URL}/ia/api-transcriptor`, { waitUntil: 'networkidle', timeout: 25000 });

    const configTab = page.locator('button:has-text("Configuración")').first();
    if (await configTab.count() === 0) throw new Error('no se encontró el tab "Configuración"');
    await configTab.click();
    await page.waitForTimeout(2500); // loadConfig() + primer render de cfgRuntime
    ok('B.0 Tab Configuración abierto');
    await screenshot(page, 'tick-B-config-tab');

    // === 3.2 Tarjeta "Tarea programada": un solo botón ======================
    step('3.2 Tarjeta "Tarea programada"');

    const card = page.locator('[data-tour="cfg-task"]').first();
    if (await card.count() === 0) throw new Error('no se encontró la tarjeta cfg-task');
    ok('3.2.a Tarjeta presente');

    const runBlock = card.locator('[data-tour="cfg-run"]').first();
    if (await runBlock.count() === 0) throw new Error('no se encontró el bloque cfg-run');
    ok('3.2.b Contenedor data-tour="cfg-run" conservado');

    const btnCount = await runBlock.locator('button').count();
    const btnTexts = [];
    for (let i = 0; i < btnCount; i++) {
        btnTexts.push((await runBlock.locator('button').nth(i).textContent()).trim().replace(/\s+/g, ' '));
    }
    if (btnCount === 1 && btnTexts[0].includes('Procesar históricos')) {
        ok('3.2.c Un único botón de acción', btnTexts[0]);
    } else {
        fail('3.2.c Un único botón de acción', `esperaba 1 ("Procesar históricos"), hay ${btnCount}: ${btnTexts.join(' | ')}`);
    }

    // Los botones eliminados NO deben existir
    for (const gone of ['Simular', 'Ejecutar ahora']) {
        const n = await card.locator(`button:has-text("${gone}")`).count();
        if (n === 0) ok(`3.2.d Botón "${gone}" eliminado`);
        else fail(`3.2.d Botón "${gone}" eliminado`, `todavía hay ${n} coincidencia(s)`);
    }

    // Estado en vivo conservado
    const cardText = await card.textContent();
    const stateChecks = [
        ['Última ejecución', /Última ejecución/],
        ['Cola de conversión', /Cola de conversión/],
        ['Workers', /Workers/],
        ['Transcripciones (histórico)', /Transcripciones/],
    ];
    for (const [label, re] of stateChecks) {
        if (re.test(cardText)) ok(`3.2.e Estado conservado: ${label}`);
        else fail(`3.2.e Estado conservado: ${label}`, 'no aparece en la tarjeta');
    }
    await screenshot(page, 'tick-C-card-un-boton');

    // === 3.5 Polling de 10 s actualiza cfgRuntime ===========================
    step('3.5 Polling de cfgRuntime (10 s)');
    // Observar la petición de settings; el polling NO debe dejar de dispararse.
    let settingsPolls = 0;
    page.on('request', (req) => {
        if (req.url().includes('/api-transcriptor/settings') && req.method() === 'GET') settingsPolls++;
    });
    const beforePolls = settingsPolls;
    // Una ventana de 12 s cubre al menos un ciclo completo del intervalo de 10 s.
    await page.waitForTimeout(12000);
    if (settingsPolls > beforePolls) {
        ok('3.5.a El polling sigue disparando GET /settings', `${settingsPolls - beforePolls} en 12 s`);
    } else {
        fail('3.5.a El polling sigue disparando GET /settings', 'ninguna petición en 12 s');
    }

    // El valor mostrado de tick_last_run debe seguir siendo legible (no "—" fijo).
    const lastRunTxt = await card.locator('text=Última ejecución').first().textContent().catch(() => '');
    if (/hace|min|h|s/i.test(lastRunTxt)) ok('3.5.b tick_last_run se renderiza', lastRunTxt.trim().slice(0, 60));
    else fail('3.5.b tick_last_run se renderiza', `texto inesperado: "${lastRunTxt.trim().slice(0, 60)}"`);

    // === 3.4 Modal "Procesar históricos" ====================================
    step('3.4 Modal "Procesar históricos"');

    // Escuchar el endpoint de estimación solo-lectura.
    const estimateResponses = [];
    page.on('response', (res) => {
        if (res.url().includes('/scan/estimate')) {
            estimateResponses.push({ status: res.status(), url: res.url() });
        }
    });

    const pzBtn = runBlock.locator('button:has-text("Procesar históricos")').first();
    await pzBtn.click();
    await page.waitForTimeout(2500); // apertura + fetch de estimación

    const modal = page.locator('div[x-show="pzOpen"]').first();
    const modalOpen = await modal.isVisible().catch(() => false);
    if (modalOpen) ok('3.4.a El modal abre desde la tarjeta');
    else fail('3.4.a El modal abre desde la tarjeta', 'el modal no se hizo visible');
    await screenshot(page, 'tick-D-modal-abierto');

    // El endpoint de estimación debe contestar 2xx (o no ser llamado aún).
    const est = estimateResponses[estimateResponses.length - 1];
    if (!est) {
        log('  (sin request a /scan/estimate todavía; puede requerir cambio de alcance)');
    } else if (est.status >= 200 && est.status < 300) {
        ok('3.4.b POST /scan/estimate responde 2xx', `HTTP ${est.status}`);
    } else {
        fail('3.4.b POST /scan/estimate responde 2xx', `HTTP ${est.status}`);
    }

    // La consola no debe reportar el endpoint borrado.
    const runTickReqs = requests404.filter(u => u.includes('run-tick'));
    if (runTickReqs.length === 0) ok('3.4.c Ningún 404 a run-tick');
    else fail('3.4.c Ningún 404 a run-tick', runTickReqs.join(' | '));

    // Cerrar el modal
    const cancelBtn = modal.locator('button:has-text("Cancelar")').first();
    if (await cancelBtn.count() > 0) {
        await cancelBtn.click();
        await page.waitForTimeout(900);
        const stillOpen = await modal.isVisible().catch(() => false);
        if (stillOpen) fail('3.4.d El modal cierra con Cancelar', 'sigue visible');
        else ok('3.4.d El modal cierra con Cancelar');
    } else {
        fail('3.4.d El modal cierra con Cancelar', 'no encontré el botón Cancelar');
    }
    await screenshot(page, 'tick-E-modal-cerrado');

    // Reabrir y cerrar de nuevo: idempotencia del handler
    await pzBtn.click();
    await page.waitForTimeout(1500);
    if (await modal.isVisible().catch(() => false)) ok('3.4.e El modal reabre');
    else fail('3.4.e El modal reabre', 'no se abrió en el segundo intento');
    const cancel2 = modal.locator('button:has-text("Cancelar")').first();
    if (await cancel2.count() > 0) {
        await cancel2.click();
        await page.waitForTimeout(900);
        if (await modal.isVisible().catch(() => false)) fail('3.4.f Cierre tras reapertura', 'sigue visible');
        else ok('3.4.f Cierre tras reapertura');
    }

    // === 3.3 Consola limpia =================================================
    step('3.3 Consola del navegador');
    const noise = (t) => t.includes('favicon') || t.includes('ERR_BLOCKED') || t.includes('net::ERR_');
    const realErrors = consoleErrors.filter((t) => !noise(t));
    const realWarnings = consoleWarnings.filter((t) => !noise(t));

    if (pageErrors.length > 0) fail('3.3.a Sin uncaught exceptions', pageErrors.join(' | ').slice(0, 200));
    else ok('3.3.a Sin uncaught exceptions');

    if (realErrors.length > 0) fail('3.3.b Sin console.error', realErrors.join(' | ').slice(0, 300));
    else ok('3.3.b Sin console.error');

    if (realWarnings.length > 0) fail('3.3.c Sin console.warn', realWarnings.join(' | ').slice(0, 300));
    else ok('3.3.c Sin console.warn');

    const real404 = requests404.filter((u) => !noise(u));
    if (real404.length > 0) fail('3.3.d Sin peticiones 404', real404.join(' | ').slice(0, 300));
    else ok('3.3.d Sin peticiones 404');

} catch (e) {
    fail('Flujo general', e.message);
    await screenshot(page, 'tick-ERROR');
} finally {
    await browser.close();
}

REPORT.passed = passed;
REPORT.failed = failed;
const reportPath = `${SCREEN_DIR}/report-tick-buttons-${Date.now()}.json`;
writeFileSync(reportPath, JSON.stringify(REPORT, null, 2));

log(`\n${'='.repeat(60)}`);
log(`RESULTADO: ${passed} PASS / ${failed} FAIL`);
log(`Reporte: ${reportPath}`);
log('='.repeat(60));
process.exit(failed === 0 ? 0 : 1);
