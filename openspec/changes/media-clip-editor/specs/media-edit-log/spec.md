## ADDED Requirements

### Requirement: Tabla de registro de usos del editor
El sistema SHALL mantener una tabla `media_edit_jobs` que registra cada ejecución del editor de corte.

#### Scenario: Registro creado al iniciar procesamiento
- **WHEN** el endpoint `POST /files/{file}/clip` recibe una petición válida
- **THEN** se crea un registro en `media_edit_jobs` con `status = 'processing'` antes de ejecutar FFmpeg

#### Scenario: Registro actualizado tras éxito
- **WHEN** FFmpeg completa con éxito
- **THEN** el registro se actualiza a `status = 'done'`

#### Scenario: Registro actualizado tras fallo
- **WHEN** FFmpeg termina con error
- **THEN** el registro se actualiza a `status = 'failed'` con `error_message` descriptivo

### Requirement: Estructura de la tabla media_edit_jobs
La tabla SHALL contener los campos necesarios para auditoría y billing futuro.

#### Scenario: Campos mínimos requeridos
- **WHEN** se crea un registro de edición
- **THEN** el registro incluye: `user_id`, `source_file_id` (nullable — el archivo puede borrarse), `source_file_name`, `segments_json` (array de {start, end} en segundos), `output_filename`, `status` (processing/done/failed), `error_message` (nullable), `created_at`

### Requirement: Consulta de uso mensual por usuario
El sistema SHALL permitir consultar cuántas veces un usuario ha usado el editor en el mes actual.

#### Scenario: Conteo de usos del mes
- **WHEN** se consulta `media_edit_jobs` para un `user_id` con `created_at >= inicio_del_mes_actual`
- **THEN** el resultado refleja con exactitud los usos del mes (incluye done y failed, excluye processing)
