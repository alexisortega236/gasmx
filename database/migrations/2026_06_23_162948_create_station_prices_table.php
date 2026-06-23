<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('station_id')
                ->constrained('stations')
                ->cascadeOnDelete();

            /*
             * Tipos de combustible:
             * regular = Magna
             * premium = Premium
             * diesel = Diésel
             */
            $table->string('fuel_type', 20);

            /*
             * Precio por litro.
             * 23.5999 sería un ejemplo válido.
             */
            $table->decimal('price', 8, 4);

            /*
             * Fecha/hora reportada por la fuente oficial.
             * Puede venir vacía si el XML no la incluye por registro.
             */
            $table->timestamp('reported_at')->nullable();

            /*
             * Momento en que nuestro sistema descargó e importó el dato.
             */
            $table->timestamp('imported_at');

            /*
             * Fuente del registro. Al inicio será "cne".
             * También deja espacio para futuras fuentes o datos comunitarios.
             */
            $table->string('source', 30)->default('cne');

            $table->timestamps();

            /*
             * Evita duplicar el mismo precio de una estación, combustible,
             * fuente y momento de importación.
             */
            $table->unique(
                ['station_id', 'fuel_type', 'source', 'imported_at'],
                'station_prices_unique_snapshot'
            );

            /*
             * Para consultar rápido el último precio de una estación.
             */
            $table->index(['station_id', 'fuel_type', 'imported_at']);
            $table->index('reported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_prices');
    }
};
