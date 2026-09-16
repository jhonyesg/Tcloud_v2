# Tasks: Ronda 2 — 12 reglas bulk para inglés residual

## 1. Preparación

- [ ] Confirmar el `user_id` del administrador firmante.
- [ ] Confirmar cuáles de las 12 reglas ya existen (`SELECT wrong_normalized, status FROM corrections WHERE wrong_normalized IN (...)`).
- [ ] Si la ronda 1 (`2026-08-11-corrections-english-residual-rules`) NO se aplicó, ejecutar sus 4 reglas + las 12 de ronda 2 (12 cubre las 4 originales).
- [ ] Si la ronda 1 YA se aplicó, ejecutar solo las que faltan.

## 2. Insertar las 12 reglas nuevas (low + medium)

### LOW RISK (8 nuevas + 2 que podrían existir desde ronda 1)
- [ ] INSERT `wrong_normalized='the gente'`, `correct_text='la gente'`, `risk_level='low'`.
- [ ] INSERT `wrong_normalized='the principal'`, `correct_text='el principal'`, `risk_level='low'`.
- [ ] INSERT `wrong_normalized='the opportunity'`, `correct_text='la oportunidad'`, `risk_level='low'`.
- [ ] INSERT `wrong_normalized='the cosas'`, `correct_text='las cosas'`, `risk_level='low'`.
- [ ] INSERT `wrong_normalized='the monitoreo'`, `correct_text='el monitoreo'`, `risk_level='low'`.
- [ ] INSERT `wrong_normalized='the personas'`, `correct_text='las personas'`, `risk_level='low'`.
- [ ] INSERT `wrong_normalized='ahora is'`, `correct_text='ahora es'`, `risk_level='low'`.
- [ ] INSERT `wrong_normalized='in the same'`, `correct_text='en el mismo'`, `risk_level='low'`.
- [ ] INSERT `wrong_normalized='for the gente'`, `correct_text='para la gente'`, `risk_level='low'`. (puede existir desde ronda 1).
- [ ] INSERT `wrong_normalized='in the celular'`, `correct_text='en el celular'`, `risk_level='low'`. (puede existir desde ronda 1).
- [ ] INSERT `wrong_normalized='in this initiative'`, `correct_text='en esta iniciativa'`, `risk_level='low'`. (puede existir desde ronda 1).

### MEDIUM RISK
- [ ] INSERT `wrong_normalized='the authorities'`, `correct_text='las autoridades'`, `risk_level='medium'`.
- [ ] INSERT `wrong_normalized='the information'`, `correct_text='la información'`, `risk_level='medium'`.

## 3. Verificación post-INSERT

- [ ] `SELECT count(*)` de las reglas afectadas: debe haber entre 8 y 12 nuevas filas (depende de cuánto de ronda 1 ya estaba).
- [ ] `SELECT wrong_normalized, correct_text, risk_level FROM corrections WHERE created_at > NOW() - INTERVAL '1 hour' AND wrong_normalized IN (...lista 12...)` muestra todas las presentes.
- [ ] Confirmar que NO se insertaron las high-risk (`the`, `and`, `for`, `i think`, `you know`, etc.) vía `SELECT count(*) FROM corrections WHERE wrong_normalized IN ('the','and','for','i think','you know')` → debe ser 0.

## 4. Verificación funcional

- [ ] Aplicación sintética: `Correction::applyToText('the gente que nos escucha')` retorna `'la gente que nos escucha'`.
- [ ] Aplicación sintética medium: `Correction::applyToText('the authorities maintain')` retorna `'las autoridades maintain'` (con `includeHighRisk=false`, las medium sí se aplican; con false, también).
- [ ] Esperar próxima transcripción con "the gente..." en audio; abrir `/ia/api-transcriptor/jobs/{id}` y comparar `text_raw` vs `text` para confirmar.

## 5. Verificación de UI

- [ ] `/ia/correcciones` → tab "Aprobadas" muestra las nuevas al final con `applies_count=0`.
- [ ] `/ia/correcciones` → tab "Contexto sensible" muestra las 2 medium (`the authorities`, `the information`).

## 6. No-regresión

- [ ] NO se crearon filas para `the`, `and`, `for`, `i think`, `you know`, `i mean`, `in the world`.
- [ ] NO se tocaron las 126 transcripciones con `corrected=-1`.
- [ ] NO se modificaron las reglas existentes (solo INSERTs nuevos).

## 7. Auditoría de impacto (1 semana después)

- [ ] `SELECT wrong_normalized, applies_count FROM corrections WHERE id IN (...ids nuevas...) ORDER BY applies_count DESC` — identificar reglas con >100 aplicaciones (éxito) vs <5 (candidatas a degradar o eliminar).
- [ ] Revisar manualmente 10 segmentos aleatorios por cada regla con >50 aplicaciones para detectar falsos positivos.
- [ ] Si alguna regla low produce falsos positivos recurrentes: degradar a `medium` o `high` con UPDATE; o eliminar (status='rejected', no borrar fila).
- [ ] Medir ratio global: `SELECT sum(case when text_raw != text then 1 else 0 end) * 100.0 / count(*) FROM transcription_segments WHERE transcription_id IN (SELECT id FROM transcriptions WHERE finished_at > NOW() - INTERVAL '1 hour' AND state='done')` — debería subir significativamente respecto al 0.91% baseline.
