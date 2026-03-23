<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Order extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_name',
        'customer_email',
        'items',
        'total',
        'status',
        'cancellation_reason',
    ];

    protected $casts = [
        'items' => 'array',
        'total' => 'float',
    ];


    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed']);
    }

    public function toKafkaPayload(string $eventType): array
    {
        return [
            'event_type' => $eventType,
            'order_id' => $this->id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'items' => $this->items,
            'total' => $this->total,
            'status' => $this->status,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}