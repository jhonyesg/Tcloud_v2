<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-16-transcriptor-physical-file-identity` — cleanup task 8.1:
 *
 * `allow_parent_overlap` fue un flag temporal que marcaba si un storage
 * admitía "parent overlap" (el archivo podía existir en el padre y en un
 * sub al mismo tiempo). El change Q1 lo retiro de la lógica de descubrimiento
 * y de la UI del escaneo (storage providers con transcription_enabled heredan
 * el scope via `StorageHierarchyService` en lugar de por el flag), pero la
 * columna quedó en BD por compatibilidad.
 *
 * Verificacion 2026-09-17: 0 lecturas del flag fuera de los 2 modelos que
 * aun lo declaran (StorageProvider::$fillable, StorageProvider::$casts).
 * El flag aparece en 2 comentarios que documentan que ya no se usa.
 *
 * Esta migration elimina la columna. Es aditiva (no destructiva de data,
 * solo del schema); si algun codigo no migrado la lee, PG devuelve error
 * claro. Si eso pasa en algun deploy, el rollback (`up` inverso) la recrea
 * con default false (valor que coincide con el uso previo).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE storage_providers DROP COLUMN IF EXISTS allow_parent_overlap');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE storage_providers ADD COLUMN IF NOT EXISTS allow_parent_overlap boolean NOT NULL DEFAULT false');
    }
};
