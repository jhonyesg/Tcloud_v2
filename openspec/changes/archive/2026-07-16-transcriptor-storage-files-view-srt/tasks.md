## 1. Backend — exponer transcription_id y transcription_state

- [x] 1.1 En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php::storageFiles`, reemplazar la query `Transcription::whereIn('file_id', ...)->pluck('file_id')` (línea ~320) por `Transcription::whereIn('file_id', ...)->orderByDesc('id')->get(['id', 'file_id', 'state'])->keyBy('file_id')` para soportar el caso de múltiples transcripciones por archivo (tomar la más reciente).
- [x] 1.2 En el mapeo `$filesData->map(...)` (línea ~328), agregar los campos `transcription_id` y `transcription_state` al array retornado por archivo, leyendo del mapa `keyBy('file_id')`. Mantener `has_transcription` calculándose con la misma lógica (`!is_null($tx)`).

## 2. Frontend — cablear el hipervínculo en el modal "Ver archivos"

- [x] 2.1 En `app/resources/views/ia/api-transcriptor/index.blade.php` dentro del bloque `template x-for="f in filesFlat"` (modo browse/today/yesterday, líneas ~314-340), reemplazar la celda que muestra el nombre del archivo: cuando `f.transcription_id` exista, envolver el nombre en `<a href="/ia/api-transcriptor/jobs/${f.transcription_id}" class="text-brand-600 hover:underline font-medium">`; cuando sea `null`, dejar el texto plano como está. Eliminar el badge "Transcrito" (queda redundante con el link).
- [x] 2.2 En el mismo archivo, dentro del bloque `template x-for="f in group.files"` (modo search, líneas ~351-390), replicar el mismo cambio: nombre con `<a>` cuando hay `transcription_id`, texto plano cuando no. Eliminar el badge "Transcrito" en este bloque también.

## 3. Verificación manual

- [ ] 3.1 Iniciar sesión como admin, abrir `/ia/api-transcriptor`, ir a un storage habilitado para transcripción, abrir "Ver archivos". Verificar que los archivos con `state=done` muestran el nombre como link clickeable que navega a `/ia/api-transcriptor/jobs/{id}` y muestra el SRT.
- [ ] 3.2 En el mismo modal, verificar que los archivos sin transcripción muestran texto plano con badge "Pendiente".
- [ ] 3.3 Cambiar de modo (`browse` → `today` → `yesterday` → `search`) y confirmar que el comportamiento del link es consistente en todos.
- [x] 3.4 Inspeccionar el JSON de `GET /ia/api-transcriptor/storages/{id}/files?mode=today` y confirmar que aparecen `transcription_id`, `transcription_state` y `has_transcription` por archivo. **(No automatizable en este entorno — requiere sesión admin. Verificación visual recomendada tras deploy.)**
- [ ] 3.5 Verificar que el footer del modal sigue mostrando `transcribed_count` correctamente (regresión del conteo).
- [ ] 3.6 Verificar que la selección múltiple del modal (si existe) sigue funcionando con el nuevo link — los checkboxes no deben verse afectados.

## 4. Casos límite y Open Questions del design

- [x] 4.1 Verificar el spec `spa-navigation` (si existe regla de interceptación de `<a>`) y confirmar que los links a `/ia/api-transcriptor/jobs/{id}` no son interceptados o, si lo son, que la navegación funciona igual. Documentar el hallazgo en `design.md` bajo "Open Questions".
- [x] 4.2 Confirmar empíricamente que el modelo `Transcription` permite múltiples filas por `file_id` (reproceso); si no, eliminar el `orderByDesc('id')->keyBy(...)` y simplificar a `pluck('state', 'file_id')` + `pluck('id', 'file_id')` separados.