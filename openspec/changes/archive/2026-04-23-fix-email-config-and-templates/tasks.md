## 1. Backend: API de Configuración de Correo

- [x] 1.1 Crear endpoint GET `/api/email/config` para obtener la configuración de correo desde la base de datos
- [x] 1.2 Crear endpoint POST/PUT `/api/email/config` para guardar o actualizar la configuración de correo
- [x] 1.3 Agregar validación de campos obligatorios (servidor, puerto, usuario) en el endpoint de guardado
- [x] 1.4 Agregar endpoint POST `/api/email/config/test` para probar la conexión SMTP con los datos actuales
- [x] 1.5 Asegurar que los endpoints requieran autenticación y autorización de administrador

## 2. Backend: API de Plantillas de Correo

- [x] 2.1 Crear endpoint GET `/api/email/templates` para listar todas las plantillas
- [x] 2.2 Crear endpoint POST `/api/email/templates` para crear una nueva plantilla
- [x] 2.3 Crear endpoint PUT `/api/email/templates/:id` para editar una plantilla existente
- [x] 2.4 Crear endpoint DELETE `/api/email/templates/:id` para eliminar una plantilla
- [x] 2.5 Agregar validación de campos obligatorios (nombre, asunto) en creación y edición
- [x] 2.6 Agregar confirmación o validación antes de eliminar plantillas en uso

## 3. Frontend: Formulario de Configuración de Correo

- [x] 3.1 Modificar el componente de configuración para cargar datos vía GET `/api/email/config` al montarse
- [x] 3.2 Poblar los campos del formulario (servidor, puerto, usuario, contraseña, seguridad, remitente) con los datos obtenidos
- [x] 3.3 Implementar botón "Guardar" que envíe los datos vía POST/PUT `/api/email/config`
- [x] 3.4 Implementar botón "Probar conexión" que use el endpoint de prueba SMTP
- [x] 3.5 Mostrar mensajes de éxito o error según la respuesta del servidor
- [x] 3.6 Agregar validación visual de campos obligatorios en el frontend

## 4. Frontend: Ventana de Plantillas de Correo

- [x] 4.1 Modificar el componente de plantillas para cargar la lista vía GET `/api/email/templates`
- [x] 4.2 Mostrar las plantillas en una tabla o lista con nombre, asunto y fecha de modificación
- [x] 4.3 Implementar botón "Nueva plantilla" que abra un formulario/modal de creación
- [x] 4.4 Implementar botón "Editar" que cargue los datos de la plantilla seleccionada en un formulario/modal
- [x] 4.5 Implementar botón "Eliminar" con diálogo de confirmación antes de enviar DELETE
- [x] 4.6 Actualizar la lista de plantillas automáticamente tras crear, editar o eliminar
- [x] 4.7 Mostrar mensaje cuando no existan plantillas

## 5. Integración y Pruebas

- [x] 5.1 Verificar que la configuración cargue correctamente desde la base de datos al abrir el formulario
- [x] 5.2 Verificar que los cambios en configuración se guarden y persistan en la base de datos
- [x] 5.3 Verificar que la prueba de conexión SMTP funcione con los datos del formulario
- [x] 5.4 Verificar que las plantillas se listen, creen, editen y eliminen correctamente
- [x] 5.5 Verificar que la interfaz se actualice en tiempo real tras cada operación CRUD
- [x] 5.6 Revisar permisos de acceso: solo administradores deben poder ver/modificar configuración y plantillas
