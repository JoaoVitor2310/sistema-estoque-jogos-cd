<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ranges_taxa_g2a', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->decimal('minimo', total: 5, places: 2);
            $table->decimal('maximo', total: 5, places: 2);
            $table->decimal('taxa', total: 5, places: 2);
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
