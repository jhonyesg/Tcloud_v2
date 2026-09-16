## 1. Backend (ShareController)

- [x] 1.1 En `verifyAvailability`, leer `after_id` (nullable int) del request y aplicar `where('shares.id', '>', $afterId)` cuando venga.
- [x] 1.2 Respetar el `orderBy` ya configurado por `shareQuery` para operaciones `all_matching`: eliminar el `->reorder('shares.id')` de `selectedSharesQuery` solo cuando `all_matching` es true. Mantenerlo cuando vienen `ids[]` explícitos.
- [x] 1.3 Incluir en la respuesta `next_cursor` (último id procesado) y mantener `has_more` calculado sobre `count() >= batchLimit`. Devolver también `checked` (ya viene del servicio).

## 2. Frontend (shares/index.blade.php)

- [x] 2.1 Reescribir `verifyAllFiltered()` para iterar con `next_cursor`: enviar `after_id`, acumular `processed` y cortar cuando `has_more === false`.
- [x] 2.2 Mantener la UX actual (toast incremental con `processed/total`, mensaje final) sin agregar controles nuevos.
- [x] 2.3 No tocar `verifySelected()` (opera sobre IDs explícitos, no requiere cursor).

## 3. Validación

- [x] 3.1 Caso A (sin filtro, 146 shares): primer batch trae 50, `has_more=true`, cursor avanza; segundo batch trae los restantes, `has_more=false`. Procesamiento completo en 2 iteraciones.
- [x] 3.2 Caso B (filtro `availability=unknown`, 25 matches): un solo batch, `has_more=false`. No se itera de más.
- [x] 3.3 Caso C (filtro `permission=write`, 3 matches): un solo batch, `has_more=false`. Procesa exactamente los 3.
- [x] 3.4 Orden visible: SQL generado respeta `created_at DESC, shares.id DESC` por defecto y, al combinar con `permission=write`, mantiene el orden de `shareQuery`.

## 4. Cierre

- [x] 4.1 `openspec validate shares-verify-availability-bulk-pagination --strict`.
- [ ] 4.2 Archivar el change.
