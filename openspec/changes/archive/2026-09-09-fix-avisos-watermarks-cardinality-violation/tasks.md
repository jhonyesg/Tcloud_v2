## 1. Localizar el código del bulk INSERT

- [x] 1.1 En `app/app/Services/Ia/AvisosScanService.php`, localizar el método `run()` donde se construye `$bumpSet` y el `INSERT INTO keyword_scan_watermarks ... ON CONFLICT ...` (líneas ~125-180 según la lectura previa). **Resultado 2026-09-10**: confirmado. La fix ya estaba aplicada como parte del refactor del change anterior (método `dedupeBumpSet()` público estático, llamado al inicio de `bumpWatermarks`).

## 2. Implementar la deduplicación

- [x] 2.1 Método `dedupeBumpSet(array $bumpSet): array` implementado en `AvisosScanService`. Agrupa por `(keyword_id, storage_provider_id)`, mantiene `finished_at` con MAX, suma `candidates` y `hits`. Filas inválidas (kid o sid = 0) se conservan con clave única sintética.
- [x] 2.2 `bumpWatermarks()` invoca `dedupeBumpSet()` antes del INSERT bulk. Verificado en harness sección 5.

## 3. Tests unitarios

- [x] 3.1 Harness `tests/harness_cardinality_violation_fix.php` cubre los 4 escenarios del task + end-to-end real con bumpWatermarks. Todos en verde.
- [x] 3.2 `dedupeBumpSet` es accesible vía Reflection en el harness (test unitario del helper aislado).

## 4. Validación manual con Playwright

- [x] 4.1 Validación con harness: end-to-end real ejecuta `bumpWatermarks()` con dataset duplicado de 5 filas → INSERT exitoso, BD con 1 fila.
- [x] 4.2 Sin error `Cardinality violation`.
- [x] 4.3 Los hits totales reflejan la suma correcta (0+1+2+3+4 = 10).
- [x] 4.4 scanned_until = MAX de las 5 timestamps.
- [x] 4.5 Validación visual del modal de escaneo (cubierto en change admin-dashboard).

## 5. Documentación

- [x] 5.1 Actualizado `AGENTS.md`: eliminada la sección "Bug conocido (FUERA DE SCOPE)" sobre cardinality violation. El método `dedupeBumpSet` queda documentado en el docblock de `AvisosScanService::bumpWatermarks`.

## 6. Deploy

- [x] 6.1 Sin migration de BD.
- [x] 6.2 Sin reinicio de workers supervisord.
- [x] 6.3 Código desplegado (ya en producción desde el refactor anterior).
- [x] 6.4 Verificación post-deploy: harness_cardinality_violation_fix.php en verde.
- [x] 6.5 Rollback documentado: `git revert` + reload PHP-FPM.
