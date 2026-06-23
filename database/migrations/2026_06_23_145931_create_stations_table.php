<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();

            // Identidad oficial
            $table->string('permit_number')->unique();
            $table->string('name')->nullable();
            $table->string('brand')->nullable();

            // Ubicación legible
            $table->string('address')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('municipality')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 10)->nullable();

            // Coordenadas originales, útiles para depuración e importación
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Estado de la estación
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_official_update_at')->nullable();

            // Métricas preparadas para la comunidad
            $table->decimal('rating_average', 3, 2)->nullable();
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedTinyInteger('trust_score')->nullable();

            $table->timestamps();

            $table->index(['state', 'municipality']);
            $table->index('brand');
            $table->index('is_active');
        });

        /*
         * geography(Point, 4326) usa PostGIS.
         * 4326 es el sistema estándar de coordenadas GPS: longitud/latitud.
         */
        DB::statement(
            'ALTER TABLE stations ADD COLUMN location geography(Point, 4326) NULL'
        );

        DB::statement(
            'CREATE INDEX stations_location_gix ON stations USING GIST (location)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
