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

    /**
     * Soft-archiva un término buscándolo por su valor normalizado. Devuelve
     * el id afectado o null si no se encontró / estaba ya archivado.
     */
    public function archiveByTerm(string $term): ?int
    {
        $normalized = mb_strtolower(trim($term));
        if ($normalized === '') {
            throw new \InvalidArgumentException('Término vacío.');
        }
        $row = CorrectionProtectedTerm::query()
            ->active()
            ->where('term', $normalized)
            ->first();
        if (!$row) {
            return null;
        }
        $row->archived_at = now();
        $row->save();
        $this->bustCache();
        return (int) $row->id;
    }

    public function bustCache(): void
    {
        Cache::forget(self::CACHE_KEY_ACTIVE);
        Cache::forget(self::CACHE_KEY_ALL);
    }

    /**
     * (change: corrections-ai-context-aware-with-mark-curation) Variante
     * optimizada para el flujo inline desde el modal de Contexto. Devuelve
     * `{term, id, is_new}` y crea/recupera idempotentemente. Acepta `example_id`
     * para trazabilidad vía `notes` sin requerir al admin teclear la fuente.
     */
    public function addFromModal(string $term, ?int $exampleId, int $userId): array
    {
        $normalized = mb_strtolower(trim($term));
        if ($normalized === '') {
            throw new \InvalidArgumentException('El término no puede estar vacío.');
        }
        if (mb_strlen($normalized) < 2) {
            throw new \InvalidArgumentException('La marca debe tener al menos 2 caracteres (recibido: "' . $normalized . '").');
        }
        if (mb_strlen($normalized) > 120) {
            throw new \InvalidArgumentException(
                'La marca excede el límite de 120 caracteres (' . mb_strlen($normalized) . ' recibidos). '
                . 'Si es multi-palabra, intenta agregar solo la parte protegida por dominio.'
            );
        }
        // Rechaza selección que es claramente basura (todos espacios, separadores, etc.).
        if (preg_match('/^[\s\p{Z}\-_]+$/u', $normalized)) {
            throw new \InvalidArgumentException('La selección no parece ser una marca (solo separadores).');
        }

        // Heurística: si tiene espacios + el último char antes del trim era un
        // separador, el admin probablemente arrastró el cursor fuera de la palabra.
        // En ese caso, normalizamos: tomamos el último token significativo.
        // (Desactivado: dejamos al admin que vea explícitamente lo que se guarda
        // y pueda re-intentar — es preferible a fallar silencioso.)
        // if (substr_count($normalized, ' ') >= 1 && preg_match('/[a-zñ] $/', $term)) { ... }

        // Idempotencia: SELECT por lower(term); si existe activo, devuelve `is_new:false`.
        $existing = CorrectionProtectedTerm::query()
            ->active()
            ->where('term', $normalized)
            ->first();

        if ($existing) {
            return [
                'term' => $existing->term,
                'id' => (int) $existing->id,
                'is_new' => false,
            ];
        }

        $noteSuffix = $exampleId !== null ? " (modal-context, example_id={$exampleId})" : ' (modal-context)';
        $row = CorrectionProtectedTerm::create([
            'term' => $normalized,
            'category' => 'brand',
            'notes' => 'Agregado desde modal de Contexto.' . $noteSuffix,
            'created_by' => $userId,
        ]);

        $this->bustCache();

        return [
            'term' => $row->term,
            'id' => (int) $row->id,
            'is_new' => true,
        ];
    }
}
