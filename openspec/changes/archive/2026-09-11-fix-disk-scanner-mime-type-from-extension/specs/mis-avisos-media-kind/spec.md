## ADDED Requirements

### Requirement: Comando de reconciliación repara `files.mime_type` de filas históricas
El sistema **SHALL** exponer el comando artisan `avisos:reconcile-file-mime-types` que reescribe `files.mime_type` para filas existentes cuyo valor actual es `'video/mp4'` pero cuya extensión de nombre corresponde a un formato de audio (`.mp3`, `.m4a`, `.opus`, `.flac`, `.wav`, `.aac`), usando el mismo mapa de extensiones definido en el spec `disk-scanner-file-mime-type`. Filas con extensiones genuinamente de video (`.mp4`, `.mkv`) **MUST NOT** ser modificadas aunque tengan `mime_type='video/mp4'` (es el valor correcto).

El comando **MUST** correr por defecto en modo `--dry-run` (cuenta candidatos y muestra los IDs sin mutar) y aplicar los cambios solo cuando se invoca con `--apply`. **SHALL** procesar las filas candidatas en chunks de 500 (constraint `no_consultas_pesadas_masivas_servidor`: query liviana acotada por `id` ascendente + filtro en `WHERE`, sin agregaciones masivas sobre el histórico). **SHALL** escribir log con prefijo `files.mime_reconcile.*` en `laravel.log` con conteos: `started`, `scanned`, `updated`, `skipped_unknown_extension`, `finished`, `duration_ms`.

#### Scenario: Modo dry-run reporta conteo sin mutar
- **WHEN** el operador ejecuta `php artisan avisos:reconcile-file-mime-types` (sin `--apply`)
- **THEN** el comando imprime el conteo total de filas candidatas (mime_type='video/mp4' AND nombre con extensión de audio)
- **AND** NO se ejecuta ningún UPDATE en `files`
- **AND** el log registra `files.mime_reconcile.started` y `files.mime_reconcile.finished` con `updated=0`

#### Scenario: Modo apply corrige archivos .mp3 mal etiquetados
- **WHEN** el operador ejecuta `php artisan avisos:reconcile-file-mime-types --apply`
- **AND** existen 1.234 filas `files` con `mime_type='video/mp4'` cuyo nombre termina en `.mp3`
- **THEN** esas 1.234 filas quedan con `mime_type='audio/mpeg'`
- **AND** las filas con `name LIKE '%.mp4'` (legítimos) NO son tocadas
- **AND** el log registra `updated=1234`, `skipped_unknown_extension=N` (si aplica), `duration_ms=T`

#### Scenario: Archivos .mp4 legítimos no se tocan
- **WHEN** el operador ejecuta el comando con `--apply`
- **AND** existen filas `files` con `mime_type='video/mp4'` cuyo nombre termina en `.mp4`
- **THEN** esas filas conservan `mime_type='video/mp4'`
- **AND** siguen apareciendo con ícono TV en Mis Avisos (es el comportamiento esperado)

#### Scenario: Operación chunked no satura la BD
- **WHEN** el comando procesa N candidatos con chunks de 500
- **THEN** los UPDATEs se ejecutan en lotes de 500 filas como máximo por iteración
- **AND** entre chunks el comando libera la conexión si el iterador lo permite (sin SELECT adicionales sobre el histórico)

### Requirement: Comando documenta y reporta el impacto en Mis Avisos
El comando `avisos:reconcile-file-mime-types --apply` **SHALL** incluir en su salida final un resumen del impacto esperado en Mis Avisos: "Estimado: K filas se reclasificarán de TV → Radio (vistas con ícono de radio tras la próxima carga de Mis Avisos)". El conteo K es el mismo `updated` del log. Esto le permite al operador confirmar visualmente que el cambio tendrá efecto antes de refrescar la UI.

#### Scenario: Salida final con estimado
- **WHEN** el comando termina con `updated=K` filas modificadas
- **THEN** la salida stdout incluye la línea "Estimado: K filas se reclasificarán de TV → Radio en Mis Avisos tras la próxima carga."
