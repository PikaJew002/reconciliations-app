<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Users\ResetUserDataService;
use Database\Seeders\Browser\BrowserBaselineSeeder;
use Database\Seeders\Browser\BrowserScenarioSeeder;
use Database\Seeders\Browser\BrowserUser;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BrowserUserCommand extends Command
{
    protected $signature = 'browser:user
                            {--scenario= : Scenario prefix under Database\Seeders\Browser, without the Scenario suffix}
                            {--list : List scenario seeders}';

    protected $description = 'Create or reset the local browser user, seed baseline accounts and categories, and optionally run a scenario seeder';

    public function handle(ResetUserDataService $reset, BrowserBaselineSeeder $baseline): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('browser:user only runs in the local and testing environments.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->listScenarios();

            return self::SUCCESS;
        }

        $scenarioName = $this->option('scenario');
        $scenarioName = is_string($scenarioName) && $scenarioName !== '' ? $scenarioName : null;
        $scenario = null;

        if ($scenarioName !== null) {
            try {
                $scenario = $this->resolveScenario($scenarioName);
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $user = User::query()->where('email', BrowserUser::EMAIL)->first();

        if ($user === null) {
            $user = User::factory()->create([
                'name' => BrowserUser::NAME,
                'email' => BrowserUser::EMAIL,
            ]);
        } else {
            $reset->reset($user);
            $user->forceFill([
                'name' => BrowserUser::NAME,
                'password' => BrowserUser::PASSWORD,
                'email_verified_at' => now(),
            ])->save();
        }

        $baseline->run($user);
        $scenario?->run($user->fresh());

        $this->info('Browser user ready.');
        $this->line('Email: '.BrowserUser::EMAIL);
        $this->line('Password: '.BrowserUser::PASSWORD);
        $this->line('Login: '.url('/login'));
        $this->newLine();
        $this->line('Baseline: checking account, credit card, and Rent, Groceries, and Paycheck categories. No transactions.');

        if ($scenarioName === null) {
            $this->line('Scenario: none. Add database/seeders/Browser/{Name}Scenario.php and rerun with --scenario={Name}.');
        } else {
            $this->line("Scenario: {$scenarioName}");
        }

        return self::SUCCESS;
    }

    protected function listScenarios(): void
    {
        $names = $this->scenarioNames();

        if ($names === []) {
            $this->info('No scenario seeders in database/seeders/Browser.');

            return;
        }

        $this->info('Browser scenarios:');

        foreach ($names as $name) {
            $this->line("  {$name}");
        }
    }

    /**
     * @return list<string>
     */
    protected function scenarioNames(): array
    {
        $paths = glob(database_path('seeders/Browser/*Scenario.php')) ?: [];
        $names = [];

        foreach ($paths as $path) {
            $filename = basename($path, '.php');

            if (! str_ends_with($filename, 'Scenario')) {
                continue;
            }

            $names[] = substr($filename, 0, -strlen('Scenario'));
        }

        sort($names);

        return $names;
    }

    protected function resolveScenario(string $name): BrowserScenarioSeeder
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9]+$/', $name)) {
            throw new InvalidArgumentException('Scenario names must be a studly prefix such as UnmatchedTransactions.');
        }

        $class = "Database\\Seeders\\Browser\\{$name}Scenario";

        if (! class_exists($class) || ! is_subclass_of($class, BrowserScenarioSeeder::class)) {
            throw new InvalidArgumentException("No browser scenario [{$name}]. Expected {$class}.");
        }

        $scenario = $this->laravel->make($class);

        if (! $scenario instanceof BrowserScenarioSeeder) {
            throw new InvalidArgumentException("No browser scenario [{$name}]. Expected {$class}.");
        }

        return $scenario;
    }
}
