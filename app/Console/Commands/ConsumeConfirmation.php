<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class ConsumeConfirmation extends BaseConsumer
{
    protected $signature = 'kafka:consume-confirmation';
    protected $description = 'Consume order.created and order.cancelled events to send customer notifications';

    protected function topic(): string
    {
        return implode(',', [
            config('kafka.topics.order_created'),
            config('kafka.topics.order_cancelled'),
        ]);
    }

    protected function groupId(): string
    {
        return config('kafka.consumer_group_id') . '-confirmation';
    }

    protected function processMessage(array $payload): void
    {
        $eventType = $payload['event_type'];
        $orderId = $payload['order_id'];
        $email = $payload['customer_email'];
        $name = $payload['customer_name'];
        $total = number_format($payload['total'], 2);

        match ($eventType) {

            'order.created' => $this->sendOrderConfirmation($orderId, $name, $email, $total, $payload['items']),

            'order.cancelled' => $this->sendCancellationNotice($orderId, $name, $email, $total),

            default => Log::warning("[confirmation] Unknown event_type [{$eventType}] — skipped"),
        };

        if ($eventType === 'order.created') {
            Order::where('id', $orderId)
                ->where('status', 'pending')
                ->update(['status' => 'confirmed']);

            Log::info("[confirmation] Order [{$orderId}] status → confirmed");
        }
    }


    private function sendOrderConfirmation(
        string $orderId,
        string $name,
        string $email,
        string $total,
        array $items
    ): void {
        $itemList = collect($items)
            ->map(fn($i) => "  • {$i['name']} x{$i['quantity']}  \${$i['line_total']}")
            ->implode("\n");

        Log::info(
            "[confirmation] ✉  Order confirmation sent",
            [
                'to' => $email,
                'order_id' => $orderId,
                'subject' => "Your order #{$orderId} is confirmed!",
                'body' => "Hi {$name},\n\nYour order is confirmed.\n\n{$itemList}\n\nTotal: \${$total}\n\nThank you!",
            ]
        );
    }

    private function sendCancellationNotice(
        string $orderId,
        string $name,
        string $email,
        string $total
    ): void {
        Log::info(
            "[confirmation] ✉  Cancellation notice sent",
            [
                'to' => $email,
                'order_id' => $orderId,
                'subject' => "Your order #{$orderId} has been cancelled",
                'body' => "Hi {$name},\n\nYour order #{$orderId} (\${$total}) has been cancelled.\n\nIf this was a mistake please place a new order.",
            ]
        );
    }
}
