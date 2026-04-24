<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('taxas', 'fees');
        Schema::rename('recursos', 'assets');
    }

    public function down(): void
    {
        Schema::rename('fees', 'taxas');
        Schema::rename('assets', 'recursos');
    }
};
