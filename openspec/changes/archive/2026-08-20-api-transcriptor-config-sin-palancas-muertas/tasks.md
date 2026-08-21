# Tasks

Todas ejecutadas y verificadas el 2026-08-20.

## 1. Auditoría

- [x] 1.1 Rastrear consumidor real de las 43 claves del `SCHEMA`, descartando definición y comentarios
- [x] 1.2 Buscar consumidores que leen `config('transcriptor.…')` y por tanto ignoran el override
- [x] 1.3 Comprobar los casos dudosos: `min_batch`/`max_batch`, `inflight_max`, `srt_max_segment_chars`, `corrections_chunk`, timeouts
- [x] 1.4 Barrer el módulo en busca de código muerto: métodos del controlador sin ruta, métodos y getters del componente Alpine sin uso, rutas que la UI no llama
- [x] 1.5 Verificar en caliente la cadena override → consumidor (`corrections_chunk = 777`, leído por `CorrectionService`, luego retirado)

## 2. Retirar lo que no hace nada

- [x] 2.1 Quitar `ai_coherence_threshold` y `ai_coherence_model` del `SCHEMA`
- [x] 2.2 Constante `MOSTLY_EN_SCORE` en `TranscriptionCoherencePass`, junto a la heurística de mezcla que gobierna
- [x] 2.3 Actualizar el docblock del pase: qué se configura, qué no, y por qué
- [x] 2.4 Eliminar el método `transcribeFile(f)` del componente Alpine (alias sin bindings)
- [x] 2.5 Comprobar que no quedan overrides huérfanos en `system_settings` para las claves retiradas

## 3. Que lo que queda se aplique de verdad

- [x] 3.1 `indexData()` publica `ui_limits` (batch_max, max_parallel_sends, scan_batch) desde la capa de settings
- [x] 3.2 La vista consume `ui_limits` en vez de `config()`
- [x] 3.3 Declarar en `config/transcriptor.php` las seis claves que solo vivían en el esquema

## 4. Verificación

- [x] 4.1 `transcription:config` ya no lista las dos claves retiradas y ninguna informa mal su origen
- [x] 4.2 Override de `ui_batch_max = 75` → la página lo pinta desde la primera carga; retirado después
- [x] 4.3 Página renderizada desde el kernel HTTP (200) y clic del interruptor probado en DOM real: sigue funcionando
- [x] 4.4 `phpunit --filter="Transcript|Coherence|PruneGuard"` → **56/56**, incluido el test de correspondencia esquema/config que llevaba tiempo en rojo
