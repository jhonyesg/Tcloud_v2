## ADDED Requirements

### Requirement: Admin puede convertir filas de pendientes o aprobadas en exclusiones con un click
El sistema SHALL exponer un botón "Excluir" por fila en las tablas **Pendientes** y **Aprobadas** del módulo `/ia/correcciones`. Al click, se abre un modal pre-llenado con el campo `wrong_text` como término a excluir (editable) y una nota de auditoría opcional. Al guardar, se hace POST a `/ia/correcciones/protected-terms` con `{term, notes}`; respuesta 201 cierra el modal con toast verde, 422 muestra toast rojo con el mensaje del backend (típicamente "ya existe"). El sistema SHALL también exponer un botón bulk "Excluir N seleccionadas" en las mismas tablas, que envía `{terms: [...]}` al endpoint y muestra cuántos se crearon vs cuántos eran duplicados. Convertir una corrección en exclusión NO modifica el estado de la corrección (no se aprueba ni se rechaza automáticamente); las acciones son independientes.

#### Scenario: Admin ve "Open English" en pendientes y la excluye
- **WHEN** el admin está revisando la tabla de Pendientes, encuentra una fila con `wrong_text="Open English"`, y hace click en "Excluir"
- **THEN** se abre un modal pre-llenado con `term="Open English"`, `notes="Agregada desde pendientes — corrección #<id>: Open English → ..."`
- **WHEN** el admin ajusta el `term` a `"open english"` (lowercase) y guarda
- **THEN** el endpoint responde 201 con la fila creada
- **THEN** el modal cierra con toast verde "Exclusión 'open english' agregada"
- **THEN** la fila de pendientes sigue siendo pending (no se aprobó ni rechazó)

#### Scenario: Admin intenta excluir un término que ya existe
- **WHEN** el admin hace click "Excluir" en una fila cuyo `wrong_text` ya es una exclusión activa
- **THEN** el modal abre pre-llenado con el término; al guardar, el backend responde 422 con `error: "'<term>' ya existe entre las exclusiones activas"`
- **THEN** la UI muestra toast rojo con el mensaje y permite corregir/archivar la existente

#### Scenario: Admin excluye múltiples pendientes en bulk
- **WHEN** el admin selecciona 3 pendientes (cuyos `wrong_text` son distintos), abre el modal bulk "Excluir 3" y guarda con nota compartida "Limpieza batch 2026-08-01"
- **THEN** el endpoint recibe `{terms: [{term, notes: "Limpieza batch ... — #1"}, ...]}` (con índice si la opción está marcada)
- **THEN** la respuesta es 201 con 3 ids creados o 207 si alguno es duplicado, mostrando toast "X creadas, Y duplicadas"

#### Scenario: Atajo en tabla de aprobadas
- **WHEN** el admin revisa la tabla de Aprobadas y ve una fila que debería ser una exclusión (ej. marca recién detectada), hace click "Excluir" en esa fila
- **THEN** el modal se abre pre-llenado con el `wrong_text` aprobado
- **WHEN** el admin guarda
- **THEN** la corrección queda aprobada (sigue activa en el diccionario) y se crea la exclusión en paralelo
- **THEN** si el admin luego quiere revertir la aprobación, usa el botón "Eliminar" existente de la fila; la exclusión queda independientemente

#### Scenario: Cambio aplica en próxima corrida AI Suggest
- **WHEN** el admin agrega una exclusión por atajo (de Pendientes o Aprobadas) a las 10:00
- **THEN** en ≤5 minutos (cache TTL), la próxima corrida AI Suggest ve el nuevo término en su lista de exclusiones dinámicas y lo cuenta en `rejected_by_filter` si el LLM lo intenta proponer de nuevo
