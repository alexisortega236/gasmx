<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_price_imports', function (Blueprint $table) {
            $table->id();

            /*
             * Estado del proceso:
             * pending  = creado pero aún no inicia
             * running  = descarga/procesamiento en curso
             * completed = terminó correctamente
             * failed   = terminó con error
             */
            $table->string('status', 20)->default('pending');

            /*
             * Fuente y archivo descargado.
             */
            $table->string('source', 30)->default('cne');
            $table->text('source_url')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();

            /*
             * Sirve para detectar archivos repetidos y validar descargas.
             */
            $table->string('file_hash', 64)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            /*
             * Métricas del procesamiento.
             */
            $table->unsignedInteger('stations_processed')->default(0);
            $table->unsignedInteger('stations_created')->default(0);
            $table->unsignedInteger('stations_updated')->default(0);

            $table->unsignedInteger('prices_processed')->default(0);
            $table->unsignedInteger('prices_created')->default(0);
            $table->unsignedInteger('prices_skipped')->default(0);

            $table->unsignedInteger('errors_count')->default(0);

            /*
             * Fechas reales del job.
             */
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            /*
             * Para guardar un resumen técnico o excepción sin romper la importación.
             */
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('file_hash');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_price_imports');
    }
};
