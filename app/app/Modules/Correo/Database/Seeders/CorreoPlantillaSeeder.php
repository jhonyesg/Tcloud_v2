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
                'body_html' => '<h1>Bienvenido {{nombre_usuario}}!</h1><p>Gracias por unirte a nuestra plataforma.</p><p>Tu cuenta está lista para usar.</p>',
                'variables' => 'nombre_usuario',
            ],
            [
                'name' => 'recuperar-password',
                'display_name' => 'Recuperación de contraseña',
                'subject' => 'Recuperar tu contraseña - TCloud',
                'body_html' => '<h1>Recuperación de contraseña</h1><p>Hola {{nombre_usuario}},</p><p>Haz clic en el siguiente enlace para restablecer tu contraseña:</p><p><a href="{{enlace_recuperacion}}">Restablecer contraseña</a></p><p>Si no solicitaste este correo, ignóralo.</p>',
                'variables' => 'nombre_usuario, enlace_recuperacion',
            ],
            [
                'name' => 'compartir-enlace',
                'display_name' => 'Compartir enlace',
                'subject' => 'Te han compartido un archivo - {{nombre_remitente}}',
                'body_html' => '<h1>Te han compartido un archivo</h1><p>Hola {{nombre_destinatario}},</p><p>{{nombre_remitente}} te ha compartido un archivo:</p><p><strong>{{nombre_archivo}}</strong></p><p><a href="{{enlace_compartido}}">Ver archivo</a></p>',
                'variables' => 'nombre_destinatario, nombre_remitente, nombre_archivo, enlace_compartido',
            ],
        ];

        foreach ($templates as $template) {
            CorreoPlantilla::updateOrCreate(
                ['name' => $template['name']],
                $template
            );
        }
    }
}
