## Why

Al cargar `/ia/api-transcriptor`, la consola del navegador emite errores
`Uncaught TypeError: Cannot read properties of null (reading 'storages_with_empty'/'items')`
provenientes de las expresiones Alpine.js hijas del banner de "carpetas sin
archivos". El banner está envuelto en `x-show="emptyFolders && ..."`, pero
`x-show` solo aplica `display:none`: NO impide que Alpine evalúe los hijos.
Durante la ventana entre el montaje del componente y la resolución del fetch a
`/ia/api-transcriptor/empty-folders` (estado inicial `emptyFolders: null`),
todas las expresiones que leen propiedades del objeto explotan.

## What Changes

- Inicializar `emptyFolders` con un objeto shape vacío
  (`{items:[], storages_with_empty:0, total_missing_folders:0}`) en lugar de
  `null`, para que las expresiones hijas siempre tengan un objeto con shape
  estable desde el primer render.
- Envolver el banner completo en `<template x-if="emptyFolders &&
  emptyFolders.total_missing_folders > 0">` en lugar de `<div
  x-show="...">`, de modo que el subárbol (incluido el `x-for` de `items`) no
  se construya hasta que existan datos reales. Esto blinda contra errores
  similares si en el futuro se añaden bindings nuevos que olviden defensive
  coding.
- Eliminar el flash del banner con `total_missing_folders === 0` durante el
  primer render: como `x-if` no monta el DOM hasta que el predicado sea
  cierto, ese caso queda cubierto sin lógica adicional.

Sin cambios en backend, rutas, migraciones ni API pública.

## Non-goals

- No se modifica la lógica del controlador `ApiTranscriptorController::emptyFolders()`
  ni la respuesta JSON.
- No se cambian otros banners o estados de Alpine en la misma vista que ya
  manejen correctamente `null` (salud, stats, storages, etc.).
- No se introduce un sistema genérico de "loading skeletons" para datos
  asíncronos: el alcance es el banner afectado.

## Capabilities

### New Capabilities
- (ninguna)

### Modified Capabilities
- (ninguna — es un fix interno de bindings Alpine que no altera requisitos de
  spec existentes; el contrato observable del banner es idéntico)

Este change se marca con `skip_specs: true` en `.openspec.yaml`: es un
bug fix de defensive coding frontend, no introduce ni modifica requisitos a
nivel de spec.

## Impact

- Vista afectada: `app/resources/views/ia/api-transcriptor/index.blade.php`
  (líneas 160-223 para el banner; línea 1798 para el estado inicial).
- Sin impacto en backend, base de datos, caché Redis, migraciones, ni otros
  módulos consumidores de `transcription-api-orchestrator`.
- Sin breaking change: la UI renderiza el banner exactamente igual cuando hay
  carpetas vacías; cuando NO las hay, el banner simplemente no aparece desde
  el primer render en vez de aparecer y ocultarse al instante.
- Cumplimiento de la regla "api-transcriptor es módulo de frontera cerrada":
  este change usa el prefijo `YYYY-MM-DD-*-api-transcriptor-*` requerido por
  el requisito existente en `openspec/specs/transcription-api-orchestrator/spec.md`.
