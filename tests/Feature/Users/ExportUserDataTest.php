<?php

namespace Tests\Feature\Users;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Users\ExportUserDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportUserDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_exports_user_scoped_sql_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['email' => 'export@example.com']);
        $other = User::factory()->create();

        $account = Account::factory()->for($user)->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
        ]);
        Category::factory()->for($user)->expense()->create();

        BankTransaction::factory()->create([
            'user_id' => $other->id,
            'account_id' => Account::factory()->for($other)->create()->id,
            'import_batch_id' => ImportBatch::factory()->create(['user_id' => $other->id])->id,
        ]);

        $this->artisan('user:export-data', [
            'user' => 'export@example.com',
        ])->assertSuccessful();

        $directories = Storage::disk('local')->directories('user-exports');
        $this->assertCount(1, $directories);

        $sql = Storage::disk('local')->get($directories[0].'/export.sql');

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0;', $sql);
        $this->assertStringContainsString('export@example.com', $sql);
        $this->assertStringContainsString('INSERT INTO `bank_transactions`', $sql);
        $this->assertStringNotContainsString($other->email, $sql);
    }

    public function test_authenticated_user_can_download_their_export(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $export = app(ExportUserDataService::class)->export($user);

        $response = $this->actingAs($user)->get($export['download_url']);

        $response->assertOk();
        $response->assertDownload($export['download_filename']);
    }

    public function test_download_requires_authentication(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $export = app(ExportUserDataService::class)->export($user);

        $this->get($export['download_url'])->assertRedirect('/login');
    }

    public function test_download_is_forbidden_for_other_users(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $other = User::factory()->create();
        $export = app(ExportUserDataService::class)->export($user);

        $this->actingAs($other)->get($export['download_url'])->assertForbidden();
    }

    public function test_expired_export_returns_gone(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $export = app(ExportUserDataService::class)->export($user);

        $manifest = app(ExportUserDataService::class)->manifest($export['token']);
        $manifest['expires_at'] = now()->subMinute()->toIso8601String();

        Storage::disk('local')->put(
            "user-exports/{$export['token']}/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->actingAs($user)->get($export['download_url'])->assertStatus(410);
    }

    public function test_new_export_replaces_previous_export_for_same_user(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $first = app(ExportUserDataService::class)->export($user);
        $second = app(ExportUserDataService::class)->export($user);

        $this->assertNotSame($first['token'], $second['token']);
        $this->assertCount(1, Storage::disk('local')->directories('user-exports'));
        $this->assertFalse(Storage::disk('local')->exists("user-exports/{$first['token']}/export.sql"));
    }

    public function test_delete_export_command_removes_pending_export(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['email' => 'delete-export@example.com']);
        $export = app(ExportUserDataService::class)->export($user);

        $this->artisan('user:delete-export', [
            'user' => 'delete-export@example.com',
        ])->assertSuccessful();

        $this->assertCount(0, Storage::disk('local')->directories('user-exports'));
        $this->assertFalse(Storage::disk('local')->exists("user-exports/{$export['token']}/export.sql"));
    }

    public function test_delete_export_command_succeeds_when_no_export_exists(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['email' => 'no-export@example.com']);

        $this->artisan('user:delete-export', [
            'user' => 'no-export@example.com',
        ])->assertSuccessful();

        $this->assertCount(0, Storage::disk('local')->directories('user-exports'));
    }
}
