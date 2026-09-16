<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transcriptor-cancel-stuck-old-jobs: log append-only de cancelaciones upstream.
 *
 * Bitacora de cada intento de cancelacion contra el transcriptor upstream
 * (POST /v1/jobs/{id}/cancel). Es append-only: NO se actualizan ni eliminan
 * filas desde la app, solo se insertan.
 *
 * Schema:
 *  - actor_user_id (NULL para CLI manual; FK a users con nullOnDelete para acciones via UI)
 *  - job_id (id devuelto por upstream; varchar porque es hex de 32 chars)
 *  - tx_id (FK opcional a transcriptions; nullable porque la fila puede purparse)
 *  - upstream_response_code (200 | 404 | 409 | 5xx | exception)
 *  - local_state_after ('dead' | 'unchanged' | 'error')
 *  - error_message (texto opcional: excepcion capturada, mensaje upstream, etc.)
 *
 * Indices:
 *  - tca_job_idx: busquedas por job_id especifico
 *  - tca_time_idx: ventana temporal (operador revisa actividad reciente)
 *  - tca_local_state_idx: filtrar por estado local (cuantas quedaron 'dead' vs 'unchanged')
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transcriptor_cancel_audit')) {
            return;
        }

        Schema::create('transcriptor_cancel_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('job_id', 64);
            $table->unsignedBigInteger('tx_id')->nullable();
            $table->smallInteger('upstream_response_code')->unsigned();
            $table->string('local_state_after', 16);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('job_id', 'tca_job_idx');
            $table->index('created_at', 'tca_time_idx');
            $table->index('local_state_after', 'tca_local_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptor_cancel_audit');
    }
};
