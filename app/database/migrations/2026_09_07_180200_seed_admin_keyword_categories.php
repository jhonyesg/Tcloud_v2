<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['Político',     'politico',     '#0EA5E9'],
            ['Artista',      'artista',      '#F59E0B'],
            ['Institución',  'institucion',  '#10B981'],
        ];

        foreach ($rows as [$name, $slug, $color]) {
            $exists = DB::table('keyword_categories')
                ->where('owner_scope', 'admin')
                ->whereNull('owner_id')
                ->where('slug', $slug)
                ->exists();

            if (!$exists) {
                DB::table('keyword_categories')->insert([
                    'owner_scope' => 'admin',
                    'owner_id'    => null,
                    'name'        => $name,
                    'slug'        => $slug,
                    'color_hex'   => $color,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('keyword_categories')
            ->where('owner_scope', 'admin')
            ->whereNull('owner_id')
            ->whereIn('slug', ['politico', 'artista', 'institucion'])
            ->delete();
    }
};
