<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
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
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Account::create([
            'name' => 'Joint Account 2',
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            'account_name' => 'Joint Account 2',
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
        ]);
    }
}
