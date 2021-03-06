<?php

use Illuminate\Database\Seeder;
use App\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@mitrateknik.co.id',
            'phone' => '0888',
            'password' => bcrypt('mitrateknik'),
            'role' => 1,
            'status' => 1
        ]);
        // $this->call(UsersTableSeeder::class);
    }
}
