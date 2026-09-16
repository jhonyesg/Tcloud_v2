<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Cubre el BFS de StorageProvider::resolveInheritedTranscriptionScope() y el
        // LIKE de StorageProvider::resolveRootIdFor(). Sin este indice, ambas
        // queries hacen seq scan sobre 190+ filas (190 hoy, esperable crecer)
        // y el cold-path del modulo API Transcriptor tarda 800-1500ms.
        // text_pattern_ops optimiza WHERE base_path LIKE '/algo/%' (prefijo).
        // CONCURRENTLY evita lock exclusivo de la tabla durante la creacion.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS storage_providers_base_path_pattern_idx ON storage_providers (base_path text_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS storage_providers_base_path_pattern_idx');
    }
};
