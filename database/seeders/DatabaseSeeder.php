<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Account::create([
            'name' => 'Chase Checking',
            'institution_name' => 'Chase',
            'account_name' => 'Total Checking',
            'account_type' => Account::CHECKING,
            'last_four' => '1234',
        ]);

        Account::create([
            'name' => 'Discover Card',
            'institution_name' => 'Discover',
            'account_name' => 'Cashback',
            'account_type' => Account::CREDIT_CARD,
            'last_four' => '9876',
        ]);
    }
}
