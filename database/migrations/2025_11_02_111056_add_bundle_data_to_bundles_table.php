<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->string('url')->after('description')->nullable();
            $table->string('url_region_locks')->after('url')->nullable();
            $table->date('end_date')->after('release_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn('url');
            $table->dropColumn('url_region_locks');
            $table->dropColumn('end_date');
        });
    }
};
