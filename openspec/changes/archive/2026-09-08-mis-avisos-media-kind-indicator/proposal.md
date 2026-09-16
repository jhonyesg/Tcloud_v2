# Propuesta: Indicador visual y filtro por tipo de medio (TV/Radio) en Mis Avisos

## Why

En Mis Avisos, el cliente ve una tabla con sus menciones (palabras clave encontradas en transcripciones de emisoras). Hoy no hay forma visual de distinguir si un archivo detectado es **video (TV)** o **audio (Radio)**. Cuando un storage contiene archivos mixtos (algunos mp4, algunos mp3/m4a), o cuando el cliente trabaja con varios medios a la vez, esta falta de identificación obliga a abrir el archivo o a mirar la extensión mental del nombre para saber qué tipo de medio es.

Adicionalmente, los filtros actuales (búsqueda libre, rango de fechas, storage, keyword) no permiten acotar por tipo de medio. El cliente que quiere ver **solo lo que es video** (sus TVs) o **solo lo que es audio** (sus radios) tiene que revisar visualmente cada fila.

## What Changes

### 1. Cada fila muestra un ícono de tipo de medio al inicio del nombre de archivo

`MentionsSearchService::hitRow()` agrega un campo derivado `media_kind ∈ {tv, radio, other}` a cada hit, calculado desde `files.mime_type`:
- `mime_type` empieza con `video/` → `tv`
- `mime_type` empieza con `audio/` → `radio`
- resto → `other` (pdf, json, png — sin ícono)

`mis-avisos/_table-hits.blade.php` agrega dos íconos FontAwesome al inicio del filename:
- **TV** → `fas fa-tv` (monitor clásico)
- **Radio** → `fas fa-radio` (radio con dial/antena)
- Otros archivos → sin ícono (decoración opcional con icono neutro)

El ícono aparece tanto en la fila resumen del grupo (cerrado) como en cada sub-fila del panel expandido. Cada mención del mismo archivo muestra el mismo ícono (comparten mime_type).

### 2. Filtros rápidos "Todas / TV / Radio" al lado de los demás filtros

`mis-avisos/index.blade.php` agrega un grupo de 3 botones toggle (mismo patrón visual que "Hoy/Ayer/3d/7d") en el renglón de filtros existente, tanto para En vivo como para Histórico:
```
[✓ Todas]  [📺 TV]  [📻 Radio]
```

Estado guardado en `liveFilters.media_type` y `historyFilters.media_type`, default `'all'`. Cuando el filtro no es `'all'`, los endpoints `/mis-avisos/feed` y `/mis-avisos/history` reciben `?media_type=tv|radio` y filtran el query con `whereHas('transcription.file', fn ($q) => $q->where('mime_type', 'like', 'video/%' | 'audio/%'))`.

El filtro interactúa correctamente con los demás filtros (storage, keyword, rango de fechas) — es un AND más.

### 3. Endpoints `/mis-avisos/feed` y `/mis-avisos/history`

Aceptan `?media_type=tv|radio`. Default sin filtro → todo. El valor se valida en el controller (whitelist tv/radio, cualquier otro → 422).

`historyFilters.media_type` se mantiene en el state Alpine del componente mientras el usuario navega entre tabs (cada tab tiene su state separado, como ya ocurre con `storage_ids`).

## Capacidades nuevas

### `mis-avisos-media-kind`

Visibilidad inmediata del tipo de medio de cada archivo detectado en Histórico y En vivo de Mis Avisos, con filtro por tipo.

## Capacidades modificadas

_Ninguna._ El matching pipeline (`KeywordMatcher`), el backfill (`BackfillKeywordMatches`), el admin de avisos y el admin del módulo Mis Avisos no cambian. El campo `media_kind` se computa on-the-fly desde `mime_type` que ya está en cada hit.

## Impacto

- **Backend**: `app/app/Services/Ia/MentionsSearchService.php` (1 método privado `hitRow()` para derivar `media_kind`; 1 método compartido `applyHitFilters()` para agregar el filtro opcional).
- **Controllers**: `app/app/Http/Controllers/MisAvisosController.php` (2 métodos: `history()`, `feed()` — leer `request('media_type')` y propagar al query).
- **Blade**:
  - `resources/views/mis-avisos/index.blade.php` (3 botones toggle + nuevo `liveFilters.media_type` y `historyFilters.media_type`).
  - `resources/views/mis-avisos/_table-hits.blade.php` (ícono al inicio del filename en filas resumen y sub-filas expandidas).
- **Estado Alpine**: agregar `media_type: 'all'` a `liveFilters` y `historyFilters`.
- **Migrations**: ninguna.
- **Sin cambios en**: `KeywordMatcher`, `MentionBackfillService`, `BackfillKeywordCommand`, `BackfillKeywordMatches`, admin module `AvisosInteligentesController`, `storage_providers`, `files`, `user_keyword`.

## No-goals

- No se modifica `storage_providers.kind` (sigue siendo `'local'|'external'`, no relacionado al tipo de medio).
- No se modifica el filtro de storage existente — el filtro `media_type` se suma como un AND más.
- No se agrega ícono para archivos "otros" (pdf, json, png) por defecto — solo se ven si llegan a aparecer en Histórico, sin ícono.
- No se cambia el admin `ia/avisos-inteligentes/{userId}` — el cambio se circunscribe a Mis Avisos. Si querés el mismo cambio en admin, se hace en un change paralelo.
- No se persiste el filtro `media_type` en URL (no es bookmarkeable por ahora); si es deseable después, se agrega como query param.

## Cómo se ve (visualización final)

```
┌─ Histórico ─ tab activo ─────────────────────────────────────────────────┐
│ Buscar[______]  Desde[...]  Hasta[...]  [Todas emisoras▾] [Todas kw▾]  │
│ [✓ Todas] [📺 TV] [📻 Radio]                            ← NUEVO          │
├─────────────────────────────────────────────────────────────────────────┤
│ ▸ 11:52  📺 winsport_113002.mp4      maluma  ×4  …Maluma, pues   [Ver]    │
│         24 WinSport                                                   │
│                                                                        │
│ ▾ 11:52  📺 winsport_113002.mp4      maluma  ×4  …Maluma, pues   [Ver]  │
│   Las 4 menciones de "maluma" en esta grabación:                       │
│   ① 00:06:41   📺 winsport_113002.mp4   Maluma, pues aquí está...       │
│   ② 00:10:35   📺 winsport_113002.mp4   Menos que Maluma, claro.       │
│   ③ 00:10:36   📺 winsport_113002.mp4   Menos que Maluma, por sup.    │
│   ④ 00:11:05   📺 winsport_113002.mp4   Después de Maluma vino Bad…    │
│                                                                        │
│ ▸ 09:33  📻 caracol_07092026_090002.mp4  Santiago Cruz ×1  …un nombre [Ver]│
│         01 Caracol Tv                                                  │
└─────────────────────────────────────────────────────────────────────────┘
```

(Filtrado por `[📻 Radio]` mostraría solo la segunda fila con ícono 📻; filtrado por `[📺 TV]` solo la primera.)
