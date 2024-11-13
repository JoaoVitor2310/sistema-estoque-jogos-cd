<?php

namespace Database\Seeders;

use DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AuthorizedUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('authorized_users')->insert([
            ['name' => 'Carca Deals', 'email' => 'joaovitormatosgouveia@gmail.com', 'status' => true],
        ]);
    }
}
