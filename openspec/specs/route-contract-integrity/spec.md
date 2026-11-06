# route-contract-integrity Specification

## Purpose
Garantizar que ninguna ruta de la aplicación quede apuntando a un método de controller inexistente, de modo que un endpoint vivo nunca degrade a HTTP 500 sin que un guardarraíl ejecutable lo detecte antes del despliegue.

## Requirements

### Requirement: Verificación ejecutable del contrato de rutas

El proyecto SHALL exponer un mecanismo ejecutable que recorra todas las rutas registradas y compruebe que cada una resuelve a un método de controller existente. El mecanismo SHALL terminar con código de salida distinto de cero y SHALL identificar la URI, los verbos HTTP y el método ausente cuando detecte una incongruencia. La detección SHALL presentarse como aserto fallido, nunca como excepción no capturada.

#### Scenario: Todas las rutas resuelven

- **WHEN** se ejecuta el mecanismo con el código de la aplicación en su estado declarado
- **THEN** el mecanismo termina con código de salida cero
- **AND** SHALL informar el número total de rutas verificadas

#### Scenario: Una ruta apunta a un método inexistente

- **WHEN** una ruta registrada apunta a un método de controller que no existe
- **THEN** el mecanismo termina con código de salida distinto de cero
- **AND** SHALL nombrar la URI, los verbos HTTP y el método ausente
- **AND** el fallo SHALL reportarse como aserto, no como traza de excepción

#### Scenario: Una ruta retirada deja de verificarse

- **WHEN** se retira una ruta junto con el método que invocaba
- **THEN** el mecanismo deja de reportarla y vuelve a terminar con código de salida cero

### Requirement: Retirada de un método de controller sin dejar rutas huérfanas

Cuando un método de controller se retire, las rutas que lo referencian SHALL ser retiradas en el mismo cambio o el método SHALL ser restaurado. Ninguna ruta SHALL quedar activa apuntando a un método ausente.

#### Scenario: Método retirado sin ajustar las rutas

- **WHEN** un cambio elimina un método de controller que todavía tiene rutas registradas
- **THEN** el mecanismo de verificación falla y nombra la ruta afectada
- **AND** el cambio no se considera completo

#### Scenario: Rutas retiradas junto con el método

- **WHEN** un cambio elimina un método de controller y también retira las rutas que lo referenciaban
- **THEN** el mecanismo de verificación pasa
- **AND** ningún endpoint de administración o público queda inaccesible de forma inesperada

#### Scenario: Método restaurado con su contrato original

- **WHEN** un método de controller es restaurado con el mismo nombre, aridad y contrato que las rutas registradas esperan
- **THEN** el mecanismo de verificación pasa sin necesidad de tocar las rutas
- **AND** el endpoint vuelve a responder según su contrato
