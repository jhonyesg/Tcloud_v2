## Why

Los contenedores Docker del sistema tcloud se detienen cuando se reinicia el PC, ya que el `docker-compose.yml` no tiene política de reinicio configurada. Esto requiere inicio manual de los servicios, lo cual es incómodo y propenso a errores en un entorno de producción.

## What Changes

- Agregar `restart: unless-stopped` a todos los servicios en `docker-compose.yml`
- Esto permite que los contenedores se reinicien automáticamente después de un reinicio inesperado del sistema
- Los contenedores pueden ser detenidos manualmente con `docker stop` y permanecerán detenidos

## Capabilities

### New Capabilities

- `docker-restart-policy`: Define y aplica la política de reinicio automático para todos los contenedores Docker del sistema

### Modified Capabilities

- *(ninguno)*

## Impact

- **Archivo afectado**: `docker-compose.yml`
- **Servicios afectados**: nginx, php, postgres, redis
- **Comportamiento**: Los contenedores ahora sobrevivirán reinicios del PC sin intervención manual
