## 1. Base de datos

- [ ] 1.1 Crear migración para tabla `file_tool_plugins` (slug, name, type, supported_mimes, resources, config, is_active)
- [ ] 1.2 Crear migración para tabla `user_file_tool_plugins` (user_id, plugin_id, is_active, expires_at)
- [ ] 1.3 Crear seeders con plugins básicos predefinidos (visor PDF básico, visor imágenes, reproductor video)

## 2. Modelos y Relaciones

- [ ] 2.1 Crear modelo `FileToolPlugin` con casts para supported_mimes, resources, config
- [ ] 2.2 Crear modelo `UserFileToolPlugin` con casts para expires_at
- [ ] 2.3 Definir relaciones: Plugin → many UserFileToolPlugin, User → many UserFileToolPlugin

## 3. Backend: Servicio de Plugins

- [ ] 3.1 Crear `FileToolPluginService` con métodos: getActivePlugins(), getPluginsForUser(userId), getPluginsForMime(userId, mime)
- [ ] 3.2 Crear método para validar recursos de plugin en filesystem
- [ ] 3.3 Crear método para verificar si plugin está activo para usuario (is_active + expires_at)

## 4. Backend: Controladores y Rutas

- [ ] 4.1 Crear `Admin/FileToolPluginController` con CRUD completo de plugins
- [ ] 4.2 Crear `Admin/UserFileToolController` para asignar/revocar plugins a usuarios
- [ ] 4.3 Crear endpoint GET `/api/admin/file-tools/plugins` (listar plugins)
- [ ] 4.4 Crear endpoint GET `/api/admin/file-tools/user/{userId}/plugins` (plugins de usuario)
- [ ] 4.5 Crear endpoint POST `/api/admin/file-tools/user/{userId}/plugins` (asignar plugin)
- [ ] 4.6 Crear endpoint DELETE `/api/admin/file-tools/user/{userId}/plugins/{pluginId}` (revocar)
- [ ] 4.7 Crear endpoint GET `/api/file-tools/available` (plugins disponibles para usuario logueado)

## 5. Frontend: Panel Admin de Plugins

- [ ] 5.1 Crear vista `admin/file-tools/index.blade.php` para listar plugins del sistema
- [ ] 5.2 Crear modal de creación/edición de plugins en admin
- [ ] 5.3 Crear vista `admin/file-tools/users.blade.php` para gestionar plugins por usuario
- [ ] 5.4 Agregar entrada en el menú admin para "Herramientas de Archivo"

## 6. Frontend: Integración en Módulo de Archivos

- [ ] 6.1 Agregar método `getAvailableTools(file)` en Alpine.js del módulo de archivos
- [ ] 6.2 Cargar plugins disponibles al seleccionar un archivo
- [ ] 6.3 Mostrar botones de herramientas en el panel de detalle del archivo
- [ ] 6.4 Implementar carga dinámica de recursos JS/CSS del plugin seleccionado
- [ ] 6.5 Crear contenedor/modal donde se instancia el plugin

## 7. Estructura de Plugins (Ejemplo)

- [ ] 7.1 Crear directorio `public/plugins/pdf-viewer-pro/`
- [ ] 7.2 Crear estructura básica de plugin de ejemplo con manifest.json y recursos
- [ ] 7.3 Documentar formato de plugin para futuros desarrollos

## 8. Pruebas y Verificación

- [ ] 8.1 Verificar que plugins se crean correctamente desde admin
- [ ] 8.2 Verificar que plugins se asignan a usuarios
- [ ] 8.3 Verificar que plugins aparecen en módulo de archivos para usuarios con acceso
- [ ] 8.4 Verificar expiración automática de plugins
- [ ] 8.5 Verificar fallback cuando usuario no tiene plugins
