## Context

El diagrama actual en `postgres.blade.php` muestra tablas en posicion fija calculada automaticamente. El usuario quiere poder arrastrar las tablas para reorganizarlas y que esa organizacion se guarde.

## Goals / Non-Goals

**Goals:**
- Tablas arrastrables con mouse
- Guardar posiciones en localStorage
- Restaurar posiciones al recargar pagina
- Modal de feedback para backup

**Non-Goals:**
- No cambiar el algoritmo de layout inicial
- No agregar zoom/pan (ya existe overflow)
- No guardar en base de datos (localStorage es suficiente)

## Decisions

**Decisión 1: Implementación de Drag & Drop**

Usar SVG nativo con eventos de mouse:
- `mousedown` en tabla → comenzar drag
- `mousemove` → mover tabla
- `mouseup` → terminar drag
- Cursor `grab`/`grabbing` para feedback visual

**Decisión 2: Estructura de posiciones**

```javascript
// localStorage key: 'postgres_diagram_positions'
{
  "users": { "x": 100, "y": 200 },
  "files": { "x": 400, "y": 200 },
  // ...
}
```

**Decisión 3: Restauracion de posiciones**

Al cargar schema:
1. Fetch tables
2. Cargar positions de localStorage
3. Si no existe, usar posicion automatica por algoritmo

**Decisión 4: Modal de backup**

- Usar modal existente en Alpine
- Mostrar icono de espera durante generacion
- Cambiar a icono de exito/error al terminar
- Boton para cerrar

## Risks / Trade-offs

| Riesgo | Mitigación |
|--------|------------|
| localStorage lleno | Intentar catch, fallback a posiciones automaticas |
| Tabla sin posicion guardada | Usar algoritmo automatico |
| Backup falla silenciosamente | Mostrar error del servidor en modal |

## Migration Plan

1. Modificar `renderDiagram()` para hacerlo arrastrable
2. Agregar funciones de drag start/move/end
3. Guardar posiciones en localStorage al hacer drop
4. Cargar posiciones al iniciar
5. Modificar `downloadBackup()` para mostrar modal

**Rollback**: Quitar codigo de localStorage y drag
