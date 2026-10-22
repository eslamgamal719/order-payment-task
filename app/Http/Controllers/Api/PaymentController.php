<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiHelper;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return ApiHelper::customResponse('Forbidden.', 403);
        }

        try {
            $payment = DB::transaction(function () use ($request, $order) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($order->status !== 'confirmed') {
                    throw new RuntimeException('Only confirmed orders can be paid.');
                }

                if ($order->payments()->where('status', 'successful')->exists()) {
                    throw new RuntimeException('Order already has a successful payment.');
                }

                $gateway = PaymentGatewayFactory::make($request->validated('method'));

                $result = $gateway->charge((float) $order->total_amount, $request->validated());

                return Payment::create([
                    'order_id' => $order->id,
                    'amount' => $order->total_amount,
                    'status' => $result->successful ? 'successful' : 'failed',
                    'method' => $request->validated('method'),
                    'transaction_reference' => $result->transactionReference,
                ]);
            });
        } catch (RuntimeException $e) {
            return ApiHelper::customResponse($e->getMessage(), 422);
        } catch (ConnectionException) {
            return ApiHelper::customResponse('Payment gateway is unreachable.', 502);
        }

        return ApiHelper::customResponse(new PaymentResource($payment), 201);
    }

    public function index(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return ApiHelper::customResponse('Forbidden.', 403);
        }

        $payments = $order->payments()->latest()->paginate($request->integer('perPage', 15));

        return ApiHelper::paginatedResponse(PaymentResource::collection($payments), $payments);
    }
}
