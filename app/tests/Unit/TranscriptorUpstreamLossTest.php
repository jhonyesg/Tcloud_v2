<?php

namespace Tests\Unit;

use App\Models\Transcription;
use App\Services\Ia\TranscriptorUpstreamException;
use Tests\TestCase;

/**
 * La decision de "reintentar" vs "rendirse" del poller.
 *
 * Es pura (no toca red ni BD) pero es la que evita repetir el incidente del
 * 2026-08-12: 33.571 filas del 1-5 de agosto se quedaron en `queued` para
 * siempre porque el SRT devolvia 500 y el poller trataba ese fallo como
 * transitorio, reintentandolo cada minuto de forma indefinida.
 *
 * Distinguir mal en cualquiera de los dos sentidos es caro: dar por perdido
 * algo transitorio tira trabajo bueno; dar por transitorio algo perdido
 * reproduce el atasco.
 */
class TranscriptorUpstreamLossTest extends TestCase
{
    public function testSrt500EsPerdidaDefinitiva(): void
    {
        // El caso del incidente: el job figura como done pero su fichero ya no
        // esta en disco en el transcriptor.
        $e = new TranscriptorUpstreamException('boom', 500, 'srt');

        $this->assertTrue($e->isDefinitiveLoss());
    }

    public function testSrt404EsPerdidaDefinitiva(): void
    {
        $this->assertTrue((new TranscriptorUpstreamException('boom', 404, 'srt'))->isDefinitiveLoss());
    }

    public function testJob404EsPerdidaDefinitiva(): void
    {
        $this->assertTrue((new TranscriptorUpstreamException('boom', 404, 'job'))->isDefinitiveLoss());
    }

    public function testJob5xxEsTransitorio(): void
    {
        // Un 5xx consultando el JOB puede ser el nodo reiniciando, con el job
        // intacto. Cerrar la fila aqui tiraria una transcripcion recuperable.
        $this->assertFalse((new TranscriptorUpstreamException('boom', 503, 'job'))->isDefinitiveLoss());
        $this->assertFalse((new TranscriptorUpstreamException('boom', 500, 'job'))->isDefinitiveLoss());
    }

    public function testJob4xxDistintoDe404EsTransitorio(): void
    {
        // 401/403 son de configuracion (api_key), no perdida del resultado.
        $this->assertFalse((new TranscriptorUpstreamException('boom', 401, 'job'))->isDefinitiveLoss());
    }

    public function testElMotivoUsaLosPrefijosQueBuscaElBackfill(): void
    {
        // transcription:backfill-lost selecciona candidatas por estos prefijos:
        // si el mensaje deja de empezar por ellos, las filas perdidas dejan de
        // ser rescatables y el fallo es silencioso.
        $srt = (new TranscriptorUpstreamException('boom', 500, 'srt'))->reason();
        $job = (new TranscriptorUpstreamException('boom', 404, 'job'))->reason();

        $this->assertStringStartsWith(Transcription::LOSS_MARK_SRT, $srt);
        $this->assertStringStartsWith(Transcription::LOSS_MARK_JOB, $job);
    }

    public function testElStatusSeConservaParaElLog(): void
    {
        $e = new TranscriptorUpstreamException('getJob error 404: not found', 404, 'job');

        $this->assertSame(404, $e->status());
        $this->assertSame('job', $e->operation());
        $this->assertStringContainsString('not found', $e->getMessage());
    }
}
