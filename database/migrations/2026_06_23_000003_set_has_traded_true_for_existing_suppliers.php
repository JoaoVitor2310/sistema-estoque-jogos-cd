<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('suppliers')->update(['has_traded' => true]);
    }

    public function down(): void
    {
        DB::table('suppliers')->update(['has_traded' => false]);
    }
};
