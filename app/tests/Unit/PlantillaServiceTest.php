<?php

namespace Tests\Unit;

use App\Modules\Correo\Models\CorreoPlantilla;
use App\Modules\Correo\Services\PlantillaService;
use PHPUnit\Framework\TestCase;

class PlantillaServiceTest extends TestCase
{
    private PlantillaService $plantillaService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plantillaService = new PlantillaService();
    }

    public function testRenderTemplateReplacesVariables(): void
    {
        $plantilla = new CorreoPlantilla();
        $plantilla->name = 'test';
        $plantilla->display_name = 'Test';
        $plantilla->subject = 'Hola {{nombre_usuario}}';
        $plantilla->body_html = '<p>Bienvenido {{nombre_usuario}}, tu código es {{codigo}}</p>';
        $plantilla->variables = 'nombre_usuario, codigo';

        $result = $this->plantillaService->renderTemplate($plantilla, [
            'nombre_usuario' => 'Juan',
            'codigo' => '12345',
        ]);

        $this->assertEquals('Hola Juan', $result['subject']);
        $this->assertEquals('<p>Bienvenido Juan, tu código es 12345</p>', $result['body']);
    }

    public function testRenderTemplateWithMissingVariables(): void
    {
        $plantilla = new CorreoPlantilla();
        $plantilla->name = 'test';
        $plantilla->display_name = 'Test';
        $plantilla->subject = 'Hola {{nombre_usuario}}';
        $plantilla->body_html = '<p>Código: {{codigo}}</p>';

        $result = $this->plantillaService->renderTemplate($plantilla, [
            'nombre_usuario' => 'Ana',
        ]);

        $this->assertEquals('Hola Ana', $result['subject']);
        $this->assertEquals('<p>Código: </p>', $result['body']);
    }

    public function testVariablesArrayAttribute(): void
    {
        $plantilla = new CorreoPlantilla();
        $plantilla->variables = 'nombre_usuario, email, enlace';

        $this->assertEquals(['nombre_usuario', 'email', 'enlace'], $plantilla->variables_array);
    }
}
