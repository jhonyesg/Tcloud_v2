<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * mention-occurrence-detail: cuántas veces la keyword aparece en el
 * segmento de cada hit + backfill accent-insensitive de los existentes.
 *
 * El backfill usa una función SQL auxiliar IMMUTABLE que replica la
 * normalización del motor (Keyword::asciiLower = Str::ascii + lower).
 * La función queda instalada (documentada) para re-backfills futuros;
 * el runtime PHP no la usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('segment_keyword_hits', 'occurrences')) {
            Schema::table('segment_keyword_hits', function (Blueprint $table) {
                $table->unsignedSmallInteger('occurrences')->default(1)->after('snippet');
            });
        }

        // Normalización equivalente a Keyword::asciiLower en SQL.
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION tcloud_ascii_lower(t text) RETURNS text AS $$
    SELECT lower(translate(t, 'ÁÉÍÓÚÜÑÇáéíóúüñç', 'aeiouuncaeiouunc'));
$$ LANGUAGE sql IMMUTABLE;
SQL);

        DB::statement(<<<'SQL'
UPDATE segment_keyword_hits h
SET occurrences = GREATEST(1,
    (
        length(tcloud_ascii_lower(s.text))
        - length(replace(tcloud_ascii_lower(s.text), tcloud_ascii_lower(k.normalized), ''))
    ) / GREATEST(length(k.normalized), 1))
FROM keywords k, transcription_segments s
WHERE k.id = h.keyword_id AND s.id = h.segment_id;
SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('segment_keyword_hits', 'occurrences')) {
            Schema::table('segment_keyword_hits', function (Blueprint $table) {
                $table->dropColumn('occurrences');
            });
        }
    }
};