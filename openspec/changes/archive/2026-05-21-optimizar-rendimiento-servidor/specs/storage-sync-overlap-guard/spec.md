## ADDED Requirements

### Requirement: storage:sync no puede ejecutarse en paralelo
El schedule del comando `storage:sync --all` SHALL usar `->withoutOverlapping()` para garantizar que una nueva ejecución no comience si la anterior todavía está corriendo.

#### Scenario: Sync termina antes del siguiente ciclo
- **WHEN** `storage:sync --all` tarda menos de 15 minutos
- **THEN** la siguiente ejecución programada comienza normalmente

#### Scenario: Sync tarda más de 15 minutos
- **WHEN** `storage:sync --all` está en ejecución al momento del siguiente disparo del schedule
- **THEN** el nuevo intento se omite silenciosamente gracias al lock de caché y no se lanza un proceso paralelo

#### Scenario: Servidor reinicia mientras sync está corriendo
- **WHEN** el servidor se reinicia y el lock de caché se pierde
- **THEN** la siguiente ejecución programada del scheduler puede correr con normalidad (el lock Redis habrá expirado o desaparecido)

### Requirement: Eager loading de storageProvider en eliminación recursiva
`FileController::deleteRecursive` y `deleteFile` SHALL cargar la relación `storageProvider` con eager loading al obtener los hijos de una carpeta, para eliminar el patrón N+1.

#### Scenario: Eliminar carpeta con múltiples archivos
- **WHEN** se elimina una carpeta que contiene N archivos
- **THEN** la relación `storageProvider` se carga en una sola query para todos los hijos, no una query por archivo
