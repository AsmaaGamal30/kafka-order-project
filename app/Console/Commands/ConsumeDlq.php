<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Facades\Kafka;

class ConsumeDlq extends Command
{
    protected $signature = 'kafka:consume-dlq';
    protected $description = 'Monitor the Dead Letter Queue for failed messages';

    public function handle(): int
    {
        $topic = config('kafka.topics.dead_letter');

        $this->warn("[DLQ Monitor] Watching topic [{$topic}] for dead messages…");

        Kafka::consumer()
            ->subscribe([$topic])
            ->withConsumerGroupId(config('kafka.consumer_group_id') . '-dlq-monitor')
            ->withAutoCommit(true)
            ->withHandler(function (ConsumerMessage $message) use ($topic) {

                $body = $message->getBody();

                if (is_string($body)) {
                    $body = json_decode($body, true) ?? ['raw' => $body];
                }

                $original = $body['original_payload'] ?? [];
                $error = $body['error'] ?? 'Unknown error';
                $failedAt = $body['failed_at'] ?? 'Unknown time';

                Log::error('[DLQ] Dead message received', [
                    'failed_at' => $failedAt,
                    'error' => $error,
                    'order_id' => $original['order_id'] ?? 'N/A',
                    'event_type' => $original['event_type'] ?? 'N/A',
                    'customer' => $original['customer_email'] ?? 'N/A',
                    'payload' => $original,
                ]);

                $this->error("╔══ DEAD MESSAGE ══════════════════════════════╗");
                $this->error("║ Time      : {$failedAt}");
                $this->error("║ Error     : {$error}");
                $this->error("║ Order ID  : " . ($original['order_id'] ?? 'N/A'));
                $this->error("║ Event     : " . ($original['event_type'] ?? 'N/A'));
                $this->error("║ Customer  : " . ($original['customer_email'] ?? 'N/A'));
                $this->error("╚══════════════════════════════════════════════╝");

            })
            ->build()
            ->consume();

        return self::SUCCESS;
    }
}
