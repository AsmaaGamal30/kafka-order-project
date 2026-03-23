<?php

namespace Tests\Feature;

use App\Console\Commands\ConsumeInventory;
use App\Console\Commands\ConsumeAnalytics;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Services\KafkaProducerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class KafkaConsumerTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private array $basePayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'id' => Str::uuid(),
            'name' => 'Widget',
            'price' => 25.00,
            'stock' => 10,
        ]);

        $this->basePayload = [
            'event_type' => 'order.created',
            'order_id' => Str::uuid(),
            'customer_name' => 'Test User',
            'customer_email' => 'test@example.com',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'name' => 'Widget',
                    'quantity' => 3,
                    'unit_price' => 25.00,
                    'line_total' => 75.00,
                ],
            ],
            'total' => 75.00,
            'status' => 'pending',
            'occurred_at' => now()->toIso8601String(),
        ];
    }


    #[Test]
    public function inventory_consumer_decrements_stock_on_order_created(): void
    {
        $consumer = new ConsumeInventory();

        $method = new \ReflectionMethod($consumer, 'handle');
        $method->setAccessible(true);
        $method->invoke($consumer, $this->basePayload);

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock' => 7,
        ]);
    }

    #[Test]
    public function inventory_consumer_restores_stock_on_order_cancelled(): void
    {
        $consumer = new ConsumeInventory();
        $method = new \ReflectionMethod($consumer, 'handle');
        $method->setAccessible(true);

        $method->invoke($consumer, $this->basePayload);
        $this->product->refresh();
        $this->assertEquals(7, $this->product->stock);

        $cancelPayload = array_merge($this->basePayload, ['event_type' => 'order.cancelled']);
        $method->invoke($consumer, $cancelPayload);

        $this->product->refresh();
        $this->assertEquals(10, $this->product->stock);
    }

    #[Test]
    public function inventory_consumer_throws_when_stock_is_insufficient(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $payload = array_merge($this->basePayload, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'name' => 'Widget',
                    'quantity' => 999,
                    'unit_price' => 25.00,
                    'line_total' => 24975.00,
                ]
            ],
        ]);

        $consumer = new ConsumeInventory();
        $method = new \ReflectionMethod($consumer, 'handle');
        $method->setAccessible(true);
        $method->invoke($consumer, $payload);
    }


    #[Test]
    public function analytics_consumer_writes_order_event_to_db(): void
    {
        $consumer = new ConsumeAnalytics();
        $method = new \ReflectionMethod($consumer, 'handle');
        $method->setAccessible(true);
        $method->invoke($consumer, $this->basePayload);

        $this->assertDatabaseHas('order_events', [
            'order_id' => $this->basePayload['order_id'],
            'event_type' => 'order.created',
        ]);
    }

    #[Test]
    public function base_consumer_routes_to_dlq_on_missing_fields(): void
    {
        $kafkaMock = Mockery::mock(KafkaProducerService::class);
        $kafkaMock->shouldReceive('publishToDlq')->once();
        $this->app->instance(KafkaProducerService::class, $kafkaMock);

        $consumer = new ConsumeInventory();

        $method = new ReflectionMethod($consumer, 'decodeAndValidate');
        $method->setAccessible(true);

        $result = $method->invoke(
            $consumer,
            ['event_type' => 'order.created'],
            'order.created',
            app(KafkaProducerService::class)
        );

        $this->assertNull($result);
    }
}
