<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\Correction;
use App\Services\Ia\AiContextAwareService;
use App\Services\Ia\CorrectionContextFinder;
use App\Services\Ia\LlmCorrectionSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * (change: corrections-ai-context-aware-with-mark-curation)
 * Endpoints para corregir con IA un ejemplo individual del modal de contexto,
 * alimentando el LLM con los vecinos ±5. Persistencia del resultado como
 * regla pending con source='ai-context-correct-context-YYYY-MM-DD'.
 */
class CorreccionesAiContextAwareController extends Controller
{
    public function suggest(
        int $correctionId,
        int $exampleId,
        AiContextAwareService $service,
        CorrectionContextFinder $finder
    ) {
        $correction = Correction::findOrFail($correctionId);

        $examples = $finder->examples($correction);
        $example = null;
        foreach ($examples['examples'] ?? [] as $ex) {
            if ((int) ($ex['segment_id'] ?? 0) === $exampleId) {
                $example = $ex;
                break;
            }
        }

        if ($example === null) {
            return response()->json([
                'ok' => false,
                'reason' => 'El ejemplo no pertenece a esta corrección padre.',
            ], 404);
        }

        $forceFresh = (bool) request()->input('force_fresh', false);
        $neighborWindow = (int) request()->input('neighbor_window', 5);
        if ($neighborWindow < 0 || $neighborWindow > 20) {
            $neighborWindow = 5;
        }

        $result = $service->correctExample($correction, $example, $forceFresh, $neighborWindow);

        if (!empty($result['ok'])) {
            return response()->json($result);
        }
        if (isset($result['api_key_source'])) {
            return response()->json($result, 503);
        }
        return response()->json($result, 503);
    }

    public function approve(
        Request $request,
        int $correctionId,
        int $exampleId,
        AiContextAwareService $service,
        CorrectionContextFinder $finder
    ) {
        /** @var LlmCorrectionSettings $settings */
        $settings = app(LlmCorrectionSettings::class);
        if (!$settings->bool('enabled')) {
            return response()->json([
                'ok' => false,
                'reason' => 'Suggest deshabilitado desde Configuración / IA Suggest.',
                'hint' => 'Activa el toggle "Habilitado" en el tab IA Suggest.',
            ], 503);
        }

        $validated = $request->validate([
            'wrong' => 'required|string|max:2000',
            'correct' => 'required|string|max:2000',
        ]);

        $correction = Correction::findOrFail($correctionId);

        $examples = $finder->examples($correction);
        $example = null;
        foreach ($examples['examples'] ?? [] as $ex) {
            if ((int) ($ex['segment_id'] ?? 0) === $exampleId) {
                $example = $ex;
                break;
            }
        }
        if ($example === null) {
            return response()->json([
                'ok' => false,
                'reason' => 'El ejemplo no pertenece a esta corrección padre.',
            ], 404);
        }

        $adminId = (int) (session('user_id') ?? Session::get('user_id') ?? 0);

        $result = $service->approve($correction, $example, $validated['wrong'], $validated['correct'], $adminId);

        if (!empty($result['ok'])) {
            return response()->json($result, 201);
        }
        $status = match ($result['status'] ?? '') {
            'conflict' => 409,
            'invalid' => 422,
            default => 500,
        };
        return response()->json($result, $status);
    }
}
