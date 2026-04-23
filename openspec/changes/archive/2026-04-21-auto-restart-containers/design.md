## Context

El sistema tcloud usa Docker Compose para orquestar 4 servicios: nginx, php, postgres, y redis. Actualmente no hay configuración de reinicio automático, por lo que después de un reinicio del PC todos los contenedores quedan en estado `Exited`.

## Goals / Non-Goals

**Goals:**
- Configurar reinicio automático para todos los contenedores
- Minimizar intervención manual después de reinicios del sistema

**Non-Goals:**
- No implementar monitoreo de salud (health checks) en esta fase
- No cambiar la configuración de red o volúmenes existentes

## Decisions

**Decisión 1**: Usar `restart: unless-stopped` en lugar de `restart: always`

| Política | Comportamiento |
|----------|-----------------|
| `always` | Reinicia siempre, incluso si se detiene manualmente |
| `unless-stopped` | Reinicia automáticamente UNLESS fue detenido explícitamente |

`unless-stopped` es la elección correcta porque permite usar `docker stop` para detener contenedores permanentemente sin que se reinicien.

## Risks / Trade-offs

- **Riesgo**: Si el PC se apaga incorrectamente (sin shutdown graceful), Docker podría no ejecutar el reinicio → **Mitigación**: Generalmente Docker maneja esto correctamente, pero en casos extremos podría requerir `docker-compose up -d` manual
- **Trade-off**: `unless-stopped` vs `always` - elegimos `unless-stopped` para permitir mantenimiento planeado

## Migration Plan

1. Editar `docker-compose.yml` agregando `restart: unless-stopped` a cada servicio
2. Ejecutar `docker-compose down` para detener contenedores actuales
3. Ejecutar `docker-compose up -d` para aplicar nueva configuración
4. Verificar que todos los contenedores están corriendo

**Rollback**: Quitar las líneas `restart:` y ejecutar `docker-compose up -d`
