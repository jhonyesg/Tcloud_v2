## Why

El admin /ia/avisos-inteligentes/{userId} sigue consultando la tabla legacy `keyword_matches` (vacía desde la fase-1 de mis-avisos) y renderiza los matches con `@foreach($matches as $match)` — un row por mención real. Eso significa:

1. La vista admin muestra **0 matches** para todos los clientes aunque tengan hits reales en `segment_keyword_hits` (tabla de phase-1).
2. La vista NO está agrupada por (archivo + keyword), así que cuando se llene de datos reproducirá el mismo "incoherente" de "filename, mention, filename, mention" que el cliente sufría antes del fix de Histórico.

Adicionalmente, el motor de matching tiene una idempotencia per-transcription que provoca que las keywords **nuevas** (registradas después de la primera tanda) **nunca se escaneen contra las 359K transcripciones existentes** que ya tenían hits de otras keywords. Resultado: 11 de las keywords que el cliente Punto tiene registradas devuelven 0 hits en Histórico, y el resto del listado admin también está vacío porque la tabla `segment_keyword_hits` está incompleta para keywords tardías.

## What Changes

Tres fases incrementales, de menos a más agresivas:

### Fase 1 — Admin matches alineado con cliente (UI + data source)
- Reemplazar `User::keywordMatches()->paginate(25)` por `MentionsSearchService::visibleHitsQuery($user)->paginate(25)` en `AvisosInteligentesController::show()` y `::matches()`.
- En `user-detail.blade.php`, agrupar el resultado por `(transcription_id, keyword_id)` server-side, exactamente con la misma semántica "filename, keyword, ×N, expand para ver cada mención". Usar una nueva sección renderizada con `@foreach` que produce groups, y dentro de cada group las menciones individuales ocultas tras un checkbox/details.
- Eliminar la dependencia del modelo legacy `KeywordMatch` y de la tabla `keyword_matches` en este flujo de UI.

### Fase 2 — Idempotencia per-(transcription, keyword)
- En `KeywordMatcher::run()`, cambiar el check de "ya procesado":
  ```php
  $already = DB::table('segment_keyword_hits')
      ->where('transcription_id', $transcription->id)
      ->whereIn('keyword_id', $keywordIdByNorm->values())
      ->exists();
  ```
  Antes (per-transcription):
  ```php
  $already = DB::table('segment_keyword_hits')
      ->where('transcription_id', $transcription->id)
      ->exists();
  ```
- Una transcripción que ya tiene hit para keyword "Petro" se vuelve a escanear cuando se añade keyword "AV Villas". El motor filtra por keyword_id en su carga de candidatos.
- UNIQUE constraint `(transcription_id, segment_id, keyword_id)` en `segment_keyword_hits` blinda contra duplicados aún si el matcher se corre múltiples veces.

### Fase 3 — Backfill de keywords con 0 hits y auto-backfill al crear
- Nuevo comando artisan `mentions:backfill-keyword {--keyword=ID} {--all}` que escanea las transcripciones accesibles para una keyword (o todas las que tengan 0 hits) usando la misma lógica del matcher.
- Reemplaza el actual backfill manual que el cliente pide cada vez que agrega keyword — ahora corre en background automáticamente al crear una keyword vía `MisAvisosController::storeKeyword`.
- Insert idempotente vía `insertOrIgnore` con el UNIQUE constraint ya existente.

## Capabilities

### New Capabilities
- `admin-matches-table`: la vista `ia/avisos-inteligentes/{userId}` muestra los matches del cliente agrupados por (archivo + keyword), con la misma UX que el cliente ve en Histórico.

### Modified Capabilities
_Ninguna._ Las capacidades existentes (mis-avisos-admin-preview, avisos-keyword-categories) siguen funcionando; este change agrega una nueva capacidad para el módulo admin y repara el motor sin cambiar comportamiento observable de las otras.

## Impact

- **Migración**: ninguna.
- **Controllers**: `app/Http/Controllers/Ia/AvisosInteligentesController.php` (2 métodos: `show()`, `matches()`).
- **Blade**: `resources/views/ia/avisos-inteligentes/user-detail.blade.php` (sección Matches, ~30 líneas reescritas).
- **Service**: `app/Services/Ia/KeywordMatcher.php` (1 método: `run()`).
- **Service nuevo**: `app/Services/Ia/MentionBackfillService.php` para el scan retroactivo.
- **Console**: `app/Console/Commands/BackfillKeywordCommand.php`.
- **Jobs**: `app/Jobs/BackfillKeywordMatches.php`.
- **Controller cliente**: `app/Http/Controllers/MisAvisosController.php::storeKeyword()` despacha el job.

## Non-goals

- No se reescriben las consultas crudas del cliente (MisAvisosController) — ya están agrupadas vía `_table-hits.blade.php`.
- No se elimina físicamente la tabla legacy `keyword_matches` (sigue existiendo aunque vacía; ningún flujo nuevo la escribe).
- No se cambia el UNIQUE constraint existente.
- No se reescribe el matcher para usar pg_trgm o full-text-search — se mantiene la búsqueda por substring normalizado (acorde con la decisión de phase-1 de no tocar el motor de búsqueda).
