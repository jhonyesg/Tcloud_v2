## ADDED Requirements

### Requirement: Atajo "Excluir" archiva la corrección asociada en la misma operación
Cuando el admin hace click "Excluir" en una fila de Pendientes o Aprobadas (atajo contextual) y la creación de la exclusión devuelve 201, la corrección asociada SHALL archivarse automáticamente (`status='rejected'`, `rejected_reason='moved_to_exclusion: <term>'`) en la misma transacción HTTP. La fila SHALL desaparecer del tab Pendientes o Aprobadas al refrescar la lista. Lo mismo aplica al bulk "Excluir N seleccionadas": cada corrección vinculada se archiva con su motivo trazable. El subpanel "Exclusiones" (alta manual) NO archiva ninguna corrección porque no hay association.

#### Scenario: Admin excluye una pendiente y la fila desaparece
- **WHEN** el admin clickea "🛡 Excluir" en una fila de Pendientes, modal pre-llenado aparece con `correct_id=<id>`, ajusta el término y guarda
- **THEN** el backend crea la exclusión (201) Y archiva la corrección (`status='rejected'`, `rejected_reason='moved_to_exclusion: <term>'`) en la misma respuesta HTTP
- **THEN** la UI muestra toast verde "Exclusión 'X' agregada + corrección #Y archivada"
- **THEN** la UI recarga `loadPending()` y la fila desaparece del tab Pendientes

#### Scenario: Bulk excluir N archivamientos múltiples
- **WHEN** el admin selecciona 3 pendientes y clickea "🛡 Excluir 3" en el bottom-bar bulk, modal aparece, guarda
- **THEN** el backend crea 3 exclusiones y archiva 3 correcciones con sus respectivos motivos `moved_to_exclusion: <term>`
- **THEN** la UI recarga `loadPending()` y desaparecen las 3 filas del tab Pendientes
- **THEN** el toast muestra "3 creadas, 3 archivadas"

#### Scenario: Si la exclusión falla por duplicado, no se archiva la corrección
- **WHEN** el admin clickea Excluir en una fila cuyo `wrong_text` ya es una exclusión activa
- **THEN** el backend devuelve 422 con `error="'X' ya existe entre las exclusiones activas."`
- **THEN** la corrección NO se archiva (mantiene su status actual)
- **THEN** el toast rojo muestra el mensaje del backend

#### Scenario: Alta manual desde subpanel Exclusiones NO archiva
- **WHEN** el admin abre IA Suggest → Exclusiones → "Agregar exclusión" y guarda un término sin association
- **THEN** solo se crea la exclusión; ninguna corrección se archiva (no hay association)
- **THEN** la fila en Pendientes/Aprobadas queda intacta
