<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $vips = DB::table('vips')->get();

        foreach ($vips as $vip) {
            if (! $vip->id_steam) {
                continue;
            }

            DB::table('suppliers')->upsert(
                [
                    'steam_id' => $vip->id_steam,
                    'url' => 'https://steamcommunity.com/profiles/'.$vip->id_steam,
                    'name' => $vip->name,
                    'category' => 'vip',
                    'is_added' => false,
                    'has_traded' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['steam_id'],
                ['name', 'category', 'updated_at'],
            );
        }
    }

    public function down(): void
    {
        DB::table('suppliers')->where('category', 'vip')->update([
            'name' => null,
            'category' => null,
        ]);
    }
};
