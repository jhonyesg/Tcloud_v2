<?php

namespace App\Modules\Correo\Database\Seeders;

use App\Modules\Correo\Models\CorreoPlantilla;
use Illuminate\Database\Seeder;

class CorreoPlantillaSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'bienvenida',
                'display_name' => 'Bienvenida',
                'subject' => 'Bienvenido a TCloud - {{nombre_usuario}}',
                'body_html' => $this->welcomeTemplate(),
                'variables' => 'nombre_usuario, email, fecha, app_url',
            ],
            [
                'name' => 'recuperar-password',
                'display_name' => 'Recuperación de contraseña',
                'subject' => 'Recuperar tu contraseña - TCloud',
                'body_html' => $this->resetPasswordTemplate(),
                'variables' => 'nombre_usuario, enlace_recuperacion, expiracion',
            ],
            [
                'name' => 'compartir-enlace',
                'display_name' => 'Compartir enlace',
                'subject' => 'Te han compartido un archivo - {{nombre_remitente}}',
                'body_html' => $this->shareTemplate(),
                'variables' => 'nombre_destinatario, nombre_remitente, nombre_archivo, enlace_compartido',
            ],
            [
                'name' => 'alerta-sistema',
                'display_name' => 'Alerta de sistema',
                'subject' => '[TCloud] {{titulo}}',
                'body_html' => $this->systemAlertTemplate(),
                'variables' => 'titulo, detalle, accion, fecha',
            ],
            [
                'name' => 'bienvenida-setup',
                'display_name' => 'Bienvenida con establecimiento de contraseña',
                'subject' => 'Bienvenido a TCloud - Establece tu contraseña',
                'body_html' => $this->welcomeSetupTemplate(),
                'variables' => 'nombre_usuario, email, set_password_url, expiracion',
            ],
        ];

        foreach ($templates as $template) {
            CorreoPlantilla::updateOrCreate(
                ['name' => $template['name']],
                $template
            );
        }
    }

    private function welcomeTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bienvenido a TCloud</title>
<style>
body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
.wrapper { width: 100%; max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
.header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); padding: 40px 30px; text-align: center; }
.header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; }
.header p { color: #dbeafe; margin: 8px 0 0; font-size: 14px; }
.content { padding: 32px 30px; color: #374151; font-size: 16px; line-height: 1.6; }
.content p { margin: 0 0 16px; }
.btn { display: inline-block; background: #2563eb; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; font-size: 15px; }
.footer { padding: 24px 30px; text-align: center; font-size: 12px; color: #9ca3af; background: #f9fafb; }
.highlight { background: #eff6ff; border-left: 4px solid #2563eb; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 20px 0; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Hola, {{nombre_usuario}}!</h1>
    <p>Bienvenido a TCloud. Tu plataforma de gestion de medios.</p>
  </div>
  <div class="content">
    <p>Gracias por unirte a nuestra plataforma. Tu cuenta esta lista para usar y hemos preparado todo para que empieces de inmediato.</p>
    <div class="highlight">
      <strong>Correo:</strong> {{email}}<br>
      <strong>Fecha de registro:</strong> {{fecha}}
    </div>
    <p style="text-align:center; margin-top:24px;">
      <a href="{{app_url}}" class="btn">Ir a TCloud</a>
    </p>
    <p style="font-size:13px; color:#6b7280; margin-top:24px;">Si tienes alguna pregunta, no dudes en contactar al equipo de soporte.</p>
  </div>
  <div class="footer">
    TCloud &copy; {{fecha}}. Todos los derechos reservados.
  </div>
</div>
</body>
</html>
HTML;
    }

    private function resetPasswordTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recuperar contraseña</title>
<style>
body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
.wrapper { width: 100%; max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
.header { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); padding: 40px 30px; text-align: center; }
.header h1 { color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; }
.content { padding: 32px 30px; color: #374151; font-size: 16px; line-height: 1.6; }
.btn { display: inline-block; background: #f59e0b; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; font-size: 15px; }
.footer { padding: 24px 30px; text-align: center; font-size: 12px; color: #9ca3af; background: #f9fafb; }
.expiry { font-size: 13px; color: #6b7280; margin-top: 24px; text-align: center; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Recuperacion de contrasena</h1>
  </div>
  <div class="content">
    <p>Hola <strong>{{nombre_usuario}}</strong>,</p>
    <p>Hemos recibido una solicitud para restablecer la contrasena de tu cuenta en TCloud.</p>
    <p style="text-align:center; margin: 28px 0;">
      <a href="{{enlace_recuperacion}}" class="btn">Restablecer contrasena</a>
    </p>
    <div class="expiry">Este enlace expira el {{expiracion}} (24 horas desde que se solicito). Si no lo usas antes de esa fecha y hora, tendras que pedir uno nuevo desde la pantalla de inicio de sesion.</div>
    <p style="font-size:13px; color:#6b7280; margin-top:24px;">Si no solicitaste este correo, ignoralo. Tu cuenta esta segura.</p>
  </div>
  <div class="footer">
    TCloud. Soporte de seguridad.
  </div>
</div>
</body>
</html>
HTML;
    }

    private function shareTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Te han compartido un archivo</title>
<style>
body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
.wrapper { width: 100%; max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
.header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 40px 30px; text-align: center; }
.header h1 { color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; }
.content { padding: 32px 30px; color: #374151; font-size: 16px; line-height: 1.6; }
.file-box { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 18px 20px; border-radius: 8px; margin: 20px 0; }
.file-box strong { color: #065f46; font-size: 15px; }
.btn { display: inline-block; background: #10b981; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; font-size: 15px; }
.footer { padding: 24px 30px; text-align: center; font-size: 12px; color: #9ca3af; background: #f9fafb; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Te han compartido un archivo</h1>
  </div>
  <div class="content">
    <p>Hola <strong>{{nombre_destinatario}}</strong>,</p>
    <p><strong>{{nombre_remitente}}</strong> te ha compartido un archivo a traves de TCloud:</p>
    <div class="file-box">
      <strong>{{nombre_archivo}}</strong>
    </div>
    <p style="text-align:center; margin-top:24px;">
      <a href="{{enlace_compartido}}" class="btn">Ver archivo</a>
    </p>
  </div>
  <div class="footer">
    TCloud - Compartir archivos de forma segura.
  </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * Aviso operativo, no comercial: lo lee un administrador que necesita saber
     * en cinco segundos que se rompio y que mirar. Sin degradados ni botones.
     */
    private function systemAlertTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Alerta de sistema - TCloud</title>
<style>
body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
.wrapper { width: 100%; max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
.header { background: #b91c1c; padding: 24px 30px; }
.header h1 { color: #ffffff; margin: 0; font-size: 20px; font-weight: 700; }
.header p { color: #fecaca; margin: 6px 0 0; font-size: 13px; }
.content { padding: 28px 30px; color: #374151; font-size: 15px; line-height: 1.6; }
.content p { margin: 0 0 16px; }
.box { background: #f9fafb; border-left: 4px solid #b91c1c; padding: 14px 16px; margin: 0 0 20px; font-size: 14px; }
.label { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; margin-bottom: 4px; }
.footer { padding: 18px 30px; background: #f9fafb; color: #6b7280; font-size: 12px; text-align: center; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>{{titulo}}</h1>
    <p>{{fecha}}</p>
  </div>
  <div class="content">
    <div class="box">
      <span class="label">Que pasa</span>
      {{detalle}}
    </div>
    <div class="box">
      <span class="label">Que revisar</span>
      {{accion}}
    </div>
    <p>Este aviso lo genera un centinela automatico de TCloud. Mientras la causa siga activa no se repetira el correo durante las proximas horas, pero si quedara registro en laravel.log.</p>
  </div>
  <div class="footer">
    TCloud - Aviso automatico de sistema.
  </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * Bienvenida con link para que el nuevo usuario establezca su propia
     * contraseña. Usada por el flujo automático al crear el user; el admin
     * ya NO escribe la contraseña inicial.
     */
    private function welcomeSetupTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bienvenido a TCloud - Establece tu contraseña</title>
<style>
body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
.wrapper { width: 100%; max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
.header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); padding: 40px 30px; text-align: center; }
.header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; }
.header p { color: #dbeafe; margin: 8px 0 0; font-size: 14px; }
.content { padding: 32px 30px; color: #374151; font-size: 16px; line-height: 1.6; }
.content p { margin: 0 0 16px; }
.btn { display: inline-block; background: #2563eb; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; font-size: 15px; }
.footer { padding: 24px 30px; text-align: center; font-size: 12px; color: #9ca3af; background: #f9fafb; }
.highlight { background: #eff6ff; border-left: 4px solid #2563eb; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 20px 0; }
.expiry { font-size: 13px; color: #6b7280; margin-top: 24px; text-align: center; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Hola, {{nombre_usuario}}!</h1>
    <p>Te han creado una cuenta en TCloud.</p>
  </div>
  <div class="content">
    <p>El administrador de TCloud registro tu correo <strong>{{email}}</strong> como una nueva cuenta. Para empezar a usarla, establece tu propia contraseña haciendo click en el siguiente boton:</p>
    <p style="text-align:center; margin: 28px 0;">
      <a href="{{set_password_url}}" class="btn">Establecer mi contraseña</a>
    </p>
    <div class="expiry">Este enlace expira el {{expiracion}}. Si no lo usas antes, pidele al administrador que te lo reenvie.</div>
    <p style="font-size:13px; color:#6b7280; margin-top:24px;">Si tienes alguna pregunta, contacta al equipo de soporte de TCloud.</p>
  </div>
  <div class="footer">
    TCloud. Este correo fue generado automaticamente.
  </div>
</div>
</body>
</html>
HTML;
    }
}
