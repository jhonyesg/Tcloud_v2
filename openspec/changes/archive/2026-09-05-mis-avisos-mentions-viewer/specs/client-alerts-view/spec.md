## ADDED Requirements

### Requirement: Feed en vivo filtrable por emisora, keyword y término

El feed en vivo de `/mis-avisos` SHALL aceptar los mismos filtros que el histórico: término libre, una o más emisoras (storages) y keyword registrada. El filtrado SHALL aplicarse en el servidor respetando la intersección de acceso (transcription_access ∩ alcance keyword→store) y SHALL mantenerse entre sondeos sucesivos del feed.

#### Scenario: El cliente mira solo las menciones de una emisora
- **WHEN** el cliente selecciona la emisora "Caracol" en el filtro del feed en vivo
- **THEN** el feed muestra únicamente coincidencias de hoy cuya transcripción pertenezca a esa emisora, y los sondeos posteriores mantienen el filtro

#### Scenario: Filtro por keyword registrada
- **WHEN** el cliente filtra el feed por su keyword "marca X"
- **THEN** solo aparecen coincidencias de esa keyword, dentro de las emisoras donde esa keyword tiene alcance

#### Scenario: Cliente sin acceso a la emisora filtrada
- **WHEN** el cliente intenta filtrar por una emisora sin `transcription_access`
- **THEN** esa emisora no está entre las opciones filtrables y el servidor ignora cualquier intento de forzarla

### Requirement: Feed en vivo e histórico se presentan como tablas con paginación real

El feed en vivo y el histórico SHALL presentarse como tablas con columnas consistentes (fecha/hora, emisora, archivo, keyword, minuto, contexto) y paginación server-side; el feed en vivo SHALL superar el límite fijo de resultados de hoy mostrando el total y navegando por páginas.

#### Scenario: Tabla del feed en vivo con más coincidencias que una página
- **WHEN** hoy existen 120 coincidencias visibles para el cliente
- **THEN** el feed en vivo las presenta paginadas (25 por página) con el total visible y controles de página, ordenadas por fecha descendente

#### Scenario: Columnas accionables
- **WHEN** el cliente visualiza cualquier fila de la tabla (en vivo o histórico)
- **THEN** dispone de las acciones de la fila: ver transcripción anclada y, según sus capabilities, ver el archivo en el minuto y generar corte
