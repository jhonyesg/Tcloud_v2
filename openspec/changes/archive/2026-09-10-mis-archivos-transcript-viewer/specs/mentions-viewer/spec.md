## ADDED Requirements

### Requirement: El visor de transcripción es invocable desde cualquier entry point que entregue un archivo con permiso

El modal de transcripción SHALL ser invocable desde entry points distintos a una mención de Mis Avisos siempre que el invocador aporte `(file_id, transcription_id)` de un archivo visible para el usuario bajo la misma intersección de acceso (`transcription_access` ∩ `transcription_enabled`) y el archivo tenga permiso de lectura para el visor (`can_view_file` calculado en el servidor). Cuando el entry point no es una mención, el modal SHALL abrir en el primer segmento, sin ancla a un segmento de mención, sin filtro de keyword y sin chip de "mención"; cuando sí lo es, SHALL preservar el comportamiento anclado existente. La búsqueda libre, el reproductor sincronizado, el scroll incremental de segmentos, las acciones de corte y "Abrir en Mis Archivos" SHALL estar disponibles en ambos entry points bajo las mismas reglas.

#### Scenario: Apertura desde Mis Archivos sin ancla de mención
- **WHEN** el cliente con `transcription_access` pulsa "Ver transcripción" en una fila de Mis Archivos sobre un archivo con transcripción done
- **THEN** el visor abre con la transcripción desde el primer segmento, sin chip de "mención", sin filtro de keyword, con reproductor en `t=0` y la búsqueda libre activa

#### Scenario: Apertura desde Mis Avisos conserva la ancla a la mención
- **WHEN** el cliente pulsa "Ver" sobre una coincidencia del feed o histórico de Mis Avisos
- **THEN** el visor abre con la transcripción anclada al segmento de la mención, con el chip de "mención: X" visible y el reproductor posicionado en el segundo de la coincidencia (comportamiento existente preservado)

#### Scenario: Capabilities calculadas en el servidor rigen el reproductor en ambos entry points
- **WHEN** el cliente sin permiso de lectura sobre el archivo abre el visor desde cualquiera de los dos módulos
- **THEN** el modal muestra solo la transcripción sin reproductor ni acciones de archivo, idéntico en ambos entry points
