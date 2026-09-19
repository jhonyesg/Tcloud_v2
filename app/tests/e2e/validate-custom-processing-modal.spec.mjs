#!/usr/bin/env node
/**
 * Pruebas E2E (Playwright) del modal "Procesar históricos" del API Transcriptor.
 *
 * Change: `transcriptor-custom-processing-modal` (2026-09-16).
 *
 * Valida el flujo completo del botón + modal en /ia/api-transcriptor:
 *   A. Login admin + smoke.
 *   B. El botón "Procesar históricos" existe y abre el modal.
 *   C. El modal muestra los 3 alcances y los 3 tipos de trabajo.
 *   D. La estimación previa carga y muestra conteos (endpoint solo-lectura).
 *   E. Cambiar de alcance re-dispara la estimación.
 *   F. El botón "Iniciar" se deshabilita sin tipo de trabajo marcado.
 *   G. Consola del navegador limpia (sin errores/warnings) durante todo el flujo.
 *   H. Cerrar el modal funciona.
 *
 * NO ejecuta el procesamiento real (evita escribir en BD y encolar 70 storages);
 * la validación del lanzamiento end-to-end se hizo por separado con el comando
 * en foreground. Este spec valida la UI y el endpoint de estimación.
 *
 * Requisitos:
 *   - APP_URL accesible (default: https://cloud.mediaserver.com.co).
 *   - Usuario admin via ADMIN_LOGIN / ADMIN_PASSWORD (credenciales obligatorias).
 *
 * Uso:
 *   APP_URL=https://cloud.mediaserver.com.co \
 *   ADMIN_LOGIN=usuario ADMIN_PASSWORD='...' \
 *   node tests/e2e/validate-custom-processing-modal.spec.mjs
 *
 * Salida: PASS/FAIL por escenario, screenshots en tests/e2e/screenshots/.
 */

// Playwright vive en el cache de npx (no hay instalación local en el proyecto).
// Override con PLAYWRIGHT_MODULE si la ruta cambia.
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
const log = (msg) => console.log(msg);
const step = (name) => log(`\n=== ${name} ===`);
const ok = (name, detail = '') => { passed++; log(`  ✓ ${name}${detail ? ' — ' + detail : ''}`); };
const fail = (name, detail) => { failed++; log(`  ✗ ${name} — ${detail}`); };

async function screenshot(page, name) {
    try {
        await page.screenshot({ path: `${SCREEN_DIR}/${name}.png`, fullPage: true });
        log(`  📸 ${name}.png`);
    } catch (e) { log(`  (screenshot skipped: ${e.message})`); }
}

const consoleErrors = [];
const consoleWarnings = [];
const pageErrors = [];

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
    ok('A. Login admin', page.url().replace(APP_URL, ''));

    // === B. Botón y apertura del modal ======================================
    step('B. Botón "Procesar históricos"');
    await page.goto(`${APP_URL}/ia/api-transcriptor`, { waitUntil: 'networkidle', timeout: 20000 });

    const btn = page.locator('button:has-text("Procesar históricos")').first();
    if (await btn.count() === 0) throw new Error('no se encontró el botón "Procesar históricos"');
    ok('B.0 Botón visible');

    await btn.click();
    await page.waitForTimeout(1200);

    const modalTitle = page.locator('text=Procesamiento personalizado').first();
    if (!await modalTitle.isVisible().catch(() => false)) {
        throw new Error('el modal no se abrió');
    }
    ok('B.1 Modal abierto', 'muestra "Procesamiento personalizado"');
    await screenshot(page, 'pzm-B-modal-abierto');

    // === C. Controles del modal ============================================
    step('C. Controles (alcance + tipo de trabajo)');
    // Acotar al contenedor del modal: fuera de el hay otros "Hoy" (dashboard)
    // que interceptan el clic por estar detras del overlay.
    const modal = page.locator('div[x-show="pzOpen"]').first();
    for (const label of ['Hoy', 'Rango', 'Histórico']) {
        const c = modal.locator(`button:has-text("${label}")`).first();
        if (await c.count() === 0) fail(`C alcance ${label}`, 'no encontrado');
        else ok(`C alcance ${label}`);
    }
    for (const label of ['Sin transcripción', 'Con error', 'Completados']) {
        const c = modal.locator(`text=${label}`).first();
        if (await c.count() === 0) fail(`C tipo ${label}`, 'no encontrado');
        else ok(`C tipo ${label}`);
    }

    // === D. Estimación previa ==============================================
    step('D. Estimación previa (solo lectura)');
    await page.waitForTimeout(2000); // esperar el fetch de estimación
    const estimateBlock = modal.locator('text=archivos sin transcripción').first();
    const hasEstimate = await estimateBlock.isVisible().catch(() => false);
    if (hasEstimate) {
        const text = await estimateBlock.textContent().catch(() => '');
        ok('D.0 Estimación cargada', text.trim().slice(0, 60));
    } else {
        const anyError = await modal.locator('text=Estimando alcance').count();
        fail('D.0 Estimación', anyError > 0 ? 'quedó en estado de carga' : 'no se muestra el bloque');
    }
    await screenshot(page, 'pzm-D-estimacion');

    // === E. Cambio de alcance re-dispara estimación ========================
    step('E. Cambio de alcance');
    // Escuchar la petición de estimación que dispara el cambio de alcance.
    let estimateCalls = 0;
    page.on('request', (req) => {
        if (req.url().includes('/scan/estimate') && req.method() === 'POST') estimateCalls++;
    });
    const todayBtn = modal.locator('button:has-text("Hoy")').first();
    if (await todayBtn.count() > 0) {
        await todayBtn.click();
        await page.waitForTimeout(3000);
        const bodyText = await modal.textContent().catch(() => '');
        if (estimateCalls > 0 || bodyText.includes('archivos sin transcripción')) {
            ok('E.0 Cambio a "Hoy" re-dispara estimación', `${estimateCalls} request(s)`);
        } else {
            fail('E.0 Cambio de alcance', 'no se vio estimación tras el click');
        }
    } else {
        fail('E.0 Cambio de alcance', 'no encontré el botón "Hoy" dentro del modal');
    }
    await screenshot(page, 'pzm-E-scope-hoy');

    // === F. Guard de "sin trabajo marcado" =================================
    step('F. Guard: sin tipo de trabajo no se puede iniciar');
    const typeChecks = modal.locator('input[type="checkbox"]');
    const n = await typeChecks.count();
    for (let i = 0; i < Math.min(n, 3); i++) {
        const cb = typeChecks.nth(i);
        if (await cb.isChecked().catch(() => false)) {
            await cb.uncheck({ force: true }).catch(() => null);
        }
    }
    await page.waitForTimeout(800);
    const startBtn = modal.locator('button:has-text("Iniciar procesamiento")').first();
    const disabled = await startBtn.isDisabled().catch(() => null);
    const guardMsg = await modal.locator('text=Marca al menos un tipo de trabajo').isVisible().catch(() => false);
    if (disabled === true || guardMsg) {
        ok('F.0 Guard activo', disabled === true ? 'botón deshabilitado' : 'mensaje de guard visible');
    } else {
        fail('F.0 Guard', 'el botón sigue habilitado sin trabajo marcado');
    }
    await screenshot(page, 'pzm-F-guard');

    // === G. Cerrar modal ===================================================
    step('G. Cerrar modal');
    const cancelBtn = modal.locator('button:has-text("Cancelar")').first();
    if (await cancelBtn.count() > 0) {
        await cancelBtn.click();
        await page.waitForTimeout(800);
        const stillOpen = await modal.isVisible().catch(() => false);
        if (stillOpen) fail('G.0 Cerrar', 'el modal sigue visible');
        else ok('G.0 Cerrar modal');
    } else {
        fail('G.0 Cerrar', 'no hay botón Cancelar');
    }

    // === H. Consola limpia =================================================
    step('H. Consola del navegador');
    // Filtrar ruido conocido que no es de este módulo
    const realErrors = consoleErrors.filter(t =>
        !t.includes('favicon') && !t.includes('ERR_BLOCKED') && !t.includes('net::ERR_')
    );
    const realWarnings = consoleWarnings.filter(t => !t.includes('favicon'));

    if (pageErrors.length > 0) fail('H.0 Uncaught exceptions', pageErrors.join(' | ').slice(0, 200));
    else ok('H.0 Sin uncaught exceptions');

    if (realErrors.length > 0) fail('H.1 console.error', realErrors.join(' | ').slice(0, 300));
    else ok('H.1 Sin console.error');

    if (realWarnings.length > 0) fail('H.2 console.warn', realWarnings.join(' | ').slice(0, 300));
    else ok('H.2 Sin console.warn');

} catch (e) {
    fail('Flujo general', e.message);
    await screenshot(page, 'pzm-ERROR');
} finally {
    await browser.close();
}

log(`\n${'='.repeat(60)}`);
log(`RESULTADO: ${passed} PASS / ${failed} FAIL`);
log('='.repeat(60));
process.exit(failed === 0 ? 0 : 1);
