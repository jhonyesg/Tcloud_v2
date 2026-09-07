# alert-delivery-cadence Specification

## Purpose
Define cómo el cliente elige la cadencia de sus correos de aviso, con proyección de impacto, techo diario por cupo y rate limiter global que protege el relay de correo de bloqueos.

## Requirements

### Requirement: El cliente elige la cadencia de sus avisos por correo
El sistema SHALL permitir al cliente elegir entre las cadencias: 1, 5, 15, 20, 30, 50 minutos, cada hora, 3 veces al día, 6 veces al día o 1 vez al día. La cadencia SHALL ser una configuración por cliente (no por keyword).

#### Scenario: Cliente elige cadencia
- **WHEN** el cliente selecciona "cada hora" en sus preferencias de aviso
- **THEN** los matches que caigan después de un envío se acumulan y salen agrupados en el correo del siguiente vencimiento de su ventana

#### Scenario: Cadencia por defecto al activar el módulo
- **WHEN** el admin activa el módulo y el cliente aún no eligió cadencia
- **THEN** aplica la cadencia por defecto definida por el sistema (30 minutos)

### Requirement: La UI muestra la proyección de impacto antes de elegir
El sistema SHALL mostrar, para cada cadencia, una estimación de correos/semana calculada con los matches reales de los últimos 7 días del cliente, y SHALL advertir el efecto de las cadencias más agresivas.

#### Scenario: Proyección con datos propios
- **WHEN** el cliente abre sus preferencias de aviso y sus keywords generaron 42 matches en los últimos 7 días
- **THEN** la UI muestra, por cada cadencia, el estimado de correos/semana (ej. "Inmediato: ~42 correos/semana")

#### Scenario: Advertencia de consecuencia
- **WHEN** el cliente considera una cadencia agresiva (ej. 1 minuto)
- **THEN** la UI advierte que puede ocasionar muchos correos y explica qué ocurre al alcanzar su cupo diario

### Requirement: Los avisos por correo cubren solo los matches del día actual
El sistema SHALL incluir en los correos de aviso únicamente los matches generados durante el día actual. Los matches históricos de días anteriores SHALL NO disparar correos.

#### Scenario: Match del día se notifica
- **WHEN** el scan de hoy genera matches para el cliente y vence su ventana de cadencia
- **THEN** el correo agrupa esos matches del día

#### Scenario: Histórico no dispara correo
- **WHEN** el cliente consulta/exporta su histórico de 60 días
- **THEN** ninguna consulta histórica genera correo

### Requirement: Techo diario por cupo de correos del cliente
El sistema SHALL limitar los correos de aviso enviados a cada cliente a un máximo diario igual a su `emails_quota`. Al alcanzar el techo, los matches pendientes SHALL acumularse y entregarse en el resumen del día siguiente, sin perderse.

#### Scenario: Cupo diario agotado
- **WHEN** el cliente con `emails_quota=20` ya recibió 20 correos hoy y vence otra ventana de cadencia
- **THEN** no se envía correo; los matches quedan pendientes y salen con el resumen del día siguiente

#### Scenario: El cliente mantiene el conocimiento
- **WHEN** se suprime un envío por techo diario
- **THEN** el cliente lo puede ver reflejado en su módulo (indicador de pendientes de entrega)

### Requirement: Rate limiter global del relay de correo
El sistema SHALL limitar globalmente los envíos de correos de aviso por minuto (configurable, con valor por defecto conservador) usando la cola Redis, para proteger el relay SMTP de bloqueos. Los correos de otros módulos (bienvenida, recuperación) no SHALL NO quedan bloqueados por la cola de avisos, pero comparten el rate limiter global como último freno.

#### Scenario: Ráfaga de envíos se dosifica
- **WHEN** en un minuto vencen las ventanas de 15 clientes y el rate limiter permite 20 correos/minuto
- **THEN** los correos salen dosificados en cola y ninguno falla por bloqueo del relay

#### Scenario: Ningún correo sale durante el scan
- **WHEN** termina el scan de una transcripción con coincidencias
- **THEN** ningún email parte en ese instante; todos pasan por la cola con su cadencia
