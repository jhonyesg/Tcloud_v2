## 1. Backup lógico de las 8 filas

- [x] 1.1 Crear tabla temporal `_overreach_backup_2026_08_15` con las 8 filas afectadas (ids 2990, 2991, 2992, 3003, 2263, 3094, 253, 261) usando `CREATE TABLE _overreach_backup_2026_08_15 AS SELECT * FROM corrections WHERE id IN (...)`
- [x] 1.2 Verificar `SELECT COUNT(*) FROM _overreach_backup_2026_08_15` retorne 8

## 2. Rechazar las 6 reglas sin artículo

- [x] 2.1 `UPDATE corrections SET status='rejected', rejected_reason='overreach-of-mapping-2026-08-15', updated_at=NOW() WHERE id IN (2991, 2990, 2992, 3003, 2263, 3094)`
- [x] 2.2 Verificar `SELECT id, status, rejected_reason FROM corrections WHERE id IN (2991, 2990, 2992, 3003, 2263, 3094)` retorne las 6 filas con `status='rejected'` y `rejected_reason` correcto

## 3. Corregir las 2 reglas con género mal

- [x] 3.1 `UPDATE corrections SET correct_text='del cambio', updated_at=NOW() WHERE id=253`
- [x] 3.2 `UPDATE corrections SET correct_text='del agua', updated_at=NOW() WHERE id=261`
- [x] 3.3 Verificar `SELECT id, correct_text FROM corrections WHERE id IN (253, 261)` retorne los nuevos `correct_text`

## 4. Verificación final

- [x] 4.1 Confirmar que las 6 reglas rechazadas NO aparecen en `SELECT id FROM corrections WHERE status='approved' AND wrong_normalized IN ('of love', 'of colombia', 'of security', 'of bogotá', 'of melanoma', 'of emergency')`
- [x] 4.2 Confirmar que las 2 reglas corregidas siguen en `status='approved'` con `correct_text='del cambio'` y `correct_text='del agua'`
- [x] 4.3 Confirmar que las reglas `of the world`, `of the year`, etc. (KNOWN_EN_ES_MAPPINGS, `wrong_normalized` empieza con `of the `) NO fueron afectadas: `SELECT COUNT(*) FROM corrections WHERE status='approved' AND wrong_normalized LIKE 'of the %'` debe retornar el conteo previo (~16 reglas)
- [x] 4.4 Listar las reglas `of %` aprobadas que quedan en el diccionario para que el admin las revise: `SELECT id, wrong_text, correct_text, applies_count FROM corrections WHERE status='approved' AND wrong_normalized LIKE 'of %' ORDER BY applies_count DESC`

## 5. Documentación post-deploy

- [x] 5.1 Añadir entrada en `project.md` (memory) con la decisión de no aceptar reglas genéricas `of X → de X` sin artículo en futuros ciclos auto