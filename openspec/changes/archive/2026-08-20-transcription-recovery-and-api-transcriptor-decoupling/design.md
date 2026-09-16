# Design

## Cronología del incidente (evidencia)

| Momento | Hecho | Fuente |
|---|---|---|
| 2026-08-18 22:27 | Se escribe la migración del pivote | mtime del archivo |
| 2026-08-18 ~22:40 | Primera corrida y `migrate:rollback` | hueco en el id 77 de `migrations` |
| 2026-08-18 ~22:50 | Segunda corrida (id 78): siembra 0 filas | 310 filas del pivote en `false` |
| 2026-08-18 22:50:17 | Última transcripción creada | `max(created_at)` en `transcriptions` |
| 2026-08-18 → 08-20 | 517 líneas "No hay storages con transcripcion habilitada" | `transcription-tune.log` |
| 2026-08-20 18:07 | Resiembra aplicada, 12 workers arriba | `transcription-tune.log` |

El `down()` de la migración borra el pivote pero **conserva** la bandera derivada ("el rollback no apaga ningún storage"). Si esa bandera ya estaba en `false`, la siembra del `up()` se queda sin fuente. El ciclo rollback → volver a migrar era, por diseño, autodestructivo.

## Reconstrucción del conjunto habilitado

El conjunto previo (39 storages: 36 planos + 3 agrupados, según `transcription-tune.log`) no vivía en ninguna tabla. Se reconstruye desde el historial:

```sql
SELECT DISTINCT f.storage_provider_id
FROM transcriptions t JOIN files f ON f.id = t.file_id
WHERE t.created_at >= '2026-05-20';
```

Da 37; se descarta el id 5 ("00 Discos", raíz `/…/Tcloud` con una sola transcripción suelta del 6 de julio: habilitarlo pondría al scanner a recorrer el disco entero). Quedan **36**. Los 3 que faltan no produjeron nada en 90 días y los reactiva el operador desde la UI.

Los ids se dejan **literales** en la migración: derivarlos con una consulta al vuelo la haría depender de cuánto historial siga vivo cuando corra en otro entorno.

## Modelo de propiedad de la bandera

```
        API TRANSCRIPTOR                 AVISOS / CORRECCIONES
     (decide QUÉ se transcribe)        (consumen lo transcrito)
              │                                   ▲
              ▼                                   │
  storage_providers.transcription_enabled   transcriptions
   (AUTORITATIVA — un solo escritor)         + segmentos
              │                                   ▲
              ├── DiskScannerService ─────────────┘
              ├── TranscriptionTuneCommand (nº de workers)
              └── UI de envío
```

Regla: **un solo escritor**, `ApiTranscriptorController::toggleStorage()`. Nada deriva esta bandera de otra tabla. La dependencia entre módulos es de contenido (Avisos lee transcripciones), no de control.

`user_storages.transcription_enabled` queda en BD sin lectores. No se borra (irreversible) pero la cabecera de su migración la declara muerta.

## Detección: por qué un centinela y no más guardas

Las tres piezas del pipeline informaban correctamente su propio estado; ninguna podía saber que el sistema global estaba muerto. Por eso el centinela **no mira componentes, mira el resultado**: ¿nació alguna fila en `transcriptions` en las últimas N horas?

Sonda barata a propósito: `ORDER BY id DESC LIMIT 1` sobre la PK. No hay índice por `created_at` solo y la tabla ronda las 240k filas — un `WHERE created_at >= X` sería un seq scan cada hora.

El correo es best-effort (sin destinatario, plantilla o SMTP se queda en el WARNING de `laravel.log`): un centinela que revienta por no poder avisar es peor que uno que avisa a medias. Cooldown de 6 h para no repetir el correo durante una caída larga.

## Frontend (Blade + Alpine)

- `ia/api-transcriptor/index.blade.php`: el `<a href="/ia/avisos-inteligentes">` de la columna "Transcripción" vuelve a ser `<button @click="toggleStorage(s)">`. Estado Alpine: `s.saving` como flag por fila; `storageById(id)` resuelve el storage desde la tarjeta de medios sin archivos. Apagar pide `confirm()` — es la acción que detiene el descubrimiento de un canal; encender es directo.
- `ia/avisos-inteligentes/user-detail.blade.php`: el toggle por cliente se sustituye por un badge de solo lectura y se elimina el método `toggleStorage()` del componente.
- El paso del tour guiado ("Estado: Transcripción") pasa a describir un interruptor, no un indicador.

## Alternativas descartadas

- **Mantener la derivación con una guarda de apagado masivo** (estilo `PruneGuard::mass_delete_ratio`). Se implementó y se probó, y luego se retiró con la derivación entera: protege el síntoma pero deja en pie el acoplamiento que lo causó. Menos código es mejor protección que más guardas.
- **Borrar la columna del pivote**: irreversible y sin ganancia; basta con dejarla sin lectores y documentada.
