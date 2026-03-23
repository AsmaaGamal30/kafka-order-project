<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\KafkaProducerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CancelOrderTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::create([
            'id' => Str::uuid(),
            'name' => 'Widget',
            'price' => 10.00,
            'stock' => 50,
        ]);

        $this->order = Order::create([
            'customer_name' => 'Test User',
            'customer_email' => 'test@example.com',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Widget',
                    'quantity' => 2,
                    'unit_price' => 10.00,
                    'line_total' => 20.00,
                ],
            ],
            'total' => 20.00,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_cancels_a_pending_order_and_publishes_kafka_event(): void
    {
        $kafkaMock = Mockery::mock(KafkaProducerService::class);
        $kafkaMock->shouldReceive('publishOrderCancelled')
            ->once()
            ->withArgs(
                fn($payload) =>
                $payload['event_type'] === 'order.cancelled' &&
                $payload['order_id'] === $this->order->id
            );

        $this->app->instance(KafkaProducerService::class, $kafkaMock);

        $response = $this->postJson("/api/orders/{$this->order->id}/cancel", [
            'reason' => 'Changed my mind',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Order cancelled successfully.')
            ->assertJsonPath('order.status', 'cancelled')
            ->assertJsonPath('order.cancellation_reason', 'Changed my mind');

        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => 'cancelled',
        ]);
    }

    #[Test]
    public function it_cannot_cancel_an_already_cancelled_order(): void
    {
        $this->order->update(['status' => 'cancelled']);

        $kafkaMock = Mockery::mock(KafkaProducerService::class);
        $kafkaMock->shouldReceive('publishOrderCancelled')->never();
        $this->app->instance(KafkaProducerService::class, $kafkaMock);

        $response = $this->postJson("/api/orders/{$this->order->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => "Order [{$this->order->id}] cannot be cancelled. Current status: cancelled."]);
    }

    #[Test]
    public function it_returns_404_for_unknown_order(): void
    {
        $response = $this->postJson('/api/orders/non-existent-id/cancel');
        $response->assertStatus(404);
    }

    #[Test]
    public function it_uses_default_cancellation_reason_when_none_provided(): void
    {
        $kafkaMock = Mockery::mock(KafkaProducerService::class);
        $kafkaMock->shouldReceive('publishOrderCancelled')->once();
        $this->app->instance(KafkaProducerService::class, $kafkaMock);

        $response = $this->postJson("/api/orders/{$this->order->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('order.cancellation_reason', 'Cancelled by customer');
    }
}