<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda cada carga de gasolina registrada por un usuario.
     *
     * La evaluación de rendimiento no se captura necesariamente al momento
     * de cargar; puede responderse días después.
     */
    public function up(): void
    {
        Schema::create('fuel_fillups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('station_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('fuel_type', 20);

            /*
             * Fecha aproximada en la que el usuario cargó gasolina.
             */
            $table->timestamp('filled_at');

            /*
             * Momento a partir del cual ya tiene sentido preguntarle
             * cómo le rindió esa carga.
             */
            $table->timestamp('reminder_eligible_at');

            /*
             * Respuesta posterior del usuario.
             * Escala de 0 a 100.
             */
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->timestamp('performance_reported_at')->nullable();

            /*
             * Control de recordatorios para no insistir demasiado.
             */
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'filled_at']);
            $table->index(['station_id', 'filled_at']);
            $table->index(['reminder_eligible_at', 'performance_score']);
            $table->index(['user_id', 'performance_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fuel_fillups');
    }
};
