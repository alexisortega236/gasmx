<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_price_imports', function (Blueprint $table) {
            $table->string('import_scope', 20)
                ->default('limited')
                ->after('source');

            $table->index(
                ['status', 'import_scope', 'created_at'],
                'fuel_price_imports_status_scope_created_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('fuel_price_imports', function (Blueprint $table) {
            $table->dropIndex('fuel_price_imports_status_scope_created_index');
            $table->dropColumn('import_scope');
        });
    }
};
