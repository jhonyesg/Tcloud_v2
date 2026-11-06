<?php

namespace App\Http\Controllers\MisArchivos;

use App\Exceptions\BackupRequestException;
use App\Http\Controllers\Controller;
use App\Models\BackupRequest;
use App\Models\File;
use App\Models\User;
use App\Services\MisArchivos\BackupRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * API del submódulo "Solicitar backup" de Mis Archivos.
 *
 * Rutas (ver `routes/web.php`): todas cuelgan del grupo `auth` y se
 * registran ANTES de `Route::resource('files')` a propósito: el resource
 * registra `GET /files/{file}`, que se tragaría `GET /files/backup-requests`
 * (el comodíncome antes que el literal).
 */
class BackupRequestController extends Controller
{
    public function __construct(private readonly BackupRequestService $service)
    {
    }

    /**
     * Ficha resuelta de una carpeta-día: qué hay detrás, si está disponible
     * y hasta cuándo viviría el enlace. Es lo que el modal pide antes de
     * dejar escribir el motivo.
     */
    public function eligibility(Request $request): JsonResponse
    {
        $user = $this->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'file_id' => 'required|integer|exists:files,id',
        ]);

        $folder = File::find($validated['file_id']);
        if ($folder === null || !$folder->is_folder) {
            return response()->json(['error' => 'Solo se puede pedir el backup de una carpeta.'], 422);
        }

        try {
            return response()->json($this->service->eligibility($user, $folder));
        } catch (BackupRequestException $e) {
            return $this->error($e);
        }
    }

    /**
     * Historial de solicitudes del usuario.
     */
    public function index(): JsonResponse
    {
        $user = $this->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json(['data' => $this->service->listFor($user)]);
    }

    /**
     * Atiende una solicitud: crea el enlace y la registra.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'file_id' => 'required|integer|exists:files,id',
            'motivo' => 'required|string|max:2000',
        ]);

        $folder = File::find($validated['file_id']);
        if ($folder === null) {
            return response()->json(['error' => 'La carpeta no existe.'], 404);
        }

        try {
            $backupRequest = $this->service->create($user, $folder, (string) $validated['motivo']);
        } catch (BackupRequestException $e) {
            return $this->error($e);
        }

        return response()->json([
            'request' => $this->service->payload($backupRequest),
            'share' => $this->service->payload($backupRequest)['link'],
        ], 201);
    }

    /**
     * Revoca una solicitud propia.
     */
    public function destroy(int $id): JsonResponse
    {
        $user = $this->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $backupRequest = BackupRequest::find($id);
        if ($backupRequest === null) {
            return response()->json(['error' => 'La solicitud no existe.'], 404);
        }

        try {
            $this->service->revoke($user, $backupRequest);
        } catch (BackupRequestException $e) {
            return $this->error($e);
        }

        return response()->json([
            'request' => $this->service->payload($backupRequest->refresh()),
        ]);
    }

    private function user(): ?User
    {
        $userId = Session::get('user_id');

        return $userId ? User::find($userId) : null;
    }

    private function error(BackupRequestException $e): JsonResponse
    {
        Log::warning('backup_request.error', [
            'code' => $e->errorCode,
            'status' => $e->status,
        ]);

        return response()->json(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
    }
}
