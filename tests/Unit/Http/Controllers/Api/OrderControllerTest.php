<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\OrderController;
use App\Http\Requests\OrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;


class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(string $requestClass, array $data, User $user, string $method = 'POST'): Request
    {
        $request = $requestClass::create('/', $method, $data);
        $request->setUserResolver(fn () => $user);

        if ($request instanceof FormRequest) {
            $request->setContainer($this->app)->validateResolved();
        }

        return $request;
    }

    public function test_index_returns_only_authenticated_users_orders(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Order::factory()->for($user)->create();
        Order::factory()->for($otherUser)->create();

        $request = $this->makeRequest(Request::class, [], $user, 'GET');

        $response = (new OrderController)->index($request);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $data['result']);
    }

    public function test_index_filters_orders_by_status(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->pending()->create();
        Order::factory()->for($user)->confirmed()->create();

        $request = $this->makeRequest(Request::class, ['status' => 'confirmed'], $user, 'GET');

        $response = (new OrderController)->index($request);
        $data = $response->getData(true);

        $this->assertCount(1, $data['result']);
        $this->assertSame('confirmed', $data['result'][0]['status']);
    }

    public function test_store_creates_order_and_decrements_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);

        $request = $this->makeRequest(OrderRequest::class, [
            'items' => [
                ['product_name' => $product->name, 'quantity' => 2],
            ],
        ], $user);

        $response = (new OrderController)->store($request);
        $data = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('20.00', $data['result']['totalAmount']);
        $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'status' => 'pending']);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_store_fails_with_insufficient_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 1]);

        $request = $this->makeRequest(OrderRequest::class, [
            'items' => [
                ['product_name' => $product->name, 'quantity' => 5],
            ],
        ], $user);

        $response = (new OrderController)->store($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, $product->refresh()->stock);
    }

    public function test_update_replaces_items_and_adjusts_stock(): void
    {
        $user = User::factory()->create();
        $oldProduct = Product::factory()->create(['stock' => 3]);
        $newProduct = Product::factory()->create(['price' => 20, 'stock' => 5]);

        $order = Order::factory()->for($user)->pending()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $oldProduct->id,
            'quantity' => 2,
        ]);

        $request = $this->makeRequest(OrderRequest::class, [
            'items' => [
                ['product_name' => $newProduct->name, 'quantity' => 1],
            ],
        ], $user, 'PATCH');

        $response = (new OrderController)->update($request, $order);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(5, $oldProduct->refresh()->stock);
        $this->assertSame(4, $newProduct->refresh()->stock);
    }

    public function test_update_fails_for_non_pending_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();

        $request = $this->makeRequest(OrderRequest::class, [
            'items' => [
                ['product_name' => $product->name, 'quantity' => 1],
            ],
        ], $user, 'PATCH');

        $response = (new OrderController)->update($request, $order);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_update_fails_for_other_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $product = Product::factory()->create();
        $order = Order::factory()->for($owner)->pending()->create();

        $request = $this->makeRequest(OrderRequest::class, [
            'items' => [
                ['product_name' => $product->name, 'quantity' => 1],
            ],
        ], $intruder, 'PATCH');

        $response = (new OrderController)->update($request, $order);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_destroy_deletes_unpaid_order_and_restocks_products(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);
        $order = Order::factory()->for($user)->pending()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $request = $this->makeRequest(Request::class, [], $user, 'DELETE');

        $response = (new OrderController)->destroy($request, $order);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertSame(7, $product->refresh()->stock);
    }

    public function test_destroy_fails_for_paid_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();
        Payment::factory()->for($order)->successful()->create();

        $request = $this->makeRequest(Request::class, [], $user, 'DELETE');

        $response = (new OrderController)->destroy($request, $order);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_destroy_fails_for_other_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->for($owner)->pending()->create();

        $request = $this->makeRequest(Request::class, [], $intruder, 'DELETE');

        $response = (new OrderController)->destroy($request, $order);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }
}
