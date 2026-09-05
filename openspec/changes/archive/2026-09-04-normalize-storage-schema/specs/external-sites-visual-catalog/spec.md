## MODIFIED Requirements

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
