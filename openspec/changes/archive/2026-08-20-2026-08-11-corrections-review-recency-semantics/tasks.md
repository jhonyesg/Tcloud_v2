# Tasks: Semántica clara de recencia en revisión de transcripciones

## 1. Contrato y consulta backend

- [x] Definir constantes para los modos `requested`, `completed` y `sensitive`.
- [x] Normalizar `mode=latest` como alias de `requested`.
- [x] Ordenar `requested` por `created_at DESC`, `id DESC`.
- [x] Ordenar `completed` y `sensitive` por `finished_at DESC NULLS LAST`,
  `created_at DESC`, `id DESC`.
- [x] Mantener el filtro `state = done` y el límite backend de diez filas.
- [x] Mantener la consulta sensible con `whereExists` y sin duplicados.
- [x] Devolver siempre `created_at`, `finished_at` y el modo canónico.

## 2. Integración de rutas y frontend

- [x] Actualizar el selector de Correcciones con los tres nombres explícitos.
- [x] Enviar los modos canónicos desde Alpine.js.
- [x] Mostrar solicitud y finalización como fechas separadas en cada fila/tarjeta.
- [x] Añadir una indicación neutral para trabajos con espera prolongada en cola.
- [x] Actualizar el texto de ayuda explicando la diferencia con API Transcriptor.
- [x] Mantener el detalle, las decisiones humanas y los enlaces existentes.
- [x] Revisar el paso correspondiente del tour interactivo de Correcciones: la vista no
  tiene tour propio; la explicación quedó incorporada como ayuda contextual.

## 3. Pruebas

- [x] Agregar pruebas del orden por `created_at` para `requested`.
- [x] Agregar pruebas del orden por `finished_at` para `completed`.
- [x] Agregar pruebas del alias `latest` y del modo devuelto.
- [ ] Agregar pruebas del fallback `finished_at` nulo y desempate por ID.
- [ ] Agregar pruebas de deduplicación para `sensitive`.
- [x] Verificar que trabajos no terminados no entren en ninguna lista revisable.
- [x] Ejecutar validación manual con un trabajo creado el 8 de agosto y terminado
  el 11 de agosto.
- [ ] Verificar la presentación en escritorio y móvil.

## 4. Documentación operativa

- [x] Documentar que API Transcriptor muestra la cola operativa completa.
- [x] Documentar que Correcciones solo muestra trabajos `done`.
- [x] Documentar cómo interpretar la diferencia entre `created_at` y `finished_at`.
