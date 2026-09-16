<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\Correction;
use App\Models\User;
use App\Services\Ia\AiContextCorrectService;
use App\Services\Ia\CorrectionContextFinder;
use App\Services\Ia\LlmCorrectionSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * (change: corrections-ai-context-correct-inline)
 *
 * Endpoints para corregir con IA un ejemplo individual del modal de contexto
 * y persistir el resultado como corrección pending.
 *
 * Patrón de respuesta: mismo contrato JSON que /ia/correcciones/ai-suggest-now
 * (200 ok|503 gate|409 conflict|502 upstream). El frontend reusa el mismo
 * toast / manejo de error.
 */
class CorreccionesAiContextCorrectController extends Controller
{
    public function suggest(
        int $correctionId,
        int $exampleId,
        AiContextCorrectService $service,
        CorrectionContextFinder $finder,
        Request $request
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

        $forceFresh = (bool) $request->input('force_fresh', false);

        $result = $service->suggest(
            $example,
            [
                'id' => (int) $correction->id,
                'wrong_text' => (string) $correction->wrong_text,
                'correct_text' => (string) $correction->correct_text,
                'wrong_normalized' => (string) ($correction->wrong_normalized ?? ''),
                'risk_level' => (string) ($correction->risk_level ?? ''),
            ],
            $forceFresh
        );

        if (!empty($result['ok'])) {
            return response()->json($result);
        }

        if (isset($result['api_key_source'])) {
            return response()->json($result, 503);
        }

        return response()->json($result, 503);
    }

    public function approve(
        int $correctionId,
        int $exampleId,
        AiContextCorrectService $service,
        CorrectionContextFinder $finder,
        Request $request,
        LlmCorrectionSettings $settings
    ) {
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
        if ($adminId <= 0) {
            $adminId = (int) (User::where('role', 'admin')->orderBy('id')->value('id') ?? 0);
        }

        $result = $service->approve(
            $example,
            [
                'id' => (int) $correction->id,
                'wrong_text' => (string) $correction->wrong_text,
                'correct_text' => (string) $correction->correct_text,
            ],
            $validated['wrong'],
            $validated['correct'],
            $adminId > 0 ? $adminId : null
        );

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
