<?php

namespace App\Modules\Correo\Http\Controllers;

use App\Modules\Correo\Models\CorreoPlantilla;
use App\Modules\Correo\Services\NotificationService;
use App\Modules\Correo\Services\PlantillaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorreoPlantillaController
{
    private PlantillaService $plantillaService;
    private NotificationService $notificationService;

    public function __construct(PlantillaService $plantillaService, NotificationService $notificationService)
    {
        $this->plantillaService = $plantillaService;
        $this->notificationService = $notificationService;
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->plantillaService->getAll()]);
    }

    public function show(string $name): JsonResponse
    {
        $plantilla = $this->plantillaService->getByName($name);
        return $plantilla 
            ? response()->json(['data' => $plantilla])
            : response()->json(['message' => 'Plantilla no encontrada'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:correo_plantillas,name',
            'display_name' => 'required|string',
            'subject' => 'required|string',
            'body_html' => 'required|string',
            'variables' => 'nullable|string',
        ]);

        $plantilla = $this->plantillaService->create($validated);
        return response()->json(['data' => $plantilla], 201);
    }

    public function update(Request $request, CorreoPlantilla $plantilla): JsonResponse
    {
        $validated = $request->validate([
            'display_name' => 'string',
            'subject' => 'string',
            'body_html' => 'string',
            'variables' => 'nullable|string',
        ]);

        $plantilla = $this->plantillaService->update($plantilla, $validated);
        return response()->json(['data' => $plantilla]);
    }

    public function destroy(CorreoPlantilla $plantilla): JsonResponse
    {
        $this->plantillaService->delete($plantilla);
        return response()->json(['message' => 'Plantilla eliminada']);
    }

    public function preview(Request $request, CorreoPlantilla $plantilla): JsonResponse
    {
        $variables = $request->input('variables', []);
        $rendered = $this->plantillaService->renderTemplate($plantilla, $variables);
        return response()->json(['data' => $rendered]);
    }

    public function sendTest(Request $request, CorreoPlantilla $plantilla): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|email',
            'variables' => 'nullable|array',
        ]);

        $variables = $validated['variables'] ?? [];
        $result = $this->notificationService->send($plantilla->name, $validated['to'], $variables);

        return response()->json($result);
    }
}
