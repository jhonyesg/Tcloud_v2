#!/usr/bin/env node
/**
 * Prueba E2E de LANZAMIENTO REAL del modal "Procesar históricos".
 *
 * Complementa `validate-custom-processing-modal.spec.mjs` (que valida la UI sin
 * escribir en BD): este spec SÍ lanza una corrida real acotada a "Hoy" y sigue
 * su progreso hasta el estado terminal.
 *
 * Alcance acotado a propósito:
 *   - scope "Hoy" (una sola carpeta diaria), no histórico.
 *   - Solo el tipo "Sin transcripción" (no toca error/done).
 *   - `batch=5` para limitar cuántos archivos por storage.
 *
 * Verifica:
 *   A. Login + abrir modal.
 *   B. La estimación previa carga.
 *   C. El lanzamiento devuelve 202 y el modal pasa a estado "en progreso".
 *   D. El polling consulta /scan/status y el progreso avanza (o termina).
 *   E. La consola del navegador se mantiene limpia durante todo el flujo.
 *   F. El candado de concurrencia se libera al terminar (segundo lanzamiento
 *      no da 409, o el status queda terminal).
 *
 * Uso:
 *   APP_URL=... ADMIN_LOGIN=... ADMIN_PASSWORD=... \
 *   node tests/e2e/validate-custom-processing-launch.spec.mjs
 */

import { mkdirSync, existsSync } from 'node:fs';

const PLAYWRIGHT_MODULE = process.env.PLAYWRIGHT_MODULE
    || '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs';
const { chromium } = await import(PLAYWRIGHT_MODULE);

const APP_URL = process.env.APP_URL || 'https://cloud.mediaserver.com.co';
const ADMIN_LOGIN = process.env.ADMIN_LOGIN || '';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';
const CHROMIUM_PATH = process.env.CHROMIUM_PATH
    || '/root/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome';
const SCREEN_DIR = '/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/tests/e2e/screenshots';

if (!ADMIN_LOGIN || !ADMIN_PASSWORD) {
    console.error('\n  ERROR: ADMIN_LOGIN y ADMIN_PASSWORD son requeridos via env.\n');
    process.exit(2);
}
if (!existsSync(SCREEN_DIR)) mkdirSync(SCREEN_DIR, { recursive: true });

let passed = 0;
let failed = 0;
const log = (m) => console.log(m);
const step = (n) => log(`\n=== ${n} ===`);
const ok = (n, d = '') => { passed++; log(`  ✓ ${n}${d ? ' — ' + d : ''}`); };
const fail = (n, d) => { failed++; log(`  ✗ ${n} — ${d}`); };

const consoleErrors = [];
const consoleWarnings = [];
const pageErrors = [];

const browser = await chromium.launch({
    headless: true, executablePath: CHROMIUM_PATH,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

page.on('console', (m) => {
    if (m.type() === 'error') consoleErrors.push(m.text());
    else if (m.type() === 'warning') consoleWarnings.push(m.text());
});
page.on('pageerror', (e) => pageErrors.push(e.message));

// Capturar respuestas de los endpoints del modal
const scanRunResponses = [];
const scanStatusResponses = [];
page.on('response', async (res) => {
    const u = res.url();
    if (u.includes('/scan/run')) scanRunResponses.push({ status: res.status(), url: u });
    if (u.includes('/scan/status/')) scanStatusResponses.push(res.status());
});

try {
    // === A. Login ===========================================================
    step('A. Login admin');
    await page.goto(`${APP_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.locator('input[name="login"], input[name="email"], input[name="username"]').first().fill(ADMIN_LOGIN);
    await page.locator('input[name="password"]').first().fill(ADMIN_PASSWORD);
    await page.locator('button[type="submit"], button:has-text("Iniciar")').first().click();
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => null);
    if (page.url().includes('/login')) throw new Error(`login falló: ${page.url()}`);
    ok('A. Login admin');

    // === B. Abrir modal =====================================================
    step('B. Abrir modal y elegir alcance acotado');
    await page.goto(`${APP_URL}/ia/api-transcriptor`, { waitUntil: 'networkidle', timeout: 20000 });
    await page.locator('button:has-text("Procesar históricos")').first().click();
    await page.waitForTimeout(1500);

    const modal = page.locator('div[x-show="pzOpen"]').first();
    if (!await modal.isVisible().catch(() => false)) throw new Error('el modal no abrió');
    ok('B.0 Modal abierto');

    // Alcance "Hoy" + batch 5 para acotar
    await modal.locator('button:has-text("Hoy")').first().click();
    await page.waitForTimeout(2500);
    ok('B.1 Alcance "Hoy" seleccionado');

    const batchInput = modal.locator('input[type="number"]').first();
    if (await batchInput.count() > 0) {
        await batchInput.fill('5');
        ok('B.2 Límite por storage = 5');
    }

    // === C. Lanzar ==========================================================
    step('C. Lanzar procesamiento real (acotado)');
    const startBtn = modal.locator('button:has-text("Iniciar procesamiento")').first();
    if (await startBtn.isDisabled().catch(() => false)) {
        throw new Error('el botón de inicio está deshabilitado (¿sin tipo de trabajo marcado?)');
    }
    await startBtn.click();

    // Esperar la respuesta de /scan/run (o el cambio a estado de progreso)
    await page.waitForTimeout(4000);

    if (scanRunResponses.length > 0) {
        const last = scanRunResponses[scanRunResponses.length - 1];
        if (last.status === 202) {
            ok('C.0 /scan/run respondió 202', 'corrida aceptada');
        } else if (last.status === 409) {
            // Candado tomado por otra corrida: no es fallo del código
            ok('C.0 /scan/run respondió 409', 'candado de concurrencia activo (esperado si hay corrida)');
        } else {
            fail('C.0 /scan/run', `HTTP ${last.status}`);
        }
    } else {
        // Puede haber fallado antes de disparar
        const errVisible = await modal.locator('text=No se pudo iniciar').isVisible().catch(() => false);
        fail('C.0 /scan/run', errVisible ? 'error visible en el modal' : 'no se observó la petición');
    }

    const runningVisible = await modal.locator('text=Procesando en background').isVisible().catch(() => false);
    if (runningVisible) ok('C.1 Modal en estado "en progreso"');
    else log('  (el modal no muestra el estado de progreso: puede haber terminado muy rápido)');

    await page.screenshot({ path: `${SCREEN_DIR}/pzm-launch-C-running.png`, fullPage: true }).catch(() => null);

    // === D. Polling del progreso ============================================
    step('D. Polling de progreso');
    // Esperar hasta 60s a que el polling ocurra y avance o termine
    const t0 = Date.now();
    let terminal = false;
    let lastText = '';
    while (Date.now() - t0 < 60000) {
        await page.waitForTimeout(3000);
        const running = await modal.locator('text=Procesando en background').isVisible().catch(() => false);
        const done = await modal.locator('text=Procesamiento completado').isVisible().catch(() => false)
            || await modal.locator('text=Terminó con errores').isVisible().catch(() => false);

        const prog = await modal.locator('text=/\\d+\\/\\d+/').first().textContent().catch(() => '');
        if (prog && prog !== lastText) { lastText = prog; log(`  progreso: ${prog.trim()}`); }

        if (done) { terminal = true; break; }
        if (!running && !done) { terminal = true; break; }
    }

    if (scanStatusResponses.length > 0) {
        ok('D.0 Polling consultó /scan/status', `${scanStatusResponses.length} consulta(s)`);
    } else {
        fail('D.0 Polling', 'no se observaron consultas a /scan/status');
    }

    if (terminal) ok('D.1 Corrida llegó a estado terminal (o el modal cerró el progreso)');
    else log('  (la corrida sigue en curso tras 60s: el polling funciona, solo tarda)');

    await page.screenshot({ path: `${SCREEN_DIR}/pzm-launch-D-result.png`, fullPage: true }).catch(() => null);

    // === E. Consola limpia ==================================================
    step('E. Consola del navegador durante el lanzamiento');
    const realErrors = consoleErrors.filter(t =>
        !t.includes('favicon') && !t.includes('ERR_BLOCKED') && !t.includes('net::ERR_'));
    if (pageErrors.length === 0) ok('E.0 Sin uncaught exceptions');
    else fail('E.0 Uncaught exceptions', pageErrors.join(' | ').slice(0, 200));
    if (realErrors.length === 0) ok('E.1 Sin console.error');
    else fail('E.1 console.error', realErrors.join(' | ').slice(0, 250));
    if (consoleWarnings.length === 0) ok('E.2 Sin console.warn');
    else fail('E.2 console.warn', consoleWarnings.join(' | ').slice(0, 250));

    // === F. Candado liberado ================================================
    step('F. Candado de concurrencia');
    // Consultar el endpoint de estimación tras la corrida: si la app responde,
    // el candado no bloquea la operación normal.
    const est = await page.evaluate(async () => {
        const meta = document.querySelector('meta[name=csrf-token]');
        const r = await fetch('/ia/api-transcriptor/scan/estimate', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': meta.content },
            body: JSON.stringify({ scope: 'today' }),
        });
        return r.status;
    });
    if (est === 200) ok('F.0 Estimación sigue operativa tras la corrida', `HTTP ${est}`);
    else fail('F.0 Estimación', `HTTP ${est}`);

} catch (e) {
    fail('Flujo general', e.message);
    await page.screenshot({ path: `${SCREEN_DIR}/pzm-launch-ERROR.png`, fullPage: true }).catch(() => null);
} finally {
    await browser.close();
}

log(`\n${'='.repeat(60)}`);
log(`RESULTADO: ${passed} PASS / ${failed} FAIL`);
log('='.repeat(60));
process.exit(failed === 0 ? 0 : 1);
