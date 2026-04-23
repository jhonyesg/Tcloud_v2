## ADDED Requirements

### Requirement: Contenedores se reinician automáticamente
Todos los servicios en docker-compose.yml DEBERÁN tener configurada la política de reinicio `unless-stopped` para sobrevivir reinicios inesperados del sistema host.

#### Scenario: Reinicio automático después de crash del sistema
- **WHEN** El PC se reinicia inesperadamente
- **THEN** Docker reinicia automáticamente todos los contenedores con `restart: unless-stopped`

#### Scenario: Contenedor permanece detenido si fue detenido manualmente
- **WHEN** Un administrador ejecuta `docker stop <contenedor>`
- **THEN** El contenedor permanece detenido incluso después de reiniciar el PC

#### Scenario: Inicio manual de todos los servicios
- **WHEN** Un administrador ejecuta `docker-compose up -d`
- **THEN** Todos los contenedores inician correctamente con sus políticas de reinicio configuradas
