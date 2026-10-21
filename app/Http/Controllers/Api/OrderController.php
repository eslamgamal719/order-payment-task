<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiHelper;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with('items')
            ->latest()
            ->get();

        return ApiHelper::customResponse(OrderResource::collection($orders));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $order = DB::transaction(function () use ($request) {
                $order = Order::create([
                    'user_id' => $request->user()->id,
                    'status' => 'pending',
                    'total_amount' => 0,
                ]);

                $total = 0;

                foreach ($request->validated('items') as $line) {
                    $product = Product::where('name', $line['product_name'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    $quantity = $line['quantity'];

                    if ($product->stock < $quantity) {
                        throw new RuntimeException("Insufficient stock for \"{$product->name}\".");
                    }

                    $product->decrement('stock', $quantity);

                    $lineTotal = round($product->price * $quantity, 2);
                    $total += $lineTotal;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $product->price,
                        'line_total' => $lineTotal,
                    ]);
                }

                $order->update(['total_amount' => $total]);

                return $order;
            });
        } catch (RuntimeException $e) {
            return ApiHelper::customResponse($e->getMessage(), 422);
        }

        return ApiHelper::customResponse(new OrderResource($order->load('items', 'user')), 201);
    }
}
