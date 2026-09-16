## ADDED Requirements

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
