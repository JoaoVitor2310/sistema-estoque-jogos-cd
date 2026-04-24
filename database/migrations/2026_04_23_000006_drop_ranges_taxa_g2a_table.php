<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ranges_taxa_g2a');
    }

    public function down(): void
    {
        Schema::create('ranges_taxa_g2a', function (Blueprint $table) {
            $table->id();
            $table->decimal('minimo', 8, 2);
            $table->decimal('maximo', 8, 2);
            $table->decimal('taxa', 8, 4);
            $table->timestamps();
        });
    }
};
