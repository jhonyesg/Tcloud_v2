# Tasks: Revisión manual de transcripciones y correcciones sensibles

## 1. Confirmar contratos y política de riesgo

- [x] Revisar las rutas y relaciones actuales de `CorreccionesController`, `ApiTranscriptorController`, `Transcription` y `TranscriptionSegment`.
- [x] Confirmar el significado operativo de `Últimas 10` y `Últimas 10 sensibles`.
- [x] Verificar todos los caminos que aplican correcciones y decidir la semántica única de `risk_level=high`.
- [x] Documentar cualquier diferencia entre el comportamiento actual y la política esperada antes de tocar código.

## 2. Persistencia del estado de revisión

- [x] Crear migración para `transcription_reviews` con estado, notas, usuario y timestamps.
- [x] Crear modelo `TranscriptionReview` y relación desde `Transcription`.
- [x] Agregar constantes y validación para `pending`, `correct`, `needs_review` e `ignored`.
- [ ] Crear pruebas de unicidad por `transcription_id` y actualización idempotente.

## 3. Servicio de consulta de revisión

- [x] Crear un servicio dedicado, por ejemplo `TranscriptionReviewService`.
- [x] Implementar consulta de las diez transcripciones `done` más recientes.
- [x] Implementar consulta de las diez transcripciones con coincidencias medium/high.
- [x] Contar segmentos modificados sin cargar el contenido completo en la lista.
- [x] Cargar el detalle bajo demanda con segmentos modificados y metadatos del archivo.
- [x] Añadir segmentos vecinos con límites claros.
- [x] Evitar duplicados cuando una transcripción tenga múltiples coincidencias.

## 4. Explicación de coincidencias

- [x] Implementar comparación `text_raw` vs `text`.
- [x] Relacionar diferencias con reglas aprobadas actuales usando límites de palabra y orden por longitud descendente.
- [x] Devolver `exact`, `candidate` o `unknown` según la certeza de la explicación.
- [x] Identificar y devolver `risk_level`, origen e ID de cada regla asociada.
- [ ] Agregar pruebas para frases solapadas, reglas eliminadas y cambios no atribuibles.

## 5. Endpoints y autorización

- [x] Agregar endpoint de lista con `mode=latest|sensitive`.
- [x] Agregar endpoint de detalle por transcripción.
- [x] Agregar endpoint `PATCH` para guardar estado y notas.
- [x] Mantener el grupo existente `auth` + `admin`.
- [x] Validar IDs, estados y longitud máxima de notas.
- [x] Devolver respuestas JSON pequeñas y consistentes.

## 6. Integración de UI en Correcciones

- [x] Agregar pestaña "Revisión de transcripciones" a `app/resources/views/ia/correcciones/index.blade.php`.
- [x] Agregar selector de modos "Últimas 10" y "Últimas 10 sensibles".
- [x] Crear carga AJAX bajo demanda y estados de loading/error/empty.
- [x] Crear tabla desktop y tarjetas responsive para el resumen.
- [x] Crear panel o modal de detalle con original, corregido, reglas y contexto vecino.
- [x] Resaltar coincidencias sensibles sin confundirlas con el texto original.
- [x] Agregar acciones de estado: correcta, necesita revisión e ignorada.
- [x] Mostrar notas y permitir editarlas.
- [x] Enlazar acciones globales de reglas con confirmación explícita.

## 7. Unificar aplicación de reglas sensibles

- [x] Revisar `CorrectionService::applyToSegments()` y el flujo de transcripciones nuevas.
- [x] Revisar la reaplicación retroactiva y el flag `include_high_risk`.
- [x] Revisar previews y uso de `Correction::applyToText()`.
- [x] Hacer que todos los caminos respeten `risk_level=high` por defecto.
- [ ] Agregar pruebas que confirmen que una regla high no se aplica automáticamente y sí se aplica con opt-in explícito.

## 8. Pruebas y verificación

- [ ] Pruebas de servicio para ambos modos de consulta y límite de diez resultados.
- [ ] Pruebas de coincidencias sensibles y deduplicación.
- [ ] Pruebas de actualización del estado de revisión.
- [x] Validar sintaxis PHP de archivos modificados.
- [x] Verificar que las transcripciones sin diferencias todavía puedan revisarse en el modo general.
- [x] Verificar que una transcripción con muchos segmentos no cargue todo el histórico de reglas innecesariamente.
- [ ] Verificar la experiencia en escritorio y móvil.
