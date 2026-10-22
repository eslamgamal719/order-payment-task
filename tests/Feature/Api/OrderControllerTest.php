<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_guest_cannot_list_orders(): void
    {
        $response = $this->getJson('/api/orders');

        $response->assertStatus(401);
    }

    public function test_user_can_list_their_orders(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Order::factory()->for($user)->create();
        Order::factory()->for($otherUser)->create();

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/orders');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('result'));
    }

    public function test_user_can_filter_orders_by_status(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->pending()->create();
        Order::factory()->for($user)->confirmed()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/orders?status=confirmed');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('result'));
        $this->assertSame('confirmed', $response->json('result.0.status'));
    }

    public function test_orders_listing_is_paginated(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->count(3)->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/orders?perPage=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('result'));
        $this->assertSame([
            'currentPage' => 1,
            'perPage' => 2,
            'total' => 3,
            'lastPage' => 2,
        ], $response->json('pagination'));
    }

    public function test_orders_listing_rejects_invalid_per_page(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/orders?perPage=0');

        $response->assertStatus(422);
    }

    public function test_user_can_create_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/orders', [
            'items' => [
                ['product_name' => $product->name, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'status' => 'pending']);
        $this->assertSame(3, $product->refresh()->stock);
    }

    public function test_store_fails_with_insufficient_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 1]);

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/orders', [
            'items' => [
                ['product_name' => $product->name, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, $product->refresh()->stock);
    }

    public function test_store_fails_with_invalid_data(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/orders', []);

        $response->assertStatus(422);
    }

    public function test_user_can_update_pending_order(): void
    {
        $user = User::factory()->create();
        $oldProduct = Product::factory()->create(['stock' => 5]);
        $newProduct = Product::factory()->create(['price' => 20, 'stock' => 5]);

        $order = Order::factory()->for($user)->pending()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $oldProduct->id,
            'quantity' => 2,
        ]);
        $oldProduct->decrement('stock', 2);

        $response = $this->withHeaders($this->authHeader($user))->patchJson("/api/orders/{$order->id}", [
            'items' => [
                ['product_name' => $newProduct->name, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame(5, $oldProduct->refresh()->stock);
        $this->assertSame(4, $newProduct->refresh()->stock);
    }

    public function test_update_fails_for_confirmed_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();

        $response = $this->withHeaders($this->authHeader($user))->patchJson("/api/orders/{$order->id}", [
            'items' => [
                ['product_name' => $product->name, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_fails_for_other_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $product = Product::factory()->create();
        $order = Order::factory()->for($owner)->pending()->create();

        $response = $this->withHeaders($this->authHeader($intruder))->patchJson("/api/orders/{$order->id}", [
            'items' => [
                ['product_name' => $product->name, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(404);
    }

    public function test_user_can_delete_unpaid_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);
        $order = Order::factory()->for($user)->pending()->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response = $this->withHeaders($this->authHeader($user))->deleteJson("/api/orders/{$order->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertSame(7, $product->refresh()->stock);
    }

    public function test_cannot_delete_paid_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();
        Payment::factory()->for($order)->successful()->create();

        $response = $this->withHeaders($this->authHeader($user))->deleteJson("/api/orders/{$order->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_cannot_delete_other_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->for($owner)->pending()->create();

        $response = $this->withHeaders($this->authHeader($intruder))->deleteJson("/api/orders/{$order->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }
}
