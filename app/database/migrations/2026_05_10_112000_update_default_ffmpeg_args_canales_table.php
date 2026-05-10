<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $radioDefaults = [
            'ffmpeg_args_pre' => '-re',
            'ffmpeg_args_post' => '-acodec libmp3lame',
            'duracion_grabacion' => '00:21:00',
            'formato_salida' => '.mp3',
        ];

        $tvDefaults = [
            'ffmpeg_args_pre' => '-re',
            'ffmpeg_args_post' => '-c copy',
            'duracion_grabacion' => '00:21:00',
            'formato_salida' => '.mp4',
        ];

        DB::table('canales')
            ->join('grabadores', 'canales.grabador_id', '=', 'grabadores.id')
            ->where('grabadores.tipo', 'radio')
            ->where(function ($q) {
                $q->whereNull('canales.ffmpeg_args_post')
                  ->orWhere('canales.ffmpeg_args_post', '');
            })
            ->update($radioDefaults);

        DB::table('canales')
            ->join('grabadores', 'canales.grabador_id', '=', 'grabadores.id')
            ->where('grabadores.tipo', 'tv')
            ->where(function ($q) {
                $q->whereNull('canales.ffmpeg_args_post')
                  ->orWhere('canales.ffmpeg_args_post', '');
            })
            ->update($tvDefaults);
    }

    public function down(): void
    {
        DB::table('canales')
            ->whereNotNull('id')
            ->update([
                'ffmpeg_args_pre' => null,
                'ffmpeg_args_post' => null,
            ]);
    }
};
