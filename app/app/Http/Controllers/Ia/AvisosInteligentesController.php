<?php

namespace App\Http\Controllers\Ia;

use App\Http\Controllers\Controller;
use App\Models\Keyword;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserAlertsInteligente;
use App\Services\Ia\AlertDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvisosInteligentesController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            // Cobertura de canales del cliente: cuántos storages habilitados
            // tiene asignados. Con withCount se resuelve en la misma consulta
            // (evita el N+1 de recorrer userStorages.storageProvider en PHP).
            //
            // Qué se transcribe NO se cuenta aquí: es una decisión de API
            // Transcriptor sobre el storage, no un atributo del cliente.
            $query = User::with(['alertsInteligente'])
                ->withCount([
                    'userKeywords as keywords_count',
                    'storageProviders as storages_count' => fn ($q) => $q
                        ->where('storage_providers.enabled', true),
                    'storageProviders as storages_with_access' => fn ($q) => $q
                        ->where('user_storages.transcription_access', true),
                ]);

            if ($search = $request->input('q')) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $module = $request->input('module');
            if ($module === 'on') {
                $query->whereHas('alertsInteligente', fn ($q) => $q->where('enabled', true));
            } elseif ($module === 'off') {
                $query->where(function ($q) {
                    $q->whereDoesntHave('alertsInteligente')
                      ->orWhereHas('alertsInteligente', fn ($sq) => $sq->where('enabled', false));
                });
            }

            $users = $query->orderBy('username')->paginate(25);
            return response()->json($users);
        }

        return view('ia.avisos-inteligentes.index');
    }

    public function show(int $userId)
    {
        $user = User::with(['userKeywords', 'alertsInteligente'])->findOrFail($userId);
        $matches = $user->keywordMatches()
            ->with(['transcription.file', 'keyword'])
            ->orderByDesc('matched_at')
            ->paginate(25);

        // Canales asignados al cliente. Aquí se concede acceso a los resultados
        // que api-transcriptor produce (transcripción_access); no se decide qué
        // se transcribe. Eso sigue siendo exclusivo de /ia/api-transcriptor y se
        // refleja aquí solo como dato informativo del storage.
        $storages = $user->storageProviders()
            ->where('storage_providers.enabled', true)
            ->orderBy('storage_providers.name')
            ->get(['storage_providers.id', 'storage_providers.name', 'storage_providers.type', 'storage_providers.transcription_enabled', 'user_storages.transcription_access'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'transcription_enabled' => (bool) $s->transcription_enabled,
                'transcription_access' => (bool) $s->pivot->transcription_access,
            ])
            ->values();

        $globalStorages = StorageProvider::where('enabled', true)->count();
        $globalTranscribing = StorageProvider::transcriptionEnabled()->count();

        return view('ia.avisos-inteligentes.user-detail', [
            'user' => $user,
            'matches' => $matches,
            'storages' => $storages,
            'globalStorages' => $globalStorages,
            'globalTranscribing' => $globalTranscribing,
        ]);
    }

    public function toggleStorageAccess(Request $request, int $userId, int $storageId)
    {
        $request->validate([
            'access' => 'required|boolean',
        ]);

        $user = User::findOrFail($userId);
        $storage = StorageProvider::findOrFail($storageId);

        $pivot = DB::table('user_storages')
            ->where('user_id', $user->id)
            ->where('storage_provider_id', $storage->id)
            ->first();

        if (!$pivot) {
            return response()->json([
                'error' => 'Este storage no está asignado al cliente. Asígnalo primero en /admin/storages.',
            ], 422);
        }

        $access = $request->boolean('access');
        DB::table('user_storages')
            ->where('user_id', $user->id)
            ->where('storage_provider_id', $storage->id)
            ->update(['transcription_access' => $access]);

        return response()->json([
            'storage_id' => $storage->id,
            'transcription_access' => $access,
        ]);
    }

    public function updateUser(Request $request, int $userId)
    {
        $request->validate([
            'enabled' => 'nullable|boolean',
            'keywords_quota' => 'nullable|integer|min:0',
            'emails_quota' => 'nullable|integer|min:0',
        ]);

        $config = UserAlertsInteligente::firstOrNew(['user_id' => $userId]);
        $config->fill($request->only(['enabled', 'keywords_quota', 'emails_quota']));
        $config->save();

        return response()->json($config->fresh());
    }

    public function storeEmail(Request $request, int $userId)
    {
        $request->validate(['email' => 'required|email']);

        $config = UserAlertsInteligente::firstOrCreate(['user_id' => $userId]);
        $emails = $config->emailsList();

        if (in_array($request->email, $emails, true)) {
            return response()->json(['message' => 'Ya registrado'], 200);
        }

        if (count($emails) >= $config->emails_quota) {
            return response()->json([
                'error' => 'Cupo de correos excedido (quedan ' . max(0, $config->emails_quota - count($emails)) . ' cupos disponibles)',
            ], 422);
        }

        $emails[] = $request->email;
        $config->emails = $emails;
        $config->save();

        return response()->json(['emails' => $config->emailsList()], 201);
    }

    public function destroyEmail(int $userId, string $email)
    {
        $config = UserAlertsInteligente::where('user_id', $userId)->firstOrFail();
        $emails = array_values(array_filter($config->emailsList(), fn ($e) => $e !== $email));
        $config->emails = $emails;
        $config->save();

        return response()->json(['emails' => $config->emailsList()]);
    }

    public function storeKeyword(Request $request, int $userId)
    {
        $request->validate(['text' => 'required|string|max:200']);
        $config = UserAlertsInteligente::firstOrCreate(['user_id' => $userId]);

        $used = DB::table('user_keyword')->where('user_id', $userId)->count();
        if ($used >= $config->keywords_quota) {
            return response()->json([
                'error' => "Cupo de keywords alcanzado ({$used}/{$config->keywords_quota})",
            ], 422);
        }

        $normalized = Keyword::normalize($request->text);
        $keyword = Keyword::firstOrCreate(
            ['normalized' => $normalized],
            ['text' => trim($request->text)]
        );

        DB::table('user_keyword')->insertOrIgnore([
            'user_id' => $userId,
            'keyword_id' => $keyword->id,
            'created_at' => now(),
        ]);

        $used++;
        return response()->json([
            'keyword' => $keyword,
            'used' => $used,
            'quota' => $config->keywords_quota,
        ], 201);
    }

    public function destroyKeyword(int $userId, int $keywordId)
    {
        DB::table('user_keyword')
            ->where('user_id', $userId)
            ->where('keyword_id', $keywordId)
            ->delete();

        return response()->json(['message' => 'Keyword eliminada']);
    }

    public function testEmail(Request $request, int $userId, string $email, AlertDispatcher $dispatcher)
    {
        $user = User::findOrFail($userId);
        $result = $dispatcher->sendTest($user, $email);
        return response()->json($result, $result['success'] ?? false ? 200 : 422);
    }

    public function matches(int $userId)
    {
        $matches = User::findOrFail($userId)
            ->keywordMatches()
            ->with(['transcription.file', 'keyword'])
            ->orderByDesc('matched_at')
            ->paginate(25);

        return response()->json($matches);
    }
}