<?php

namespace App\Console\Commands;

use App\Services\KafkaProducerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Facades\Kafka;


abstract class BaseConsumer extends Command
{

    abstract protected function topic(): string;

    protected function groupId(): string
    {
        return config('kafka.consumer_group_id');
    }


    protected function requiredFields(): array
    {
        return ['event_type', 'order_id', 'customer_email', 'items', 'total'];
    }


    abstract protected function handle(array $payload): void;


    public function handle_command(): int
    {
        $topic = $this->topic();
        $maxRetries = (int) config('kafka.max_retries', 3);
        $producer = app(KafkaProducerService::class);

        $this->info("[{$topic}] Consumer started. Waiting for messages…");

        Kafka::createConsumer([config('kafka.brokers')])
            ->subscribe($topic)
            ->withConsumerGroupId($this->groupId())
            ->withAutoCommit(config('kafka.auto_commit', false))
            ->withHandler(function (ConsumerMessage $message) use ($topic, $maxRetries, $producer) {
                $raw = $message->getBody();
                $attempt = 0;

                $payload = $this->decodeAndValidate($raw, $topic, $producer);
                if ($payload === null) {
                    return;
                }

                while ($attempt <= $maxRetries) {
                    try {
                        $this->handle($payload);

                        Log::info("[{$topic}] Message processed OK", [
                            'order_id' => $payload['order_id'],
                            'event_type' => $payload['event_type'],
                            'attempt' => $attempt + 1,
                        ]);

                        return;

                    } catch (\Throwable $e) {
                        $attempt++;

                        Log::warning("[{$topic}] Processing failed (attempt {$attempt}/{$maxRetries})", [
                            'order_id' => $payload['order_id'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);

                        if ($attempt > $maxRetries) {
                            Log::error("[{$topic}] Max retries reached. Routing to DLQ.", [
                                'order_id' => $payload['order_id'] ?? 'unknown',
                                'error' => $e->getMessage(),
                            ]);

                            $producer->publishToDlq($payload, $e->getMessage());
                            return;
                        }

                        sleep((int) config('kafka.sleep_on_error', 5));
                    }
                }
            })
            ->build()
            ->consume();

        return self::SUCCESS;
    }


    private function decodeAndValidate(
        mixed $raw,
        string $topic,
        KafkaProducerService $producer
    ): ?array {
        if (is_string($raw)) {
            $payload = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Invalid JSON: ' . json_last_error_msg();
                Log::error("[{$topic}] {$error}", ['raw' => substr($raw, 0, 200)]);
                $producer->publishToDlq(['raw_body' => $raw], $error);
                return null;
            }
        } else {
            $payload = (array) $raw;
        }

        $missing = array_diff($this->requiredFields(), array_keys($payload));
        if (!empty($missing)) {
            $error = 'Missing required fields: ' . implode(', ', $missing);
            Log::error("[{$topic}] {$error}", ['payload' => $payload]);
            $producer->publishToDlq($payload, $error);
            return null;
        }

        return $payload;
    }
}
