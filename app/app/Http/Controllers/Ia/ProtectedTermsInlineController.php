<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ia\AiBrandSuggestionService;
use App\Services\Ia\CorrectionProtectedTermsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * (change: corrections-ai-context-aware-with-mark-curation) Endpoint de
 * curación inline de marcas protegidas desde el modal de Contexto de
 * `/ia/correcciones`. Valida el término y delega a
 * `CorrectionProtectedTermsService::addFromModal`.
 */
class ProtectedTermsInlineController extends Controller
{
    public function store(Request $request, CorrectionProtectedTermsService $service)
    {
        // Quitamos el rule "max:120" aquí: el mensaje útil lo da
        // el propio service (largo específico, multi-palabra, etc.).
        $validated = $request->validate([
            'term' => 'required|string',
            'source' => 'nullable|string|max:60',
            'example_id' => 'nullable|integer',
        ]);

        $userId = (int) (session('user_id') ?? Session::get('user_id') ?? 0);
        if ($userId <= 0) {
            return response()->json(['error' => 'No authenticated'], 401);
        }

        try {
            $result = $service->addFromModal(
                $validated['term'],
                $validated['example_id'] ?? null,
                $userId
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

/**
 * (change: corrections-ai-context-aware-with-mark-curation) Sugerencia de
 * marcas/siglas/nombres propios en un texto.
 */
public function suggestBrands(Request $request, AiBrandSuggestionService $service)
{
    $userId = (int) (session('user_id') ?? Session::get('user_id') ?? 0);
    if ($userId <= 0) {
        return response()->json(['error' => 'No authenticated'], 401);
    }

    $validated = $request->validate([
        'text' => 'required|string|max:8000',
    ]);

    $result = $service->suggestBrands($validated['text']);
    if (!empty($result['ok'])) {
        return response()->json($result);
    }
    if (isset($result['api_key_source']) || isset($result['hint'])) {
        return response()->json($result, 503);
    }
    return response()->json($result, 503);
}

/**
 * Soft-archiva una marca protegida agregada durante esta sesión. Devuelve el
 * resultado para que la UI la retire de "Protegidas ahora".
 *
 * Body: { term: string } — el admin no debería tener que buscar el id de BD.
 */
public function destroy(Request $request, CorrectionProtectedTermsService $service)
{
    $validated = $request->validate([
        'term' => 'required|string',
    ]);

    try {
        $id = $service->archiveByTerm(mb_strtolower(trim($validated['term'])));
    } catch (\InvalidArgumentException $e) {
        return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }

    if ($id === null) {
        return response()->json(['ok' => false, 'error' => 'Marca no encontrada o ya archivada.'], 404);
    }

    return response()->json(['ok' => true, 'id' => (int) $id, 'term' => mb_strtolower(trim($validated['term']))]);
}

/**
 * Lista completa de marcas activas (para el panel consolidado del modal
 * de Contexto). Devuelve {items: [{term, id, category, created_at}], total}.
 */
public function index(CorrectionProtectedTermsService $service)
{
    $items = $service->terms();
    $details = $service->listAll();
    $active = array_values(array_filter($details, fn ($r) => empty($r['archived_at'])));
    return response()->json([
        'items' => array_map(fn ($r) => [
            'term' => $r['term'] ?? '',
            'category' => $r['category'] ?? null,
            'created_at' => $r['created_at'] ?? null,
        ], $active),
        'total' => count($items),
    ]);
}
}
