## Purpose

Amplía el catálogo visual de personalización de sites externos: más iconos agrupados y buscables, más colores, preview más grande y consistencia garantizada entre lo que el admin elige en el módulo, lo que valida el backend y lo que renderiza el sidebar.

## Requirements

### Requirement: Catálogo amplio de iconos agrupados
El formulario de creación/edición de site SHALL ofrecer un catálogo de al menos 40 iconos Font Awesome válidos, organizados en categorías visuales (mínimo: Media, Datos, Comunicación, Seguridad, Herramientas, General), conservando siempre el icono actualmente seleccionado visible y accesible.

#### Scenario: Catálogo muestra categorías
- **WHEN** el admin abre el selector de iconos en el modal de crear/editar site
- **THEN** los iconos aparecen agrupados bajo encabezados de categoría y el total de iconos disponibles es 40 o más

#### Scenario: Selección conservada al reorganizar
- **WHEN** un site existente tiene asignado un icono cualquiera del catálogo y el admin abre el modal de edición
- **THEN** ese icono aparece como seleccionado en su categoría

### Requirement: Búsqueda de iconos en vivo
El selector de iconos SHALL incluir un campo de búsqueda que filtre los iconos en tiempo real por nombre.

#### Scenario: Búsqueda filtra el catálogo
- **WHEN** el admin escribe en el buscador de iconos (ej. "chart")
- **THEN** solo se muestran los iconos cuyo nombre coincide, agrupados en sus categorías, y al limpiar la búsqueda se restaura el catálogo completo

#### Scenario: Sin resultados de búsqueda
- **WHEN** la búsqueda no coincide con ningún icono
- **THEN** se muestra un mensaje de "sin resultados" en lugar de un grid vacío

### Requirement: Paleta ampliada de colores
El formulario SHALL ofrecer al menos 16 colores, cada uno con su variante de fondo pastel y de texto que la vista ya consume, de modo que la combinación icono+color sea distinguible en chips pequeños (20×20 del sidebar). La base de datos SHALL imponer el mismo conjunto de colores que valida la aplicación: cualquier valor fuera del catálogo SHALL ser rechazado tanto por la validación de la app como por la restricción de la BD.

#### Scenario: Paleta completa en el modal
- **WHEN** el admin abre el selector de color en el modal de crear/editar
- **THEN** se muestran 16 o más swatches y el swatch del color actual aparece marcado como seleccionado

#### Scenario: Colores distintos se distinguen entre sí
- **WHEN** dos sites usan colores diferentes de la paleta
- **THEN** sus chips en la tabla del módulo se ven visualmente distintos (sin colisiones de tono entre entradas de la paleta)

#### Scenario: Color nuevo se guarda correctamente
- **WHEN** el admin crea o edita un site con un color nuevo del catálogo (ej. `indigo`)
- **THEN** la API acepta la petición y persiste el valor sin error de base de datos

#### Scenario: Color inválido se rechaza
- **WHEN** una petición envía un color fuera del catálogo
- **THEN** la API responde 422 con error de validación y la BD no persiste el valor

#### Scenario: Inserción directa con color fuera de catálogo falla
- **WHEN** se intenta insertar o actualizar un `external_sites.color` fuera del catálogo de 16 colores por una vía que no pasa por la validación de la app
- **THEN** la base de datos rechaza la operación por violación del CHECK constraint

### Requirement: Preview ampliado de la combinación
El modal de crear/editar SHALL mostrar un preview de la combinación icono+color+nombre de al menos 48px, actualizado en vivo mientras el admin navega iconos y colores.

#### Scenario: Preview refleja la elección en vivo
- **WHEN** el admin cambia icono o color en el modal
- **THEN** el preview actualiza inmediatamente icono, color de fondo y color de texto sin guardar

### Requirement: Validación backend alineada con la paleta
El backend SHALL aceptar exactamente los colores que ofrece el selector del módulo: la regla de validación de `color` en creación y actualización de sites SHALL incluir todos los colores nuevos, y SHALL rechazar valores fuera del catálogo.

#### Scenario: Color nuevo se guarda correctamente
- **WHEN** el admin crea o edita un site con un color nuevo del catálogo (ej. `indigo`)
- **THEN** la API acepta la petición y persiste el valor

#### Scenario: Color inválido se rechaza
- **WHEN** una petición envía un color fuera del catálogo
- **THEN** la API responde 422 con error de validación

### Requirement: Render de colores nuevos en sidebar
El sidebar SHALL conocer los colores nuevos del catálogo: el mapa de colores del sidebar incluye las mismas entradas (bg/text) que el módulo admin, de modo que un site con color nuevo se renderiza con su color y no con el fallback azul.

#### Scenario: Site con color nuevo en el sidebar
- **WHEN** un usuario tiene asignado un site cuyo color es uno de los nuevos
- **THEN** el chip del sidebar se renderiza con el color de fondo y texto correspondiente a ese color, igual que en la tabla del módulo admin

#### Scenario: Consistencia admin-sidebar
- **WHEN** un mismo site se compara entre la tabla del módulo admin y el sidebar del usuario
- **THEN** ambos muestran el mismo icono con el mismo color