<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Product;
use App\Services\KafkaProducerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function __construct(
        private readonly KafkaProducerService $kafka
    ) {
    }


    public function store(StoreOrderRequest $request): JsonResponse
    {
        $productIds = collect($request->items)->pluck('product_id');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $lineItems = [];
        $total = 0;

        foreach ($request->items as $item) {
            $product = $products->get($item['product_id']);

            if ($product->stock < $item['quantity']) {
                return response()->json([
                    'message' => "Insufficient stock for product [{$product->name}]. "
                        . "Available: {$product->stock}, requested: {$item['quantity']}.",
                ], 422);
            }

            $linePrice = $product->price * $item['quantity'];
            $total += $linePrice;

            $lineItems[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $item['quantity'],
                'unit_price' => $product->price,
                'line_total' => round($linePrice, 2),
            ];
        }

        $order = DB::transaction(function () use ($request, $lineItems, $total) {
            $order = Order::create([
                'customer_name' => $request->customer_name,
                'customer_email' => $request->customer_email,
                'items' => $lineItems,
                'total' => round($total, 2),
                'status' => 'pending',
            ]);

            $this->kafka->publishOrderCreated(
                $order->toKafkaPayload('order.created')
            );

            return $order;
        });

        Log::info("[OrderController] Order created", ['order_id' => $order->id]);

        return response()->json([
            'message' => 'Order placed successfully.',
            'order' => $order->fresh(),
        ], 201);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['order' => $order]);
    }

    public function cancel(CancelOrderRequest $request, Order $order): JsonResponse
    {
        if (!$order->isCancellable()) {
            return response()->json([
                'message' => "Order [{$order->id}] cannot be cancelled. Current status: {$order->status}.",
            ], 422);
        }

        DB::transaction(function () use ($request, $order) {
            $order->update([
                'status' => 'cancelled',
                'cancellation_reason' => $request->input('reason', 'Cancelled by customer'),
            ]);

            $this->kafka->publishOrderCancelled(
                $order->toKafkaPayload('order.cancelled')
            );
        });

        Log::info("[OrderController] Order cancelled", ['order_id' => $order->id]);

        return response()->json([
            'message' => 'Order cancelled successfully.',
            'order' => $order->fresh(),
        ]);
    }
}