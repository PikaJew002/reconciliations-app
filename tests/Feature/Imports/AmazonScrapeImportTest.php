<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Imports\ImporterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AmazonScrapeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson(route('api.amazon.import'), $this->scrapePayload())
            ->assertUnauthorized();
    }

    public function test_tokens_without_import_ability_are_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['other']);

        $this->postJson(route('api.amazon.import'), $this->scrapePayload())
            ->assertForbidden();
    }

    public function test_authenticated_token_queues_an_amazon_scrape_import(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['amazon:import']);

        $payload = $this->scrapePayload();

        $response = $this->postJson(route('api.amazon.import'), $payload);

        $batch = ImportBatch::query()->first();

        $this->assertNotNull($batch);
        $this->assertSame($user->id, $batch->user_id);
        $this->assertSame('amazon', $batch->source);
        $this->assertSame('orders', $batch->type);
        $this->assertSame('pending', $batch->status);
        $this->assertSame('amazon-scrape.json', $batch->original_filename);
        $this->assertSame('scrape_json', $batch->metadata['format']);
        $this->assertSame('2026-08-17T03:29:22.995Z', $batch->metadata['scraped_at']);
        $this->assertStringEndsWith('.json', $batch->storage_path);
        Storage::disk('local')->assertExists($batch->storage_path);

        $stored = json_decode(Storage::disk('local')->get($batch->storage_path), true);
        $this->assertSame($payload['details'][0]['orderNumber'], $stored['details'][0]['orderNumber']);

        Queue::assertPushed(ProcessImportBatch::class, function (ProcessImportBatch $job) use ($batch) {
            return $job->importBatch->is($batch);
        });

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Amazon order import queued.',
                'batch_id' => $batch->id,
            ]);
    }

    public function test_details_array_is_required(): void
    {
        Storage::fake('local');
        Queue::fake();

        Sanctum::actingAs(User::factory()->create(), ['amazon:import']);

        $this->postJson(route('api.amazon.import'), [
            'scrapedAt' => '2026-08-17T03:29:22.995Z',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('details');

        $this->assertSame(0, ImportBatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_job_imports_amazon_orders_from_scrape_json(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/amazon-scrape.json';

        Storage::disk('local')->put($path, json_encode($this->scrapePayload([
            'details' => [
                $this->cardOnlyDetail(),
                $this->quantityDetail(),
                $this->giftOnlyDetail(),
                $this->splitPaymentDetail(),
                [
                    'success' => false,
                    'orderNumber' => '114-0000000-0000000',
                    'data' => [
                        'orderNumber' => '114-0000000-0000000',
                        'orderDate' => 'August 1, 2026',
                        'summary' => [
                            'items_subtotal' => 10,
                            'estimated_tax_to_be_collected' => 1,
                            'grand_total' => 11,
                        ],
                        'shipments' => [],
                    ],
                ],
            ],
        ])));

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [
                'format' => 'scrape_json',
            ],
        ]);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(4, $batch->record_count);

        $orders = Order::query()->orderBy('order_number')->get()->keyBy('order_number');

        $this->assertCount(4, $orders);
        $this->assertSame('amazon', $orders['111-0000002-0000002']->merchant->normalized_name);

        $cardOnly = $orders['111-0000002-0000002'];
        $this->assertSame('7.39', $cardOnly->total);
        $this->assertSame('6.97', $cardOnly->subtotal);
        $this->assertSame('0.42', $cardOnly->tax);
        $this->assertSame('1111', $cardOnly->payment_last_four);
        $this->assertSame('2026-08-07', $cardOnly->ordered_at->toDateString());
        $this->assertCount(1, $cardOnly->metadata['payments']);
        $this->assertSame('card', $cardOnly->metadata['payments'][0]['kind']);
        $this->assertSame(7.39, $cardOnly->metadata['payments'][0]['amount']);
        $this->assertSame('Mastercard ending in 1111', $cardOnly->metadata['payments'][0]['ending']);

        $qtyOrder = $orders['111-0000004-0000004'];
        $this->assertSame('77.94', $qtyOrder->subtotal);
        $this->assertSame('82.62', $qtyOrder->total);
        $item = OrderItem::query()->where('order_id', $qtyOrder->id)->first();
        $this->assertSame('12.99', $item->unit_price);
        $this->assertSame('77.94', $item->extended_price);
        $this->assertSame(6.0, (float) $item->quantity);
        $this->assertSame('B0FKMYKH3H', $item->sku);

        $giftOnly = $orders['111-0000006-0000006'];
        $this->assertSame('15.84', $giftOnly->total);
        $this->assertNull($giftOnly->payment_last_four);
        $this->assertCount(1, $giftOnly->metadata['payments']);
        $this->assertSame('gift_card', $giftOnly->metadata['payments'][0]['kind']);
        $this->assertSame(15.84, $giftOnly->metadata['payments'][0]['amount']);

        $split = $orders['111-0000007-0000007'];
        $this->assertSame('41.37', $split->total);
        $this->assertSame('39.78', $split->subtotal);
        $this->assertSame('0.80', $split->discount);
        $this->assertNull($split->payment_last_four);
        $this->assertCount(2, $split->metadata['payments']);
        $this->assertSame('card', $split->metadata['payments'][0]['kind']);
        $this->assertSame(7.21, $split->metadata['payments'][0]['amount']);
        $this->assertSame('4444', $split->metadata['payments'][0]['last_four']);
        $this->assertSame('gift_card', $split->metadata['payments'][1]['kind']);
        $this->assertSame(34.16, $split->metadata['payments'][1]['amount']);

        $this->assertSame(5, OrderItem::query()->count());
        $this->assertDatabaseMissing('orders', ['order_number' => '114-0000000-0000000']);
    }

    public function test_job_imports_orders_from_sample_scrape_payload(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/amazon-scrape-v2.json';
        $payload = file_get_contents(base_path('tests/Fixtures/amazon-scrape-v2.json'));

        $this->assertNotFalse($payload);

        Storage::disk('local')->put($path, $payload);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [
                'format' => 'scrape_json',
            ],
        ]);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(8, $batch->record_count);
        $this->assertSame(8, Order::query()->count());
        $this->assertSame(9, OrderItem::query()->count());
        $this->assertDatabaseHas('orders', [
            'order_number' => '111-0000008-0000008',
            'total' => 42.28,
        ]);
        $this->assertSame(
            2,
            OrderItem::query()
                ->whereHas('order', fn ($query) => $query->where('order_number', '111-0000008-0000008'))
                ->count(),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function scrapePayload(array $overrides = []): array
    {
        return [
            'scrapedAt' => '2026-08-17T03:29:22.995Z',
            'summary' => [
                'page' => 'summary',
                'orderCount' => 1,
                'orders' => [],
            ],
            'details' => [
                $this->cardOnlyDetail(),
            ],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cardOnlyDetail(): array
    {
        return [
            'success' => true,
            'orderNumber' => '111-0000002-0000002',
            'data' => [
                'orderNumber' => '111-0000002-0000002',
                'orderDate' => 'August 7, 2026',
                'paymentMethod' => 'Mastercardending in 1111',
                'summary' => [
                    'items_subtotal' => 6.97,
                    'estimated_tax_to_be_collected' => 0.42,
                    'grand_total' => 7.39,
                ],
                'shipments' => [
                    [
                        'status' => 'Delivered August 8',
                        'items' => [
                            [
                                'title' => 'Carabiner',
                                'asin' => 'B0B6R34RD4',
                                'quantity' => 1,
                                'unitPrice' => 6.97,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function quantityDetail(): array
    {
        return [
            'success' => true,
            'orderNumber' => '111-0000004-0000004',
            'data' => [
                'orderNumber' => '111-0000004-0000004',
                'orderDate' => 'August 5, 2026',
                'paymentMethod' => 'Mastercardending in 2222',
                'summary' => [
                    'items_subtotal' => 77.94,
                    'estimated_tax_to_be_collected' => 4.68,
                    'grand_total' => 82.62,
                ],
                'shipments' => [
                    [
                        'status' => 'Delivered August 7',
                        'items' => [
                            [
                                'title' => 'Spa Set',
                                'asin' => 'B0FKMYKH3H',
                                'quantity' => 6,
                                'unitPrice' => 12.99,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function giftOnlyDetail(): array
    {
        return [
            'success' => true,
            'orderNumber' => '111-0000006-0000006',
            'data' => [
                'orderNumber' => '111-0000006-0000006',
                'orderDate' => 'July 21, 2026',
                'paymentMethod' => 'Visaending in 4444',
                'summary' => [
                    'items_subtotal' => 14.94,
                    'estimated_tax_to_be_collected' => 0.9,
                    'gift_card_amount' => -15.84,
                    'grand_total' => 0,
                ],
                'shipments' => [
                    [
                        'status' => 'Delivered July 22',
                        'items' => [
                            [
                                'title' => 'Tonies Cinderella',
                                'asin' => 'B08FCYMXTD',
                                'quantity' => 1,
                                'unitPrice' => 14.94,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function splitPaymentDetail(): array
    {
        return [
            'success' => true,
            'orderNumber' => '111-0000007-0000007',
            'data' => [
                'orderNumber' => '111-0000007-0000007',
                'orderDate' => 'July 21, 2026',
                'paymentMethod' => 'Visaending in 4444',
                'summary' => [
                    'items_subtotal' => 39.78,
                    'shipping__handling' => 2.99,
                    'free_shipping' => -2.99,
                    'amazon_discount' => -0.8,
                    'estimated_tax_to_be_collected' => 2.39,
                    'gift_card_amount' => -34.16,
                    'grand_total' => 7.21,
                ],
                'shipments' => [
                    [
                        'status' => 'Delivered July 22',
                        'items' => [
                            [
                                'title' => 'Tonies Ginny',
                                'asin' => 'B0GYGNZN9D',
                                'quantity' => 1,
                                'unitPrice' => 19.99,
                            ],
                            [
                                'title' => 'Tonies Ryder',
                                'asin' => 'B0FN6HVP7N',
                                'quantity' => 1,
                                'unitPrice' => 19.79,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
