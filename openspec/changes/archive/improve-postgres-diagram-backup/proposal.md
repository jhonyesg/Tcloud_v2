## Why

El modulo PostgreSQL Admin tiene dos areas de mejora:

1. **Diagrama**: Las tablas se muestran en posicion fija y el admin necesita poder rearrglarlas arrastrandolas y guardar esa organizacion para no tener que reorganizar cada vez
2. **Backup**: Cuando se inicia un backup no hay feedback claro de que la descarga comenzo - el usuario no sabe si funciona o no

## What Changes

### 1. Diagrama interactivo con drag & drop
- Tablas arrastrables con el mouse
- Posiciones guardadas en localStorage
- Al recargar el diagrama se restauran las posiciones guardadas

### 2. Feedback de backup mejorado
- Modal de confirmacion antes de iniciar descarga
- Mensaje de exito/error claro
- Indicador visual de progreso

## Capabilities

### Modified Capabilities

- `postgres-diagram`: Hacer diagrama interactivo con drag & drop y persistencia
- `postgres-backup`: Feedback claro de inicio y fin de backup

## Impact

- **Archivos afectados**: `app/resources/views/admin/postgres.blade.php`
- **Almacenamiento**: localStorage para posiciones de tablas
- **UX**: Admin puede personalizar la organizacion del diagrama
