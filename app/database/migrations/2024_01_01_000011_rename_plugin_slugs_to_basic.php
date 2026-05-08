<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            'image-viewer-pro'  => 'image-viewer-basic',
            'video-player-pro'  => 'video-player-basic',
            'audio-player-pro'  => 'audio-player-basic',
            'pdf-viewer-pro'    => 'pdf-viewer-basic',
        ];

        foreach ($renames as $old => $new) {
            DB::table('file_tool_plugins')
                ->where('slug', $old)
                ->update([
                    'slug'       => $new,
                    'resources'  => json_encode([
                        'js'  => ['/plugins/' . $new . '/viewer.js'],
                        'css' => ['/plugins/' . $new . '/viewer.css'],
                    ]),
                    'is_default' => true,
                ]);
        }

        // Fix resources for player-type plugins (js/css key differs)
        DB::table('file_tool_plugins')
            ->where('slug', 'video-player-basic')
            ->update([
                'resources' => json_encode([
                    'js'  => ['/plugins/video-player-basic/player.js'],
                    'css' => ['/plugins/video-player-basic/player.css'],
                ]),
            ]);

        DB::table('file_tool_plugins')
            ->where('slug', 'audio-player-basic')
            ->update([
                'resources' => json_encode([
                    'js'  => ['/plugins/audio-player-basic/player.js'],
                    'css' => ['/plugins/audio-player-basic/player.css'],
                ]),
            ]);

        // Set is_default on text-editor-basic too
        DB::table('file_tool_plugins')
            ->where('slug', 'text-editor-basic')
            ->update(['is_default' => true]);
    }

    public function down(): void
    {
        $renames = [
            'image-viewer-basic' => 'image-viewer-pro',
            'video-player-basic' => 'video-player-pro',
            'audio-player-basic' => 'audio-player-pro',
            'pdf-viewer-basic'   => 'pdf-viewer-pro',
        ];

        foreach ($renames as $old => $new) {
            DB::table('file_tool_plugins')
                ->where('slug', $old)
                ->update([
                    'slug'       => $new,
                    'is_default' => false,
                ]);
        }

        DB::table('file_tool_plugins')
            ->where('slug', 'text-editor-basic')
            ->update(['is_default' => false]);
    }
};
