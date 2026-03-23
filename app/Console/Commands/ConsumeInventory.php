<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConsumeInventory extends BaseConsumer
{
    protected $signature = 'kafka:consume-inventory';
    protected $description = 'Consume order events to reserve or release inventory stock';

    protected function topic(): string
    {
        return implode(',', [
            config('kafka.topics.order_created'),
            config('kafka.topics.order_cancelled'),
        ]);
    }

    protected function groupId(): string
    {
        return config('kafka.consumer_group_id') . '-inventory';
    }

    protected function handle(array $payload): void
    {
        $eventType = $payload['event_type'];
        $orderId = $payload['order_id'];
        $items = $payload['items'];

        match ($eventType) {
            'order.created' => $this->reserveStock($orderId, $items),
            'order.cancelled' => $this->releaseStock($orderId, $items),
            default => Log::warning("[inventory] Unknown event_type [{$eventType}] — skipped"),
        };
    }

    private function reserveStock(string $orderId, array $items): void
    {
        DB::transaction(function () use ($orderId, $items) {
            foreach ($items as $item) {
                $product = Product::lockForUpdate()->find($item['product_id']);

                if (!$product) {
                    throw new \RuntimeException(
                        "Product [{$item['product_id']}] not found while reserving stock."
                    );
                }

                if ($product->stock < $item['quantity']) {
                    throw new \RuntimeException(
                        "Insufficient stock for [{$product->name}]. "
                        . "Available: {$product->stock}, required: {$item['quantity']}."
                    );
                }

                $product->decrement('stock', $item['quantity']);

                Log::info("[inventory] Stock reserved", [
                    'order_id' => $orderId,
                    'product' => $product->name,
                    'decremented' => $item['quantity'],
                    'remaining' => $product->stock - $item['quantity'],
                ]);
            }
        });
    }

    private function releaseStock(string $orderId, array $items): void
    {
        DB::transaction(function () use ($orderId, $items) {
            foreach ($items as $item) {
                $affected = Product::where('id', $item['product_id'])
                    ->increment('stock', $item['quantity']);

                if ($affected === 0) {
                    Log::warning("[inventory] Product [{$item['product_id']}] not found during stock release", [
                        'order_id' => $orderId,
                    ]);
                    continue;
                }

                Log::info("[inventory] Stock released", [
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'released' => $item['quantity'],
                ]);
            }
        });
    }
}
