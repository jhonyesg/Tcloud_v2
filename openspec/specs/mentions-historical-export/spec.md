# Spec — mentions-historical-export

## Purpose

Define la búsqueda histórica de menciones (60 días) sobre las transcripciones de los storages con acceso del cliente, y su exportación a CSV/Excel con descarga directa y envío por correo solo a solicitud manual.

## Requirements

### Requirement: Búsqueda histórica sobre segmentos de sus storages con acceso
El sistema SHALL permitir al cliente buscar términos libres sobre los textos de transcripción de los storages donde tiene `transcription_access = true`, con antigüedad máxima de 60 días. La búsqueda SHALL respetar también el alcance keyword→store cuando se filtra por una keyword registrada.

#### Scenario: Búsqueda de término libre
- **WHEN** el cliente busca "concierto" en la pestaña de histórico
- **THEN** el sistema retorna coincidencias de texto libre en los segmentos de sus storages con acceso, con medio, fecha, minuto y snippet

#### Scenario: Búsqueda acotada a 60 días
- **WHEN** el cliente busca un término que solo aparece en una grabación de 90 días atrás
- **THEN** el sistema no retorna ese resultado (fuente histórica acotada a la retención de grabaciones del negocio)

#### Scenario: Sin acceso a un storage, sin resultados de ese storage
- **WHEN** el resultado potencial existe en un storage sin `transcription_access` para el cliente
- **THEN** ese resultado no aparece jamás, ni en búsqueda ni en exportación

### Requirement: La búsqueda está protegida contra sobrecarga del servidor
El sistema SHALL aplicar a la búsqueda histórica: mínimo de caracteres por consulta, throttle por usuario y límite de resultados por vista previa.

#### Scenario: Consulta corta rechazada
- **WHEN** el cliente busca "ab" (menos del mínimo)
- **THEN** el sistema informa el mínimo requerido sin ejecutar la consulta

#### Scenario: Uso intensivo limitado
- **WHEN** el cliente excede el límite de búsquedas por minuto
- **THEN** el sistema responde 429 hasta que pase la ventana

### Requirement: Exportación a CSV/Excel mediante job en cola
El sistema SHALL permitir al cliente exportar el resultado filtrado de su histórico a CSV (compatible Excel, con BOM UTF-8) mediante un job en cola que aplique exactamente el mismo filtro de acceso que la vista. El archivo SHALL entregarse por link firmado con expiración.

#### Scenario: Exportación exitosa
- **WHEN** el cliente pulsa "Exportar" con sus filtros activos
- **THEN** se encola el job, la UI lo refleja como pendiente, y al terminar obtiene un link firmado con expiración para descargar el CSV

#### Scenario: El export respeta el filtro de acceso
- **WHEN** el job de exportación procesa los datos
- **THEN** incluye únicamente resultados de storages con `transcription_access` del solicitante y su alcance keyword→store cuando aplique, sin importar qué parámetros envíe el cliente

#### Scenario: Sin exportaciones simultáneas infinitas
- **WHEN** el cliente ya tiene un export en cola/proceso o agotó su tope diario de exports
- **THEN** el sistema rechaza nuevos exports hasta liberar el cupo, informando el motivo

### Requirement: Envío del export por correo solo a solicitud manual
El sistema SHALL ofrecer el envío del archivo exportado por correo electrónico únicamente como acción manual y explícita del cliente. El sistema SHALL NO enviar exports por correo de forma automática ni programada.

#### Scenario: Envío manual del export
- **WHEN** el cliente pulsa "Enviar por correo" sobre un export ya generado
- **THEN** el sistema envía el link de descarga a sus correos registrados y lo registra en log

#### Scenario: Sin envíos automáticos
- **WHEN** termina un export y el cliente no lo solicitó por correo
- **THEN** el sistema no envía nada; el archivo queda disponible por su link firmado hasta expirar

### Requirement: El histórico expone filtros por emisora y keyword en la interfaz

La tabla de histórico SHALL exponer en su barra de filtros la selección de una o más emisoras (limitadas a los storages con acceso del cliente) y la selección de una keyword registrada, además del término libre y el rango de fechas existentes.

#### Scenario: Filtrado combinado de la tabla
- **WHEN** el cliente filtra por el término "concierto", la emisora "Caracol" y su keyword "marca X" en un rango de 30 días
- **THEN** la tabla retorna únicamente las coincidencias que cumplen los cuatro filtros simultáneamente, dentro de sus storages con acceso

### Requirement: La exportación aplica exactamente los filtros activos de la tabla

El botón de exportación SHALL enviar los filtros activos tal cual están aplicados en la tabla (término, rango de fechas, emisoras, keyword), y el archivo generado SHALL contener exactamente el mismo conjunto de resultados que la tabla mostraría paginada, respetando la intersección de acceso.

#### Scenario: Exportación fiel a lo filtrado
- **WHEN** el cliente exporta con filtros de emisora y keyword activos
- **THEN** el CSV generado contiene las mismas filas que la tabla paginada mostraría con esos filtros, sin resultados adicionales

#### Scenario: Exportación sin filtros explícitos
- **WHEN** el cliente exporta sin ajustar filtros
- **THEN** el CSV abarca el histórico completo de 60 días visible para él
