<?php

namespace App\Services\MisArchivos;

use App\Exceptions\BackupRequestException;
use App\Models\BackupRequest;
use App\Models\File;
use App\Models\Share;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ciclo de vida de las solicitudes de backup de Mis Archivos.
 *
 * Decisiones que este servicio sostiene (spec `backup-request-workflow`):
 *
 *  1. El share se crea AQUÍ, no en `ShareController::store()`. El cliente no
 *     tiene permiso sobre el storage de backup (por diseño: el árbol de
 *     producción no aparece en su listado de storages), así que la ruta
 *     normal daría 403. Debilitar `store()` para admitir este caso abriría
 *     una vía de escalada en el endpoint más usado del sistema.
 *
 *  2. La autorización es la del storage de ORIGEN: quien puede leer el
 *     canal puede pedir el master de ese canal, y solo de ese canal.
 *
 *  3. El enlace es de SOLO LECTURA y de vigencia acotada. El cliente no
 *     puede pedir enlace permanente ni ampliar la vigencia: el servicio
 *     ignora cualquier bandera de expiración que venga en la entrada.
 *
 *  4. La solicitud (no el share) es la fuente de verdad. `shares.file_id` es
 *     FK `ON DELETE CASCADE` y el árbol de backup rota: si la carpeta-día
 *     desaparece, el share muere solo y la solicitud sobrevive para poder
 *     reportar el material como no disponible.
 */
class BackupRequestService
{
    public function __construct(private readonly BackupResolver $resolver)
    {
    }

    /**
     * Ficha resuelta de una carpeta-día, para pintar el modal antes de
     * que el cliente escriba el motivo.
     *
     * @return array<string,mixed>
     */
    public function eligibility(User $user, File $sourceFolder): array
    {
        $storage = $sourceFolder->storageProvider;
        if ($storage === null) {
            throw new BackupRequestException('storage_missing', 'La carpeta no pertenece a ningún almacenamiento.', 404);
        }

        $resolved = $this->resolver->resolveDayFolder($storage, $sourceFolder->name);

        return [
            'day' => $resolved['day'],
            'date' => $resolved['date'],
            'source_storage' => [
                'id' => $storage->id,
                'name' => $storage->name,
            ],
            'backup_path' => $resolved['backup_path'],
            'available' => $resolved['available'],
            'reason' => $resolved['reason'],
            'reason_message' => $resolved['available'] ? null : $this->reasonMessage($resolved['reason']),
            'expires_at' => $this->validityUntil()->toIso8601String(),
            'can_request' => $resolved['available'] && $this->canRequest($user, $storage),
        ];
    }

    /**
     * Atiende una solicitud: resuelve la carpeta-día, crea el enlace y
     * registra la solicitud.
     *
     * @throws BackupRequestException
     */
    public function create(User $user, File $sourceFolder, string $motivo): BackupRequest
    {
        $motivo = trim($motivo);
        $minLength = (int) config('backups.min_motivo_length', 10);

        if ($motivo === '' || mb_strlen($motivo) < $minLength) {
            throw new BackupRequestException(
                'motivo_invalido',
                "Escribe un motivo de al menos {$minLength} caracteres explicando por qué necesitas el backup.",
                422,
            );
        }

        $storage = $sourceFolder->storageProvider;
        if ($storage === null) {
            throw new BackupRequestException('storage_missing', 'La carpeta no pertenece a ningún almacenamiento.', 404);
        }

        if (!$this->canRequest($user, $storage)) {
            throw new BackupRequestException(
                'no_permission',
                'No tienes permiso de lectura sobre este almacenamiento.',
                403,
            );
        }

        if (!$sourceFolder->is_folder) {
            throw new BackupRequestException('not_a_folder', 'Solo se puede pedir el backup de una carpeta.', 422);
        }

        $resolved = $this->resolver->resolveDayFolder($storage, $sourceFolder->name);

        if (!$resolved['available']) {
            // La solicitud se REGISTRA igual: el cliente pidió, y la respuesta
            // honesta es "ese día no tiene backup todavía / ya no está". Queda
            // en el historial con su motivo, sin enlace.
            $request = BackupRequest::create([
                'user_id' => $user->id,
                'source_storage_id' => $storage->id,
                'source_folder_id' => $sourceFolder->id,
                'backup_storage_id' => $resolved['backup_storage_id'],
                'day' => $resolved['day'],
                'motivo' => $motivo,
                'status' => BackupRequest::STATUS_UNAVAILABLE,
                'backup_path' => $resolved['backup_path'],
            ]);

            Log::info('backup_request.created', [
                'request_id' => $request->id,
                'actor_id' => $user->id,
                'source_storage_id' => $storage->id,
                'day' => $resolved['day'],
                'status' => BackupRequest::STATUS_UNAVAILABLE,
                'reason' => $resolved['reason'],
            ]);

            return $request;
        }

        // Idempotencia: un enlace vivo por (usuario, storage de origen, día).
        // Re-solicitar el mismo día no multiplica enlaces públicos sobre el
        // master de producción.
        $existing = $this->findLive($user, $storage, $resolved['day']);
        if ($existing !== null) {
            return $existing;
        }

        $expiresAt = $this->validityUntil();

        $request = DB::transaction(function () use ($user, $sourceFolder, $storage, $motivo, $resolved, $expiresAt) {
            $share = Share::create([
                'file_id' => $resolved['file_id'],
                'path_snapshot' => $resolved['relative_path'],
                'token' => Str::random(32),
                'password_hash' => null,
                'expires_at' => $expiresAt,
                'permissions' => 'read',
                'created_by' => $user->id,
            ]);

            return BackupRequest::create([
                'user_id' => $user->id,
                'source_storage_id' => $storage->id,
                'source_folder_id' => $sourceFolder->id,
                'backup_storage_id' => $resolved['backup_storage_id'],
                'share_id' => $share->id,
                'day' => $resolved['day'],
                'motivo' => $motivo,
                'status' => BackupRequest::STATUS_READY,
                'backup_path' => $resolved['backup_path'],
                'link_expires_at' => $expiresAt,
            ]);
        });

        Log::info('backup_request.created', [
            'request_id' => $request->id,
            'actor_id' => $user->id,
            'source_storage_id' => $storage->id,
            'day' => $resolved['day'],
            'status' => BackupRequest::STATUS_READY,
            'backup_path' => $resolved['backup_path'],
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return $request;
    }

    /**
     * Revoca una solicitud propia: el enlace deja de servir el material de
     * inmediato y la solicitud queda en `revoked`.
     *
     * @throws BackupRequestException
     */
    public function revoke(User $user, BackupRequest $request): BackupRequest
    {
        if ((int) $request->user_id !== (int) $user->id) {
            throw new BackupRequestException('no_permission', 'Esa solicitud no es tuya.', 403);
        }

        if ($request->status === BackupRequest::STATUS_REVOKED) {
            return $request;
        }

        if ($request->share_id !== null) {
            $share = Share::find($request->share_id);
            if ($share !== null) {
                // La vista pública cachea los escalares del enlace una hora:
                // sin invalidarla, el enlace seguiría sirviendo el material.
                Cache::forget("share:meta:{$share->token}");
                $share->delete();
            }
        }

        $request->update(['status' => BackupRequest::STATUS_REVOKED]);

        Log::info('backup_request.revoked', [
            'request_id' => $request->id,
            'actor_id' => $user->id,
            'day' => $request->day,
        ]);

        return $request->refresh();
    }

    /**
     * Historial de solicitudes del usuario.
     *
     * @return list<array<string,mixed>>
     */
    public function listFor(User $user): array
    {
        $requests = BackupRequest::query()
            ->where('user_id', $user->id)
            ->with(['sourceStorage', 'share'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return $requests->map(fn (BackupRequest $r) => $this->payload($r))->all();
    }

    /**
     * Payload de una solicitud para el historial del cliente.
     *
     * El enlace solo se expone cuando la solicitud está viva: estado
     * `ready`, share presente y vigencia no vencida. Un share borrado en
     * cascada o un enlace vencido dejan la solicitud sin enlace — el
     * historial explica el motivo en vez de servir un 404.
     *
     * @return array<string,mixed>
     */
    public function payload(BackupRequest $request): array
    {
        $live = $request->isLive();
        $share = $request->relationLoaded('share') ? $request->share : $request->share()->first();

        $link = null;
        if ($live && $share !== null) {
            $link = [
                'public_url' => url('/s/' . $share->token),
                'expires_at' => $request->link_expires_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $request->id,
            'day' => $request->day,
            'motivo' => $request->motivo,
            'status' => $this->effectiveStatus($request),
            'status_message' => $this->statusMessage($request),
            'source_storage' => $request->relationLoaded('sourceStorage') && $request->sourceStorage
                ? ['id' => $request->sourceStorage->id, 'name' => $request->sourceStorage->name]
                : null,
            'backup_path' => $request->backup_path,
            'created_at' => $request->created_at?->toIso8601String(),
            'link' => $link,
        ];
    }

    /**
     * Estado efectivo: una solicitud `ready` cuyo enlace ya no sirve (share
     * borrado en cascada o vigencia vencida) se reporta como no viva.
     */
    private function effectiveStatus(BackupRequest $request): string
    {
        if ($request->status !== BackupRequest::STATUS_READY) {
            return $request->status;
        }

        if ($request->link_expires_at !== null && $request->link_expires_at->isPast()) {
            return BackupRequest::STATUS_EXPIRED;
        }

        if ($request->share_id === null) {
            return BackupRequest::STATUS_UNAVAILABLE;
        }

        return BackupRequest::STATUS_READY;
    }

    private function statusMessage(BackupRequest $request): string
    {
        return match ($this->effectiveStatus($request)) {
            BackupRequest::STATUS_READY => 'El enlace está disponible.',
            BackupRequest::STATUS_EXPIRED => 'El enlace venció su vigencia. Vuelve a solicitar el backup para obtener uno nuevo.',
            BackupRequest::STATUS_REVOKED => 'Revocaste esta solicitud. El enlace ya no sirve.',
            default => 'El backup de ese día no está disponible.',
        };
    }

    private function reasonMessage(?string $reason): string
    {
        return match ($reason) {
            BackupResolver::REASON_NO_MAPPING => 'Este canal no tiene backup configurado.',
            BackupResolver::REASON_INVALID_DAY => 'Esta carpeta no corresponde a una fecha de backup.',
            BackupResolver::REASON_OUTSIDE_ROOT => 'La ruta del backup queda fuera del árbol de respaldo.',
            BackupResolver::REASON_MOUNT_DETACHED => 'El disco de backup no está disponible en este momento. Intenta más tarde.',
            BackupResolver::REASON_NOT_READY => 'El backup de este día todavía no está disponible: la producción va con retraso.',
            BackupResolver::REASON_EXPIRED_WINDOW => 'Este día ya salió de la ventana de retención del backup.',
            default => 'El backup de ese día no está disponible.',
        };
    }

    /**
     * ¿El usuario puede pedir el backup de este storage de origen?
     *
     * La autorización es la del storage de ORIGEN. El admin tiene bypass,
     * igual que en el módulo de compartidos.
     */
    private function canRequest(User $user, StorageProvider $storage): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasStoragePermission((int) $storage->id, 'read');
    }

    /**
     * Solicitud viva del mismo usuario para el mismo storage y día.
     */
    private function findLive(User $user, StorageProvider $storage, string $day): ?BackupRequest
    {
        return BackupRequest::query()
            ->where('user_id', $user->id)
            ->where('source_storage_id', $storage->id)
            ->where('day', $day)
            ->where('status', BackupRequest::STATUS_READY)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Instante de expiración del enlace: vigencia configurada (2 días por
     * defecto) desde ahora.
     */
    private function validityUntil(): Carbon
    {
        $days = max(1, (int) config('backups.link_validity_days', 2));

        return Carbon::now()->addDays($days);
    }
}
