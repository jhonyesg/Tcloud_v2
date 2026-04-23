## 1. Crear controlador

- [x] 1.1 Crear PostgresAdminController con métodos: index, test, schema, query, backup, uploadFtp

## 2. Agregar ruta

- [x] 2.1 Agregar Route::get('/admin/postgres') en web.php

## 3. Crear vista principal

- [x] 3.1 Crear postgres.blade.php con estructura de tabs

## 4. Implementar tab Configuración

- [x] 4.1 Formulario de configuración de conexión
- [x] 4.2 Botón "Probar conexión"

## 5. Implementar tab Diagrama

- [x] 5.1 Obtener esquema de tablas y relaciones (endpoint /schema)
- [x] 5.2 Renderizar diagrama visual con Canvas/SVG

## 6. Implementar tab Query SQL

- [x] 6.1 Editor de SQL (textarea)
- [x] 6.2 Botón "Ejecutar" y tabla de resultados

## 7. Implementar tab Backup

- [x] 7.1 Botón "Backup local"
- [x] 7.2 Configuración FTP y botón "Backup a FTP"
