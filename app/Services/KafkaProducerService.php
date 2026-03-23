<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;

class KafkaProducerService
{
    /**
     * Publish a message to any Kafka topic.
     *
     * @param  string  $topic    Topic name
     * @param  array   $payload  Data to serialise as JSON
     * @param  string|null $key  Optional partition key (e.g. order_id for ordering)
     */
    public function publish(string $topic, array $payload, ?string $key = null): void
    {
        try {
            $message = new Message(
                topicName: $topic,
                body: $payload,
                key: $key,
                headers: [
                    'source' => 'laravel-orders-api',
                    'published_at' => now()->toIso8601String(),
                    'event_type' => $payload['event_type'] ?? 'unknown',
                ]
            );

            Kafka::publish(config('kafka.brokers'))
                ->onTopic($topic)
                ->withMessage($message)
                ->send();

            Log::info("[Kafka] Published to [{$topic}]", [
                'key' => $key,
                'event_type' => $payload['event_type'] ?? 'unknown',
            ]);

        } catch (\Throwable $e) {
            Log::error("[Kafka] Failed to publish to [{$topic}]", [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            throw $e;
        }
    }

    public function publishOrderCreated(array $payload): void
    {
        $this->publish(
            topic: config('kafka.topics.order_created'),
            payload: $payload,
            key: $payload['order_id'] ?? null
        );
    }

    public function publishOrderCancelled(array $payload): void
    {
        $this->publish(
            topic: config('kafka.topics.order_cancelled'),
            payload: $payload,
            key: $payload['order_id'] ?? null
        );
    }

    public function publishToDlq(array $originalPayload, string $errorMessage): void
    {
        $dlqPayload = [
            'event_type' => 'dlq.message',
            'original_payload' => $originalPayload,
            'error' => $errorMessage,
            'failed_at' => now()->toIso8601String(),
        ];

        $this->publish(
            topic: config('kafka.topics.dead_letter'),
            payload: $dlqPayload
        );
    }
}