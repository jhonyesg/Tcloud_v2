# Tasks: 4 reglas de bajo riesgo para inglés residual en transcripciones ES

## 1. Preparación

- [ ] Confirmar el `user_id` del administrador que firma el change (sesión actual).
- [ ] Confirmar que las 3 transcripciones siguen en `state='done'` y que `165433`/`165435` NO quedan afectadas.
- [ ] Confirmar que ninguna de las 4 reglas (`the gente`, `for the gente`, `in the celular`, `in this initiative`) ya existe en `corrections` con `status='approved'` (`wrong_normalized` UNIQUE entre approved).

## 2. Insertar las 4 reglas nuevas

- [ ] INSERT `wrong_normalized='the gente'`, `correct_text='la gente'`, `risk_level='low'`, `status='approved'`, `proposed_by=:admin`, `approved_by=:admin`, `approved_at=NOW()`.
- [ ] INSERT `wrong_normalized='for the gente'`, `correct_text='para la gente'`, `risk_level='low'`, `status='approved'`, mismo autor.
- [ ] INSERT `wrong_normalized='in the celular'`, `correct_text='en el celular'`, `risk_level='low'`, `status='approved'`, mismo autor.
- [ ] INSERT `wrong_normalized='in this initiative'`, `correct_text='en esta iniciativa'`, `risk_level='low'`, `status='approved'`, mismo autor.
- [ ] Confirmar 4 filas vía `SELECT count(*) FROM corrections WHERE risk_level='low' AND created_at > NOW() - INTERVAL '1 hour'`.

## 3. Marcar las 3 transcripciones como `needs_review`

- [ ] UPSERT `transcription_reviews` para `transcription_id=165434` con `status='needs_review'`, nota sobre "the gente" / "for the gente" / "in this initiative" en segs 19, 46, 48, 194.
- [ ] UPSERT `transcription_reviews` para `transcription_id=165436` con `status='needs_review'`, nota sobre entrevista bilingüe ES/EN.
- [ ] UPSERT `transcription_reviews` para `transcription_id=165445` con `status='needs_review'`, nota sobre secciones enteras en inglés.
- [ ] Confirmar 3 filas vía `SELECT transcription_id, status, reviewed_at FROM transcription_reviews WHERE transcription_id IN (165434,165436,165445)`.

## 4. Verificación de no-regresión

- [ ] Confirmar que `165433 minutodedios` y `165435 sol` siguen sin fila en `transcription_reviews` (o si existe, sigue en `pending`/`correct`).
- [ ] Confirmar que las 3 transcripciones siguen con `state='done'` (no se cambió accidentalmente).
- [ ] Confirmar que las 4 reglas son recuperables por `Correction::approved()` y serán aplicadas por `CorrectionService::applyToSegments` en próximas transcripciones (sanity check con `Correction::where('wrong_normalized', 'the gente')->first()`).

## 5. Verificación manual en la UI

- [ ] Recargar `/ia/correcciones` → tab "Aprobadas" y confirmar 4 nuevas reglas presentes al final de la lista.
- [ ] Recargar `/ia/correcciones` → tab "Revisión de transcripciones" → modo "Últimas 10" y confirmar que las 3 transcripciones aparecen con badge "Necesita revisión" y la nota visible.

## 6. Auditoría de impacto (1-2 semanas después)

- [ ] Re-correr el script de detección de inglés residual sobre las nuevas transcripciones generadas desde este cambio.
- [ ] Contar `applies_count` de las 4 reglas: si alguna tiene `applies_count=0` tras 50+ transcripciones nuevas, considerar `risk_level='high'` o eliminarla.
- [ ] Verificar que no hay reportes de "la gente" mal corregido en contextos donde la palabra "the" sí es legítima (poco probable pero revisar logs).
