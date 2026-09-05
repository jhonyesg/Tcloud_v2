## Purpose

Centraliza la decisión de si un correo electrónico es entregable antes de que el sistema intente enviarle cualquier mensaje. Protege al usuario de typos, direcciones inexistentes y dominios disposable, sin filtrar al exterior si el correo existe o no en la base de datos.

## ADDED Requirements

### Requirement: Email syntax validation
The system SHALL validate that any email address passed to outbound communication follows RFC-compliant syntax before any further check or send is attempted.

#### Scenario: Well-formed email
- **WHEN** the system receives an email like `usuario@dominio.com`
- **THEN** the syntax check passes

#### Scenario: Malformed email
- **WHEN** the system receives an email like `usuario@@dominio` or `usuario dominio.com`
- **THEN** the validation fails with reason "formato inválido" and the email is rejected

### Requirement: Domain deliverability check via DNS MX
The system SHALL verify that the email's domain publishes MX records (or, as a fallback, A records) before allowing the address to receive system-generated messages.

#### Scenario: Domain has mail server
- **WHEN** the domain of the email address returns one or more MX records via DNS lookup
- **THEN** the deliverability check passes that domain step

#### Scenario: Domain has no mail server
- **WHEN** the domain returns no MX and no A records via DNS lookup
- **THEN** the validation fails with reason "dominio sin servidor de correo"

#### Scenario: MX lookup is slow or unreachable
- **WHEN** the DNS lookup for the domain does not respond within the configured timeout
- **THEN** the validation does not block the caller indefinitely and falls back to "validar sintaxis y descartar disposable" without failing the call

### Requirement: Disposable domain blocklist
The system SHALL reject email addresses whose domain is in a maintained blocklist of disposable email providers (mailinator, guerrillamail, tempmail, yopmail, and similar).

#### Scenario: Disposable domain detected
- **WHEN** the email domain matches an entry in the disposable blocklist
- **THEN** the validation fails with reason "dominio no permitido"

#### Scenario: Legitimate corporate domain
- **WHEN** the email domain does not match any blocklist entry
- **THEN** the disposable check passes

### Requirement: Caching of MX lookup results
The system SHALL cache MX lookup results per domain with a TTL to avoid repeated DNS queries during bursts of activity (e.g., bulk user creation).

#### Scenario: Repeated lookup within TTL
- **WHEN** the same domain is validated twice within the cache TTL
- **THEN** the second lookup returns the cached result without performing a new DNS query

#### Scenario: Cache miss after TTL
- **WHEN** the cached entry has expired
- **THEN** a fresh DNS lookup is performed and the cache is updated

### Requirement: Validation result contract
The system SHALL return a structured validation result with a boolean `valid` field and, when invalid, a `reason` string suitable for display to the caller. The result MUST NOT leak whether the email belongs to a user in the system.

#### Scenario: Valid email result
- **WHEN** an email passes all checks
- **THEN** the result is `{ valid: true, reason: null }`

#### Scenario: Invalid email result
- **WHEN** an email fails any check
- **THEN** the result is `{ valid: false, reason: <localized string> }` and no information about user existence is included
