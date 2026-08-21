# Tasks: Idioma esperado por canal

Orden pensado para poder parar entre pasos sin dejar nada a medias.

## 1. Base de datos

- [ ] Migración `create_channel_languages_table`: `slug` (varchar 80, único),
      `label` (nullable), `language` (varchar 8, default `es`),
      `apply_corrections` (boolean, default `true`), `notes` (nullable), timestamps.
- [ ] Modelo `App\Models\ChannelLanguage` con `$fillable` y constantes
      `LANG_ES` / `LANG_EN` / `LANG_MIXED`.

## 2. Extracción del slug

- [ ] `ChannelSlug::fromFilename(string $originalName): ?string` — servicio o
      método estático. Cubre las dos convenciones:
      `teleisla_13082026_...` → `teleisla`
      `15_abc_atlantico_19072026_...` → `abc_atlantico`
      Regla: quitar extensión → quitar `^\d+_` → cortar en el primer token de 8
      dígitos → unir el resto con `_` → minúsculas.
- [ ] Tests unitarios de las dos convenciones, del caso sin fecha y del nulo.

## 3. Poblado inicial

- [ ] Comando `channels:sync-languages` que recorre los `original_name` distintos
      de `transcriptions`, extrae slugs y crea las filas que falten con
      `language = 'es'` (el default seguro).
- [ ] Marcar a mano los tres conocidos:
      `teleisla` → `en`, `apply_corrections = false`, nota "criollo raizal de San Andrés"
      `uniminuto` → `mixed`, `apply_corrections = false`, nota "música en inglés"
      `lafmplus` → `mixed`, `apply_corrections = false`, nota "música en inglés"
- [ ] El comando debe ser idempotente: no pisa lo que un humano ya ajustó.

## 4. Puntos de uso

- [ ] `CorrectionService::applyRetroactively()` — excluir transcripciones de
      canales con `apply_corrections = false`. Ojo al rendimiento: resolver los
      slugs excluidos UNA vez y filtrar por `transcription_id`, nunca por
      subconsulta sobre `transcription_segments` (8,3 GB).
- [ ] `CorrectionService::applyToSegments()` — mismo criterio en el camino de
      ingesta (`TranscriptionProcessor`).
- [ ] `EnglishResidualSegmentDetector` — no marcar `needs_review` en canales no
      españoles.
- [ ] `CycleSuggestionsCommand` y `EnEsMixMiner` — no extraer candidatos de esos
      canales.

## 5. UI (opcional, si da tiempo)

- [ ] Pestaña o sección en `/ia/correcciones` para ver y editar el idioma por
      canal. Sin esto se administra por comando, que para 64 filas es aceptable.

## 6. Verificación

- [ ] `php -l` de todo lo tocado; suite completa.
- [ ] `channels:sync-languages --dry-run` sobre producción: debe encontrar 64 slugs.
- [ ] Re-medir el residuo excluyendo canales no españoles y comprobar que baja.
- [ ] Confirmar con `EXPLAIN` que el filtro por canal no introduce ningún scan
      sobre `transcription_segments`.

## Contexto imprescindible para retomar

- **Nada de full-scans sobre `transcription_segments`** (20,6M filas / 8,3 GB, y
  `created_at` sin índice). Para buscar por contenido, el único camino es el
  índice GIN trigram `idx_transcription_segments_text_gin`, que está sobre la
  columna `text`, **no** sobre `text_raw`.
- El diccionario **no traduce**: corrige español. Ver
  `openspec/changes/2026-08-13-corrections-loose-word-pruning/` y
  `.../2026-08-13-corrections-min-phrase-length/`.
- **Ninguna regla de una sola palabra**: se borraron las 306 que quedaban. El
  mínimo para los productores automáticos es 3 palabras
  (`corrections.min_suggestion_words`).
- Estado del diccionario tras la limpieza: 126 reglas activas (todas de 2+
  palabras), 2.534 en cuarentena, 0 pendientes.
- Quedan **21 reglas activas de 2 palabras** con 4.445 aplicaciones, cinco de las
  cuales dejan inglés en el resultado (`of security` → `de security`). Decisión
  pendiente del usuario.
- Para leer contexto de una corrección: `/ia/correcciones` → "Ver ejemplos".
