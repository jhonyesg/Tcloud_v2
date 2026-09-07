# mis-avisos-table-navigation Specification

## Purpose
TBD - created by archiving change 2026-09-07-mis-avisos-navigation-polish. Update Purpose after archive.

## Requirements

### Requirement: Paginación accesible arriba y abajo con tamaño configurable

Las tablas de Mis Avisos (En vivo e Histórico) SHALL presentar controles de paginación tanto al inicio como al final de la tabla: botones Anterior/Siguiente con iconos y estado deshabilitado coherente, números de página con elipsis para rangos largos, y un selector de resultados por página (25/50/100/500). El tamaño elegido SHALL enviarse al servidor (whitelist) y mantenerse al cambiar de pestaña.

#### Scenario: Cambiar de página sin desplazarse al final
- **WHEN** el cliente está viendo la página 3 del histórico y pulsa "Siguiente" en la paginación superior
- **THEN** la tabla carga la página 4 sin requerir scroll al final

#### Scenario: Cambiar el tamaño de página
- **WHEN** el cliente selecciona 100 resultados por página
- **THEN** el servidor retorna 100 por página y la paginación refleja el nuevo total de páginas

### Requirement: Acciones de fila con identidad de color y micro-animaciones

Las acciones de fila SHALL distinguirse visualmente por color: "Ver" en color de marca (sólido), "Editor" en violeta (identidad del editor de medios) y "Archivos" en esmeralda (identidad de carpeta), cada uno con icono y transición de hover (elevación/sombra) y feedback al presionar. Las filas SHALL tener hover sutil animado.

#### Scenario: Identificación visual inmediata de acciones
- **WHEN** el cliente ve las acciones de una fila
- **THEN** distingue por color Ver/Editor/Archivos sin leer las etiquetas, y el hover de cada botón da feedback visible
