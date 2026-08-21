<?php

namespace App\Services\Ia;

use App\Models\CorrectionProtectedTerm;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Gestión de exclusiones dinámicas (corrections-protected-terms-admin).
 *
 * El admin las gestiona desde `/ia/correcciones → IA Suggest → Exclusiones`.
 * El suggester las lee como una capa adicional sobre `protected_brands`
 * (config) para que el prompt y el post-filtro PHP cubran los mismos términos.
 *
 * Cache:
 *  - `correction_protected_terms:active` → array plano de strings lowercased,
 *    TTL 300s (5min). El cache se invalida en add/archive/restore.
 *  - `correction_protected_terms:all` → lista detallada para UI, TTL 60s.
 *    (La UI la refresca manualmente al mutar, así que 60s es defensa.)
 */
class CorrectionProtectedTermsService
{
    public const CACHE_KEY_ACTIVE = 'correction_protected_terms:active';
    public const CACHE_KEY_ALL = 'correction_protected_terms:all';
    public const TTL_ACTIVE = 300;
    public const TTL_ALL = 60;

    /**
     * Array plano de términos lowercased (para `looksLikeBrandOrProperNoun`).
     *
     * @return array<int, string>
     */
    public function terms(): array
    {
        return Cache::remember(
            self::CACHE_KEY_ACTIVE,
            self::TTL_ACTIVE,
            fn() => CorrectionProtectedTerm::termsListActive()
        );
    }

    /**
     * Lista detallada para UI (activas + archivadas ordenadas por reciente).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return Cache::remember(
            self::CACHE_KEY_ALL,
            self::TTL_ALL,
            function () {
                return CorrectionProtectedTerm::query()
                    ->with('createdBy:id,username')
                    ->orderByRaw('archived_at IS NOT NULL')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn(CorrectionProtectedTerm $t) => [
                        'id' => $t->id,
                        'term' => $t->term,
                        'category' => $t->category,
                        'notes' => $t->notes,
                        'created_by_username' => $t->createdBy?->username ?? '—',
                        'created_at' => $t->created_at?->toIso8601String(),
                        'archived_at' => $t->archived_at?->toIso8601String(),
                    ])
                    ->all();
            }
        );
    }

    /**
     * Agregar término. Normaliza a lowercase + trim. Tira \InvalidArgumentException
     * si vacío o duplicado entre activos (el constraint parcial UNIQUE de la DB
     * es la segunda barrera).
     */
    public function add(string $term, ?string $category, ?string $notes, User $by): CorrectionProtectedTerm
    {
        $normalized = mb_strtolower(trim($term));

        if ($normalized === '') {
            throw new \InvalidArgumentException('El término no puede estar vacío.');
        }
        if (mb_strlen($normalized) > 120) {
            throw new \InvalidArgumentException('El término no puede superar 120 caracteres.');
        }
        if ($category !== null && !array_key_exists($category, CorrectionProtectedTerm::CATEGORIES)) {
            throw new \InvalidArgumentException("Categoría '{$category}' inválida.");
        }

        // Chequeo de unicidad rápido antes de pegar en la DB (mejor mensaje de error).
        $exists = CorrectionProtectedTerm::query()
            ->active()
            ->where('term', $normalized)
            ->exists();
        if ($exists) {
            throw new \InvalidArgumentException("'{$normalized}' ya existe entre las exclusiones activas.");
        }

        $row = CorrectionProtectedTerm::create([
            'term' => $normalized,
            'category' => $category,
            'notes' => $notes,
            'created_by' => $by->id,
        ]);

        $this->bustCache();

        return $row;
    }

    /**
     * Soft-archive: set archived_at = now(). Idempotente.
     *
     * NOTA: usamos asignación + save() en lugar de $row->update([...]) porque
     * Eloquent no aplica correctamente el cast `datetime` cuando se pasa
     * `now()` via array update — bug conocido en Laravel con soft-delete-ish
     * patterns. Asignación directa sí dispara el cast (verificado en producción
     * 2026-08-01).
     */
    public function archive(int $id): bool
    {
        $row = CorrectionProtectedTerm::find($id);
        if (!$row) {
            return false;
        }
        if ($row->archived_at === null) {
            $row->archived_at = now();
            $row->save();
            $this->bustCache();
        }
        return true;
    }

    /**
     * Restaurar un término archivado. Idempotente.
     */
    public function restore(int $id): bool
    {
        $row = CorrectionProtectedTerm::find($id);
        if (!$row) {
            return false;
        }
        if ($row->archived_at !== null) {
            $row->archived_at = null;
            $row->save();
            $this->bustCache();
        }
        return true;
    }

    public function bustCache(): void
    {
        Cache::forget(self::CACHE_KEY_ACTIVE);
        Cache::forget(self::CACHE_KEY_ALL);
    }
}
