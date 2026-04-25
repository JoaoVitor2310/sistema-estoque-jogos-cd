<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renomeia os identificadores de taxas na tabela fees para snake_case em inglês.
 *
 * gamivoPercentualMenor → gamivo_percent_low
 * gamivoFixoMenor       → gamivo_fixed_low
 * gamivoPercentualMaior → gamivo_percent_high
 * gamivoFixoMaior       → gamivo_fixed_high
 */
return new class extends Migration
{
    private array $renames = [
        'gamivoPercentualMenor' => 'gamivo_percent_low',
        'gamivoFixoMenor'       => 'gamivo_fixed_low',
        'gamivoPercentualMaior' => 'gamivo_percent_high',
        'gamivoFixoMaior'       => 'gamivo_fixed_high',
    ];

    public function up(): void
    {
        foreach ($this->renames as $old => $new) {
            DB::table('fees')->where('name', $old)->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $old => $new) {
            DB::table('fees')->where('name', $new)->update(['name' => $old]);
        }
    }
};
