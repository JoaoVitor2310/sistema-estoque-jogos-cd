<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `tf2_qty` passou a guardar o valor acertado na moeda da trade (`currency`),
     * não necessariamente TF2. Só o nome muda: o tipo e os dados ficam, e toda
     * trade existente foi paga em TF2, então o valor continua o mesmo.
     */
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->renameColumn('tf2_qty', 'amount');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->renameColumn('amount', 'tf2_qty');
        });
    }
};
