## ADDED Requirements

### Requirement: Pool PHP-FPM limitado a 50 workers máximo
El pool `[www]` de PHP-FPM SHALL tener `pm.max_children = 50`, de modo que el consumo máximo de RAM sea 50 × 256 MB = 12.8 GB, dentro del límite del servidor de 78 GB.

#### Scenario: Carga máxima no agota RAM
- **WHEN** todos los 50 workers están activos simultáneamente
- **THEN** el consumo total de PHP-FPM no supera 12.8 GB de RAM

### Requirement: Workers se reciclan tras 500 requests
El pool SHALL configurar `pm.max_requests = 500` para reciclar los procesos worker periódicamente y prevenir acumulación de memory leaks.

#### Scenario: Worker se recicla automáticamente
- **WHEN** un worker PHP-FPM ha procesado 500 requests
- **THEN** el proceso se termina y FPM lanza uno nuevo en su lugar sin downtime observable

### Requirement: Spare servers dentro de rango razonable
El pool SHALL tener `pm.min_spare_servers = 5` y `pm.max_spare_servers = 15` para mantener workers listos sin desperdiciar memoria.

#### Scenario: Arranque del servidor
- **WHEN** PHP-FPM arranca
- **THEN** inicia exactamente `pm.start_servers` workers (configurado como 10) y no más de 15 quedan idle

### Requirement: memory_limit de 256 MB por worker
Cada proceso PHP-FPM SHALL tener `memory_limit = 256M` configurado en el pool.

#### Scenario: Request normal no supera límite
- **WHEN** se procesa cualquier request que no sea generación de ZIP grande
- **THEN** el proceso no supera los 256 MB de uso de memoria
