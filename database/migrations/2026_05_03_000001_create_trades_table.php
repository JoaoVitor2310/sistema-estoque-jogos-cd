<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->json('rows'); // Array de linhas da trade (dados brutos do TSV)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
