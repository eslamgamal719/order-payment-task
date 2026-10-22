<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit tests: call the controller methods directly (no HTTP, no routes, no middleware).
 */
class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    // Build a real request filled with data and an authenticated user, the same way Laravel does before calling the controller.
    private function makeRequest(string $requestClass, array $data, User $user, string $method = 'POST'): Request
    {
        $request = $requestClass::create('/', $method, $data);
        $request->setUserResolver(fn () => $user);

        if ($request instanceof FormRequest) {
            $request->setContainer($this->app)->validateResolved();
        }

        return $request;
    }

    public function test_index_returns_payments_for_own_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();
        Payment::factory()->for($order)->count(2)->create();

        $request = $this->makeRequest(Request::class, [], $user, 'GET');

        $response = (new PaymentController)->index($request, $order);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $data['result']);
    }

    public function test_index_is_forbidden_for_other_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->for($owner)->confirmed()->create();

        $request = $this->makeRequest(Request::class, [], $intruder, 'GET');

        $response = (new PaymentController)->index($request, $order);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_store_creates_successful_payment(): void
    {
        config(['services.stripe.secret' => 'sk_test_123']);
        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'pi_123', 'status' => 'succeeded']),
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create(['total_amount' => 50]);

        $request = $this->makeRequest(StorePaymentRequest::class, [
            'method' => 'stripe',
            'payment_method' => 'pm_card_visa',
        ], $user);

        $response = (new PaymentController)->store($request, $order);
        $data = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('successful', $data['result']['status']);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'successful',
            'transaction_reference' => 'pi_123',
        ]);
    }

    public function test_store_creates_failed_payment_when_gateway_declines(): void
    {
        config(['services.stripe.secret' => 'sk_test_123']);
        Http::fake([
            'api.stripe.com/*' => Http::response(['error' => ['message' => 'Card declined.']], 402),
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create(['total_amount' => 50]);

        $request = $this->makeRequest(StorePaymentRequest::class, [
            'method' => 'stripe',
            'payment_method' => 'pm_card_visa',
        ], $user);

        $response = (new PaymentController)->store($request, $order);
        $data = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('failed', $data['result']['status']);
    }

    public function test_store_fails_for_pending_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->pending()->create();

        $request = $this->makeRequest(StorePaymentRequest::class, [
            'method' => 'stripe',
            'payment_method' => 'pm_card_visa',
        ], $user);

        $response = (new PaymentController)->store($request, $order);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_store_fails_when_order_already_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();
        Payment::factory()->for($order)->successful()->create();

        $request = $this->makeRequest(StorePaymentRequest::class, [
            'method' => 'stripe',
            'payment_method' => 'pm_card_visa',
        ], $user);

        $response = (new PaymentController)->store($request, $order);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_store_is_forbidden_for_other_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->for($owner)->confirmed()->create();

        $request = $this->makeRequest(StorePaymentRequest::class, [
            'method' => 'stripe',
            'payment_method' => 'pm_card_visa',
        ], $intruder);

        $response = (new PaymentController)->store($request, $order);

        $this->assertSame(403, $response->getStatusCode());
    }
}
