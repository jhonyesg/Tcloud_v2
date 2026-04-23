## Why

El diagrama actual del modulo PostgreSQL tiene dos problemas:

1. **Lineas FK no siguen las tablas**: Al arrastrar una tabla, las lineas de relaciones FK se quedan en su posicion original, rompiendo la conexion visual
2. **Sin control de zoom**: En diagramas con muchas tablas es dificil visualizar todo, no hay forma de hacer zoom in/out

## What Changes

1. **Lineas FK dinamicas**: Las lineas de relaciones se recalculan en tiempo real mientras se arrastra una tabla
2. **Sistema de zoom**: Controles de zoom in/out/reset y soporte para scroll de mouse

## Capabilities

### Modified Capabilities

- `postgres-diagram`: Diagrama con relaciones dinamicas y control de zoom

## Impact

- **Archivo afectado**: `app/resources/views/admin/postgres.blade.php`
- **UX**: Diagrama mucho mas util y navegable
