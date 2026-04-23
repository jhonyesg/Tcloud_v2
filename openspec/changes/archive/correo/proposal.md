## Why

El sistema necesita un módulo de configuración de correo que permita enviar notificaciones a los usuarios. Esto mejora la experiencia al compartir enlaces, crear usuarios y gestionar contraseñas.

## What Changes

- Nuevo módulo de configuración de servidor de correo (SMTP)
- Opción para enviar enlaces compartidos por correo electrónico
- Notificaciones por correo al crear usuarios
- Envío de correos al cambiar o recuperar contraseñas
- Sistema de plantillas de email personalizables

## Capabilities

### New Capabilities

- `correo-config`: Configuración del servidor SMTP para el envío de correos
- `correo-plantillas`: Plantillas de email para diferentes notificaciones
- `correo-notificaciones`: Sistema de envío de notificaciones por correo

### Modified Capabilities

- `compartido-link`: Agregar opción de envío por correo al compartir enlaces
- `usuario`: Agregar opción de notificación por correo al crear usuarios

## Impact

- Nuevo módulo en `/src/modules/correo/`
- Dependencia de librería para envío de emails (nodemailer)
- Nuevas tablas en BD para configuración y plantillas
- API endpoints para gestión de configuración de correo