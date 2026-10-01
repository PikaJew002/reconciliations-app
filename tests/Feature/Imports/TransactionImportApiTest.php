<?php

namespace Tests\Feature\Imports;

use App\Http\Controllers\ApiTokens\ApiTokenController;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction()],
        ])->assertUnauthorized();
    }

    public function test_tokens_without_import_ability_are_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['amazon:import']);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction()],
        ])->assertForbidden();
    }

    public function test_transaction_import_token_accepts_sheet_rows_without_importing(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $sparse = $this->transaction([
            'description' => 'Transfer From X8955 to X1758 Daycare',
            'category' => '',
            'amount' => '$1,346.25',
            'transaction_id' => '7yEPLLP6XLhKQebKB6LrsjN94Ed79LF4rAmNE',
            'import_tag' => '',
            'check_number' => '',
            'full_description' => 'TRANSFER FROM X8955 TO X1758 DAYCARE',
            'category_hint' => 'TRANSFER_IN: TRANSFER_IN_ACCOUNT_TRANSFER',
            'categorized_by' => '',
            'categorized_date' => '',
        ]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction(), $sparse],
        ])->assertOk()
            ->assertJson([
                'received' => 2,
            ]);

        $this->assertSame(0, ImportBatch::query()->count());
        $this->assertDatabaseCount('bank_transactions', 0);
    }

    public function test_numeric_amounts_and_account_numbers_are_accepted(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'amount' => -278.04,
                'account_number' => 1758,
            ])],
        ])->assertOk()
            ->assertJson([
                'received' => 1,
            ]);
    }

    public function test_blank_description_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'description' => ' ',
            ])],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.0.description');

        $this->assertDatabaseCount('bank_transactions', 0);
    }

    public function test_invalid_amount_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'amount' => 'free',
            ])],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.0.amount');
    }

    public function test_zero_amount_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'amount' => '$0.00',
            ])],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.0.amount');
    }

    public function test_duplicate_transaction_ids_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction(), $this->transaction()],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.1.transaction_id');
    }

    public function test_empty_batch_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function transaction(array $overrides = []): array
    {
        return [
            'date' => '9/28/2026',
            'description' => 'KY Farm Bureau Kyfarm Bur',
            'category' => 'Insurance',
            'amount' => '-$278.04',
            'account' => 'Joint Account 1',
            'account_number' => '1758',
            'institution' => 'Cumberland Valley National Bank & Trust Company',
            'month' => '9/1/26',
            'week' => '9/27/26',
            'transaction_id' => 'DZDajja0pjTbvKVbnDmOT80aoXmKpvIXRy6ZZ',
            'account_id' => '6ab9a5af34a0b8d3bc2b870a',
            'import_tag' => null,
            'check_number' => null,
            'full_description' => 'KY FARM BUREAU KYFARM BUR',
            'date_added' => '9/29/26',
            'category_hint' => 'GENERAL_SERVICES: GENERAL_SERVICES_INSURANCE',
            'categorized_by' => 'Description Match',
            'categorized_date' => '9/29/26',
            'source' => 'Plaid',
            ...$overrides,
        ];
    }
}
