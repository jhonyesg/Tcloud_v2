<?php

namespace Tests\Unit;

use App\Services\Ia\CorrectionService;
use PHPUnit\Framework\TestCase;

class CorrectionServiceTest extends TestCase
{
    public function testApplyToSegmentsReturnsArrayNotVoid(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'applyToSegments');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType, 'applyToSegments debe tener return type');
        $this->assertSame('array', $returnType->getName(), 'applyToSegments debe retornar array, no void');
    }

    public function testApplyToSegmentsRefParamHasTextRawOnly(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'applyToSegments');
        $param = $reflection->getParameters()[0];

        $this->assertSame('segments', $param->getName());
        $this->assertSame('array', $param->getType()->getName());
    }

    public function testApplyTextIsPrivate(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'applyText');
        $this->assertTrue($reflection->isPrivate(), 'applyText debe ser private');
    }

    public function testApplyTextHandlesEmptyCollection(): void
    {
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $result = $applyText->invoke($service, 'cualquier texto', collect([]));
        $this->assertSame('cualquier texto', $result);
    }

    public function testApplyTextHandlesStdClassCorrections(): void
    {
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            (object) ['wrong_normalized' => 'presedente', 'correct_text' => 'presidente'],
        ]);

        $result = $applyText->invoke($service, 'el presedente', $corrections);
        $this->assertSame('el presidente', $result);
    }

    public function testApplyToSegmentsWithWordBoundaryIntegration(): void
    {
        // Test de integración: simula applyToSegments con correcciones
        // in-memory (sin DB) usando reflexión para inyectar el set de
        // correcciones. Verifica que el word-boundary no rompe attractive.
        $service = new CorrectionService();
        $reflection = new \ReflectionClass($service);

        // Mockear Correction::approved() devolviendo una colección estática.
        // En lugar de mockear Eloquent, creamos un helper in-memory vía
        // reflexión directa sobre applyText (que es lo que llama internamente).
        $applyText = $reflection->getMethod('applyText');
        $applyText->setAccessible(true);

        $corrections = collect([
            (object) ['wrong_normalized' => 'active to', 'correct_text' => 'Activa tu'],
            (object) ['wrong_normalized' => 'in the world', 'correct_text' => 'en el mundo'],
        ]);

        // Caso 1: substring dentro de palabra NO se aplica.
        $result = $applyText->invoke(
            $service,
            'the attractive touristic destination',
            $corrections
        );
        $this->assertSame(
            'the attractive touristic destination',
            $result,
            'attractive no debe romperse por word-boundary'
        );

        // Caso 2: frase completa SÍ se aplica.
        $result = $applyText->invoke(
            $service,
            'peace in the world today',
            $corrections
        );
        $this->assertSame(
            'peace en el mundo today',
            $result,
            'in the world debe aplicarse como frase completa'
        );

        // Caso 3: combinación con Active to frase completa + attractive sin tocar.
        $result = $applyText->invoke(
            $service,
            'Active to Bogotá, an attractive city',
            $corrections
        );
        $this->assertSame(
            'Activa tu Bogotá, an attractive city',
            $result,
            'Active to aplica, attractive intacto'
        );
    }

    public function testApplyRetroactivelyAcceptsDaysBackParameter(): void
    {
        // Test de signature: applyRetroactively debe aceptar 4to param daysBack.
        $reflection = new \ReflectionMethod(CorrectionService::class, 'applyRetroactively');
        $params = $reflection->getParameters();

        $this->assertGreaterThanOrEqual(4, count($params), 'applyRetroactively debe aceptar al menos 4 parámetros');
        $this->assertSame('daysBack', $params[3]->getName(), '4to parámetro debe llamarse daysBack');
        $this->assertTrue($params[3]->allowsNull(), 'daysBack debe ser nullable');
    }

    public function testApplyRetroactivelyReturnsUpdatedCount(): void
    {
        // Test de signature: el return type debe ser int.
        $reflection = new \ReflectionMethod(CorrectionService::class, 'applyRetroactively');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType, 'applyRetroactively debe tener return type');
        $this->assertSame('int', $returnType->getName(), 'applyRetroactively debe retornar int');
    }
}
