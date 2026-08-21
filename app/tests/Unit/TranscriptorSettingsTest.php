<?php

namespace Tests\Unit;

use App\Services\Ia\TranscriptorSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\LaravelTestCase;

/**
 * La capa de settings en caliente.
 *
 * Los overrides "persistidos" se simulan sembrando la cache que map() consulta,
 * asi que estos tests no necesitan base de datos.
 */
class TranscriptorSettingsTest extends LaravelTestCase
{
    private const CACHE_KEY = 'transcriptor:settings';

    private function settings(): TranscriptorSettings
    {
        return new TranscriptorSettings();
    }

    /** Simula filas en system_settings sin tocar la BD. */
    private function seedOverrides(array $map): void
    {
        $prefixed = [];
        foreach ($map as $k => $v) {
            $prefixed['transcriptor.' . $k] = (string) $v;
        }
        Cache::put(self::CACHE_KEY, $prefixed, 60);
    }

    // ------------------------------------------------------------ resolucion

    public function testSinOverrideDevuelveElDefaultDeConfig(): void
    {
        Cache::forget(self::CACHE_KEY);

        $this->assertSame(config('transcriptor.target_redis_queue'), $this->settings()->int('target_redis_queue'));
        $this->assertSame(140, $this->settings()->int('target_redis_queue'));
    }

    public function testElOverrideDeBdGanaSobreConfig(): void
    {
        $this->seedOverrides(['target_redis_queue' => 300]);

        $this->assertSame(300, $this->settings()->int('target_redis_queue'));
    }

    public function testValorFueraDeRangoSeClampeaEnLectura(): void
    {
        // Una fila corrupta, o un rango que se estrecho despues de haberla
        // escrito, no debe producir un valor invalido en runtime.
        $this->seedOverrides(['inflight_max' => 9999]);
        $this->assertSame(48, $this->settings()->int('inflight_max'));

        $this->seedOverrides(['inflight_max' => -5]);
        $this->assertSame(0, $this->settings()->int('inflight_max'));
    }

    public function testEnumInvalidoCaeAlDefault(): void
    {
        $this->seedOverrides(['scope' => 'basura']);

        $this->assertSame('current_day', $this->settings()->str('scope'));
    }

    public function testBooleanoSeInterpretaDesdeSuFormaPersistida(): void
    {
        $this->seedOverrides(['dispatch_paused' => '1']);
        $this->assertTrue($this->settings()->bool('dispatch_paused'));

        $this->seedOverrides(['dispatch_paused' => '0']);
        $this->assertFalse($this->settings()->bool('dispatch_paused'));
    }

    // ------------------------------------------------------- regulador (bug)

    /**
     * El bug central: aplicar min_batch ANTES de evaluar el freno lo volvia
     * inalcanzable. Con la cola en 300, objetivo 140 y min_batch 10, la formula
     * antigua daba max(10, min(200, -155)) = 10 y el tick seguia encolando.
     */
    public function testElFrenoDelReguladorEsAlcanzableConLaColaSobreObjetivo(): void
    {
        Cache::forget(self::CACHE_KEY);

        $this->assertSame(0, $this->settings()->computeDispatchBatch(300));
        $this->assertSame(0, $this->settings()->computeDispatchBatch(5000));
    }

    public function testElFrenoActuaJustoEnElLimite(): void
    {
        Cache::forget(self::CACHE_KEY);

        // deficit = 140 - 145 + 5 = 0 -> frena
        $this->assertSame(0, $this->settings()->computeDispatchBatch(145));

        // deficit = 140 - 144 + 5 = 1 -> pasa, elevado al piso min_batch
        $this->assertSame(10, $this->settings()->computeDispatchBatch(144));
    }

    public function testConMargenAmplioSeAplicaElTechoMaxBatch(): void
    {
        Cache::forget(self::CACHE_KEY);

        // deficit = 140 - 0 + 5 = 145, por debajo de max_batch=200
        $this->assertSame(145, $this->settings()->computeDispatchBatch(0));

        $this->seedOverrides(['target_redis_queue' => 1000, 'max_batch' => 200]);
        $this->assertSame(200, $this->settings()->computeDispatchBatch(0));
    }

    public function testMinBatchCeroPermiteLotesPequenos(): void
    {
        $this->seedOverrides(['min_batch' => 0]);

        // deficit = 140 - 143 + 5 = 2
        $this->assertSame(2, $this->settings()->computeDispatchBatch(143));
    }

    // ------------------------------------------------------------ validacion

    public function testRechazaValorFueraDeRango(): void
    {
        $this->expectException(ValidationException::class);

        $this->settings()->set(['inflight_max' => 999]);
    }

    public function testRechazaClaveDesconocida(): void
    {
        $this->expectException(ValidationException::class);

        $this->settings()->set(['no_existe' => 1]);
    }

    public function testRechazaEnumInvalido(): void
    {
        $this->expectException(ValidationException::class);

        $this->settings()->set(['scope' => 'manana']);
    }

    // ------------------------------------------------------- formato de envio

    public function testElFormatoDeEnvioPorDefectoEsWav(): void
    {
        Cache::forget(self::CACHE_KEY);

        $this->assertSame('wav', $this->settings()->str('audio_output_format'));
    }

    public function testElFormatoDeEnvioAceptaWavYOpus(): void
    {
        Cache::forget(self::CACHE_KEY);

        foreach (['wav', 'opus'] as $formato) {
            [$clean, $errors] = $this->settings()->validate(['audio_output_format' => $formato]);

            $this->assertSame([], $errors);
            $this->assertSame($formato, $clean['audio_output_format']);
        }
    }

    public function testRechazaUnFormatoDeEnvioQueFfmpegNoSabeProducir(): void
    {
        $this->expectException(ValidationException::class);

        $this->settings()->set(['audio_output_format' => 'flac']);
    }

    public function testRechazaMinBatchMayorQueMaxBatch(): void
    {
        Cache::forget(self::CACHE_KEY);

        try {
            // max_batch efectivo es 200; 300 lo supera.
            $this->settings()->set(['min_batch' => 300]);
            $this->fail('Esperaba ValidationException por la invariante cruzada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('min_batch', $e->errors());
        }
    }

    public function testAceptaMinYMaxCoherentesEnviadosJuntos(): void
    {
        Cache::forget(self::CACHE_KEY);

        // La invariante se evalua sobre el estado RESULTANTE: min=300 seria
        // invalido contra el max actual (200), pero valido si max sube a 400 en
        // la misma operacion.
        [$clean, $errors] = $this->settings()->validate(['min_batch' => 300, 'max_batch' => 400]);

        $this->assertSame([], $errors, 'min/max coherentes enviados juntos no deberian fallar.');
        $this->assertSame(300, $clean['min_batch']);
        $this->assertSame(400, $clean['max_batch']);
    }

    public function testRechazaSubmitTimeoutQueAlcanzaElTimeoutDelJob(): void
    {
        try {
            $this->settings()->set(['submit_timeout' => 600]);
            $this->fail('Esperaba ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('submit_timeout', $e->errors());
        }
    }

    // --------------------------------------------------------- introspeccion

    public function testEffectiveReportaElOrigenDeCadaClave(): void
    {
        Cache::forget(self::CACHE_KEY);
        $sinOverride = $this->settings()->effective();
        $this->assertNotSame('bd', $sinOverride['target_redis_queue']['source']);

        $this->seedOverrides(['target_redis_queue' => 300]);
        $conOverride = $this->settings()->effective();
        $this->assertSame('bd', $conOverride['target_redis_queue']['source']);
        $this->assertSame(300, $conOverride['target_redis_queue']['value']);
        $this->assertSame(140, $conOverride['target_redis_queue']['default'], 'default debe seguir mostrando el valor de config, no el override.');
    }

    public function testEffectiveExponeElRangoQueLaUiNecesita(): void
    {
        $e = $this->settings()->effective();

        $this->assertSame(0, $e['inflight_max']['min']);
        $this->assertSame(48, $e['inflight_max']['max']);
        $this->assertSame(['current_day', 'unbounded'], $e['scope']['options']);
        $this->assertNotEmpty($e['inflight_max']['label']);
        $this->assertNotEmpty($e['inflight_max']['help']);
    }

    public function testValidationRulesCubreTodasLasClaves(): void
    {
        $settings = $this->settings();
        $rules = $settings->validationRules();

        foreach ($settings->keys() as $key) {
            $this->assertArrayHasKey($key, $rules, "Falta regla de validacion para '{$key}'.");
        }
    }

    public function testValidationRulesRespetaLosRangosDelEsquema(): void
    {
        $rules = $this->settings()->validationRules();

        $this->assertStringContainsString('max:48', $rules['inflight_max']);
        $this->assertStringContainsString('integer', $rules['inflight_max']);
        $this->assertStringContainsString('in:current_day,unbounded', $rules['scope']);
        $this->assertStringContainsString('boolean', $rules['dispatch_paused']);
    }

    /**
     * El esquema alimenta a la vez el accessor, la validacion y el formulario.
     * Si una clave del esquema no existe en config/transcriptor.php, el fallback
     * se pierde en silencio.
     */
    public function testTodaClaveDelEsquemaTieneRespaldoEnConfig(): void
    {
        foreach ($this->settings()->keys() as $key) {
            $this->assertNotNull(
                config("transcriptor.{$key}"),
                "La clave '{$key}' del esquema no existe en config/transcriptor.php."
            );
        }
    }

    public function testFlushInvalidaLaCache(): void
    {
        $this->seedOverrides(['target_redis_queue' => 300]);
        $settings = $this->settings();
        $this->assertSame(300, $settings->int('target_redis_queue'));

        $settings->flush();

        $this->assertNull(Cache::get(self::CACHE_KEY));
    }
}
