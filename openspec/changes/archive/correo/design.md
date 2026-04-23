## Context

El sistema necesita poder enviar correos electrónicos para notificaciones a usuarios. Actualmente no existe configuración de correo, por lo que se implementará un módulo dedicado que permita configurar un servidor SMTP y utilizar plantillas para diferentes tipos de notificaciones.

## Goals / Non-Goals

**Goals:**
- Proporcionar configuración de servidor SMTP desde el panel de administración
- Permitir envío de notificaciones por correo para: compartir enlaces, creación de usuarios, recuperación de contraseñas
- Sistema de plantillas de email editables

**Non-Goals:**
- No implementar cliente de email con interfaz completa, solo integración backend
- No soporte para protocolos distintos a SMTP
- No implementar cola de emails (envío síncrono inicial)

## Decisions

1. **Usar nodemailer para envío de emails**
   - Librería madura y bien mantenida
   - Soporte para SMTP y template engines
   
2. **Configuración almacenada en base de datos**
   - Permite cambio de configuración sin redeploy
   - Tabla `correo_config` con campos: host, port, secure, user, password, from_name, from_email
   
3. **Plantillas almacenadas en BD**
   - Tabla `correo_plantillas` con: name, subject, body (HTML), variables disponibles
   - Facilitad de edición desde admin
   
4. **Envío asíncrono con queue (futuro)**
   - Implementación inicial síncrona para simplicidad
   - Arquitectura preparada para migrar a cola después

## Risks / Trade-offs

- [Riesgo] Almacenar passwords en texto plano → Mitigación: Encriptar password en BD
- [Riesgo] Fallos de envío sin notificación al usuario → Mitigación: Logging de errores y retry básico
- [Trade-off] Plantillas simples vs motor de templates completo →选择了 Handlebars básico para mantener simplicidad