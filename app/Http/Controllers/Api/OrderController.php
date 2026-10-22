<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiHelper;
use App\Http\Requests\OrderRequest;
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
        $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,confirmed,cancelled'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $request->user()
            ->orders()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->with('items')
            ->latest()
            ->paginate($request->integer('perPage', 15));

        return ApiHelper::paginatedResponse(OrderResource::collection($orders), $orders);
    }

    public function store(OrderRequest $request): JsonResponse
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

    public function update(OrderRequest $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return ApiHelper::customResponse('Order not found.', 404);
        }

        if ($order->status !== 'pending') {
            return ApiHelper::customResponse('Only pending, unpaid orders can be updated.', 422);
        }

        try {
            $order = DB::transaction(function () use ($request, $order) {
                foreach ($order->items as $item) {
                    Product::where('id', $item->product_id)->increment('stock', $item->quantity);
                }

                $order->items()->delete();

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

        return ApiHelper::customResponse(new OrderResource($order->load('items', 'user')));
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return ApiHelper::customResponse('Order not found.', 404);
        }

        if ($order->payments()->where('status', 'successful')->exists()) {
            return ApiHelper::customResponse('Order has already been paid and cannot be deleted.', 422);
        }

        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                Product::where('id', $item->product_id)->increment('stock', $item->quantity);
            }

            $order->delete();
        });

        return ApiHelper::customResponse('Order deleted.');
    }
}
