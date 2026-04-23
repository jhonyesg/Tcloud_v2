-- =====================================================
-- MIGRACIONES MÓDULO CORREO
-- =====================================================

-- Tabla 1: correo_config
CREATE TABLE IF NOT EXISTS correo_config (
    id BIGSERIAL PRIMARY KEY,
    host VARCHAR(255) NOT NULL,
    port INTEGER DEFAULT 587,
    secure BOOLEAN DEFAULT FALSE,
    user VARCHAR(255),
    password_encrypted TEXT,
    from_name VARCHAR(255) NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla 2: correo_plantillas
CREATE TABLE IF NOT EXISTS correo_plantillas (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body_html TEXT NOT NULL,
    variables TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla 3: correo_log
CREATE TABLE IF NOT EXISTS correo_log (
    id BIGSERIAL PRIMARY KEY,
    destinatario VARCHAR(255) NOT NULL,
    plantilla VARCHAR(255) NOT NULL,
    asunto VARCHAR(500),
    body_sent TEXT,
    estado VARCHAR(20) NOT NULL,
    error_message TEXT,
    sent_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- DATOS DE CONFIGURACIÓN SMTP
-- =====================================================

INSERT INTO correo_config (host, port, secure, user, password_encrypted, from_name, from_email, is_active)
VALUES (
    'mail.mediaserver.com.co',
    587,
    FALSE,
    'avisos@mediaserver.com.co',
    'T3cn0l0g14***',  -- Será encriptada por Laravel al guardar
    'Avisos Mediaserver',
    'avisos@mediaserver.com.co',
    TRUE
) ON CONFLICT DO NOTHING;

-- =====================================================
-- PLANTILLAS POR DEFECTO
-- =====================================================

-- Template: bienvenida
INSERT INTO correo_plantillas (name, display_name, subject, body_html, variables, is_active)
VALUES (
    'bienvenida',
    'Bienvenida',
    'Bienvenido a TCloud - {{nombre_usuario}}',
    '<h1>Bienvenido {{nombre_usuario}}!</h1>
<p>Gracias por unirte a nuestra plataforma TCloud.</p>
<p>Tu cuenta está lista para usar. Ya puedes comenzar a subir y compartir archivos.</p>
<p>Si tienes alguna pregunta, no dudes en contactarnos.</p>
<p>Saludos,<br>El equipo de TCloud</p>',
    'nombre_usuario',
    TRUE
) ON CONFLICT (name) DO NOTHING;

-- Template: recuperar-password
INSERT INTO correo_plantillas (name, display_name, subject, body_html, variables, is_active)
VALUES (
    'recuperar-password',
    'Recuperación de contraseña',
    'Recuperar tu contraseña - TCloud',
    '<h1>Recuperación de contraseña</h1>
<p>Hola {{nombre_usuario}},</p>
<p>Has solicitado recuperar tu contraseña. Haz clic en el siguiente enlace para restablecerla:</p>
<p><a href="{{enlace_recuperacion}}" style="display:inline-block;background:#2d5aa0;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;">Restablecer contraseña</a></p>
<p>O copia este enlace en tu navegador:<br>{{enlace_recuperacion}}</p>
<p><strong>Este enlace expira en 24 horas.</strong></p>
<p>Si no solicitaste este correo, puedes ignorarlo. Tu contraseña actual no cambiará hasta que actives el enlace.</p>',
    'nombre_usuario, enlace_recuperacion',
    TRUE
) ON CONFLICT (name) DO NOTHING;

-- Template: compartir-enlace
INSERT INTO correo_plantillas (name, display_name, subject, body_html, variables, is_active)
VALUES (
    'compartir-enlace',
    'Compartir enlace',
    'Te han compartido un archivo - {{nombre_remitente}}',
    '<h1>Te han compartido un archivo</h1>
<p>Hola {{nombre_destinatario}},</p>
<p><strong>{{nombre_remitente}}</strong> te ha compartido un archivo:</p>
<div style="background:#f5f5f5;padding:16px;border-radius:8px;margin:16px 0;">
<p style="margin:0;font-size:18px;"><i class="fas fa-file"></i> <strong>{{nombre_archivo}}</strong></p>
</div>
<p><a href="{{enlace_compartido}}" style="display:inline-block;background:#2d5aa0;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;">Ver archivo</a></p>
<p>O copia este enlace:<br>{{enlace_compartido}}</p>
<p>Saludos,<br>El equipo de TCloud</p>',
    'nombre_destinatario, nombre_remitente, nombre_archivo, enlace_compartido',
    TRUE
) ON CONFLICT (name) DO NOTHING;

-- =====================================================
-- VERIFICACIÓN
-- =====================================================

-- Ver tablas creadas
SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE 'correo_%';

-- Ver configuración
SELECT id, host, port, secure, user, from_name, from_email, is_active FROM correo_config;

-- Ver plantillas
SELECT name, display_name, subject FROM correo_plantillas;

-- Ver logs (vacío inicialmente)
SELECT COUNT(*) as total_logs FROM correo_log;
