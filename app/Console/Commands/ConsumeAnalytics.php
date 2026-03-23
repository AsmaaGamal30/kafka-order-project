<?php

namespace App\Console\Commands;

use App\Models\OrderEvent;
use Illuminate\Support\Facades\Log;

class ConsumeAnalytics extends BaseConsumer
{
    protected $signature = 'kafka:consume-analytics';
    protected $description = 'Consume order events and persist them to the analytics event log';

    protected function topic(): string
    {
        return implode(',', [
            config('kafka.topics.order_created'),
            config('kafka.topics.order_cancelled'),
        ]);
    }

    protected function groupId(): string
    {
        return config('kafka.consumer_group_id') . '-analytics';
    }

    protected function handle(array $payload): void
    {
        $eventType = $payload['event_type'];
        $orderId = $payload['order_id'];

        $alreadyRecorded = OrderEvent::where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->where('occurred_at', $payload['occurred_at'])
            ->exists();

        if ($alreadyRecorded) {
            Log::info("[analytics] Duplicate event ignored", [
                'order_id' => $orderId,
                'event_type' => $eventType,
            ]);
            return;
        }

        OrderEvent::create([
            'order_id' => $orderId,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => $payload['occurred_at'],
        ]);

        Log::info("[analytics] Event recorded", [
            'order_id' => $orderId,
            'event_type' => $eventType,
            'total' => $payload['total'],
        ]);

        $this->logMetrics($eventType, $payload);
    }

    private function logMetrics(string $eventType, array $payload): void
    {
        if ($eventType === 'order.created') {
            $itemCount = collect($payload['items'])->sum('quantity');

            Log::info('[analytics] Metrics snapshot', [
                'event' => 'order_placed',
                'order_id' => $payload['order_id'],
                'revenue' => $payload['total'],
                'item_count' => $itemCount,
                'customer' => $payload['customer_email'],
            ]);
        }

        if ($eventType === 'order.cancelled') {
            Log::info('[analytics] Metrics snapshot', [
                'event' => 'order_cancelled',
                'order_id' => $payload['order_id'],
                'lost_revenue' => $payload['total'],
            ]);
        }
    }
}