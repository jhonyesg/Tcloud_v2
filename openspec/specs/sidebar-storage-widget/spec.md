## ADDED Requirements

### Requirement: Widget de almacenamiento muestra datos reales del usuario

El sidebar SHALL mostrar el uso de almacenamiento real del usuario autenticado en lugar de valores estáticos. Los datos se obtienen del modelo `User` a través de un View Composer registrado en `AppServiceProvider`.

#### Scenario: Usuario normal con cuota limitada

- **WHEN** un usuario con rol distinto de `admin` accede a cualquier página del layout
- **THEN** el sidebar muestra `personal_used_bytes` formateado y `personal_quota_bytes` formateado como límite
- **THEN** la barra de progreso refleja el porcentaje real `(used / limit) * 100`

#### Scenario: Usuario normal con cuota ilimitada

- **WHEN** un usuario tiene `personal_quota_bytes = 0`
- **THEN** el sidebar muestra el uso en bytes formateados y el texto "Ilimitado" como límite
- **THEN** la barra de progreso no se muestra

#### Scenario: Admin ve el total del sistema

- **WHEN** un usuario con rol `admin` accede a cualquier página del layout
- **THEN** el sidebar muestra la suma total de `size` de todos los archivos del sistema como uso
- **THEN** se muestra "Sistema" o "Ilimitado" como límite sin barra de porcentaje

### Requirement: Formateo de bytes legible por humanos

El widget SHALL formatear los valores de bytes en la unidad más legible: KB (< 1 MB), MB (< 1 GB), o GB (>= 1 GB), con dos decimales de precisión.

#### Scenario: Valores en megabytes

- **WHEN** el uso es menor a 1 GB y mayor o igual a 1 MB
- **THEN** se muestra el valor en MB con dos decimales (ej: "1.24 MB")

#### Scenario: Valores en gigabytes

- **WHEN** el uso es mayor o igual a 1 GB
- **THEN** se muestra el valor en GB con dos decimales (ej: "2.50 GB")

### Requirement: Alerta visual cuando el almacenamiento está casi lleno

El widget SHALL cambiar el color de la barra a rojo cuando el porcentaje de uso supere el 90%.

#### Scenario: Uso normal

- **WHEN** el porcentaje de uso es menor o igual al 90%
- **THEN** la barra se muestra en color de marca (`bg-brand-300`)

#### Scenario: Uso crítico

- **WHEN** el porcentaje de uso supera el 90%
- **THEN** la barra se muestra en rojo (`bg-red-400`) para alertar al usuario

### Requirement: Sin errores cuando la sesión no tiene usuario

El View Composer SHALL manejar graciosamente el caso en que no haya sesión activa, devolviendo valores en cero para no romper la renderización del layout.

#### Scenario: Sesión inválida o inexistente

- **WHEN** el layout se renderiza sin `user_id` en la sesión
- **THEN** el widget muestra "0 KB / 0 KB" o queda oculto sin lanzar excepciones
