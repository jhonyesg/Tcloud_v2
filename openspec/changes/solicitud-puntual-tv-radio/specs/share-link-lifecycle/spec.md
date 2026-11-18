## ADDED Requirements

### Requirement: Los enlaces de entrega de solicitud puntual tienen vigencia propia

Los enlaces generados por el flujo de solicitud puntual de TV y Radio SHALL recibir una vigencia propia, configurable, con un valor inicial de 3 días contados desde el momento de la solicitud. Esta vigencia SHALL ser independiente de la política general de vencimiento de enlaces compartidos.

El sistema SHALL NOT permitir que un enlace de entrega quede sin vencimiento ni que su vencimiento sea extendido, y SHALL NOT permitir que su vigencia se desvíe de la calculada en el momento de la creación. La revocación de la solicitud SHALL producir la expiración efectiva del enlace aunque su fecha almacenada siga en el futuro.

#### Scenario: Vigencia inicial de tres días

- **WHEN** el sistema genera los enlaces de una solicitud puntual
- **THEN** cada enlace vence 3 días después del momento de la solicitud

#### Scenario: La vigencia no depende de la política general

- **WHEN** la política general de vencimiento de enlaces compartidos está fijada en 30 días
- **THEN** los enlaces de solicitud puntual vencen a los 3 días y no a los 30

#### Scenario: No se puede volver permanente

- **WHEN** alguien intenta dejar un enlace de entrega sin vencimiento
- **THEN** el sistema rechaza la operación

#### Scenario: La revocación expira el enlace

- **WHEN** la solicitud que respalda un enlace es revocada
- **THEN** el enlace deja de servir el material aunque su fecha de vencimiento almacenada siga en el futuro
