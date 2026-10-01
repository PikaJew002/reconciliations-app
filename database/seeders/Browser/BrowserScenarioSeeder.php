<?php

namespace Database\Seeders\Browser;

use App\Models\User;
use Illuminate\Database\Seeder;

abstract class BrowserScenarioSeeder extends Seeder
{
    abstract public function run(User $user): void;
}
