<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FileToolPluginSeeder extends Seeder
{
    public function run(): void
    {
        $plugins = [
            [
                'slug' => 'pdf-viewer-basic',
                'name' => 'Visor PDF',
                'type' => 'viewer',
                'supported_mimes' => json_encode(['application/pdf']),
                'resources' => json_encode([
                    'js' => ['/plugins/pdf-viewer-basic/viewer.js'],
                    'css' => ['/plugins/pdf-viewer-basic/viewer.css']
                ]),
                'config' => json_encode([]),
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'image-viewer-basic',
                'name' => 'Visor de Imágenes',
                'type' => 'viewer',
                'supported_mimes' => json_encode(['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml']),
                'resources' => json_encode([
                    'js' => ['/plugins/image-viewer-basic/viewer.js'],
                    'css' => ['/plugins/image-viewer-basic/viewer.css']
                ]),
                'config' => json_encode(['zoomEnabled' => true, 'panEnabled' => true]),
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'video-player-basic',
                'name' => 'Reproductor de Video',
                'type' => 'player',
                'supported_mimes' => json_encode(['video/mp4', 'video/webm', 'video/ogg']),
                'resources' => json_encode([
                    'js' => ['/plugins/video-player-basic/player.js'],
                    'css' => ['/plugins/video-player-basic/player.css']
                ]),
                'config' => json_encode(['autoplay' => false, 'controls' => true]),
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'audio-player-basic',
                'name' => 'Reproductor de Audio',
                'type' => 'player',
                'supported_mimes' => json_encode(['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/flac', 'audio/mp4']),
                'resources' => json_encode([
                    'js' => ['/plugins/audio-player-basic/player.js'],
                    'css' => ['/plugins/audio-player-basic/player.css']
                ]),
                'config' => json_encode(['autoplay' => false]),
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'text-editor-basic',
                'name' => 'Editor de Texto',
                'type' => 'editor',
                'supported_mimes' => json_encode(['text/plain', 'text/html', 'text/css', 'text/javascript', 'application/json']),
                'resources' => json_encode([
                    'js' => ['/plugins/text-editor-basic/editor.js'],
                    'css' => ['/plugins/text-editor-basic/editor.css']
                ]),
                'config' => json_encode(['theme' => 'light', 'lineNumbers' => true]),
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('file_tool_plugins')->insertOrIgnore($plugins);
    }
}
