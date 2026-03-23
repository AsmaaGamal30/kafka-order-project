<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\KafkaProducerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'id' => Str::uuid(),
            'name' => 'Test Headphones',
            'price' => 49.99,
            'stock' => 20,
        ]);
    }

    #[Test]
    public function it_creates_an_order_and_publishes_kafka_event(): void
    {
        $kafkaMock = Mockery::mock(KafkaProducerService::class);
        $kafkaMock->shouldReceive('publishOrderCreated')
            ->once()
            ->withArgs(
                fn($payload) =>
                $payload['event_type'] === 'order.created' &&
                isset($payload['order_id'])
            );

        $this->app->instance(KafkaProducerService::class, $kafkaMock);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Ahmed Hassan',
            'customer_email' => 'ahmed@example.com',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Order placed successfully.')
            ->assertJsonPath('order.customer_email', 'ahmed@example.com')
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('order.total', 99.98);

        $this->assertDatabaseHas('orders', [
            'customer_email' => 'ahmed@example.com',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_calculates_total_correctly_for_multiple_items(): void
    {
        $product2 = Product::create([
            'id' => Str::uuid(),
            'name' => 'USB Hub',
            'price' => 20.00,
            'stock' => 10,
        ]);

        $kafkaMock = Mockery::mock(KafkaProducerService::class);
        $kafkaMock->shouldReceive('publishOrderCreated')->once();
        $this->app->instance(KafkaProducerService::class, $kafkaMock);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Sara Ali',
            'customer_email' => 'sara@example.com',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
                ['product_id' => $product2->id, 'quantity' => 3],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.total', 109.99);
    }

    #[Test]
    public function it_rejects_an_order_with_no_items(): void
    {
        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Ahmed Hassan',
            'customer_email' => 'ahmed@example.com',
            'items' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    #[Test]
    public function it_rejects_an_order_with_invalid_product_id(): void
    {
        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Ahmed Hassan',
            'customer_email' => 'ahmed@example.com',
            'items' => [
                ['product_id' => 'non-existent-uuid', 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    #[Test]
    public function it_rejects_an_order_exceeding_available_stock(): void
    {
        $kafkaMock = Mockery::mock(KafkaProducerService::class);
        $kafkaMock->shouldReceive('publishOrderCreated')->never();
        $this->app->instance(KafkaProducerService::class, $kafkaMock);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Ahmed Hassan',
            'customer_email' => 'ahmed@example.com',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 999],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient stock for product [Test Headphones]. Available: 20, requested: 999.']);
    }

    #[Test]
    public function it_rejects_missing_customer_email(): void
    {
        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Ahmed Hassan',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_email']);
    }
}