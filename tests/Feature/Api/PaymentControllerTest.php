<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_guest_cannot_list_payments(): void
    {
        $order = Order::factory()->create();

        $response = $this->getJson("/api/orders/{$order->id}/payments");

        $response->assertStatus(401);
    }

    public function test_guest_cannot_create_payment(): void
    {
        $order = Order::factory()->create();

        $response = $this->postJson("/api/orders/{$order->id}/payments", [
            'method' => 'stripe',
            'payment_method' => 'pm_card_visa',
        ]);

        $response->assertStatus(401);
    }

    public function test_owner_can_list_their_order_payments(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();
        Payment::factory()->for($order)->count(2)->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/orders/{$order->id}/payments");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('result'));
    }

    public function test_order_payments_listing_is_paginated(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();
        Payment::factory()->for($order)->count(3)->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/orders/{$order->id}/payments?perPage=2");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('result'));
        $this->assertSame([
            'currentPage' => 1,
            'perPage' => 2,
            'total' => 3,
            'lastPage' => 2,
        ], $response->json('pagination'));
    }

    public function test_user_cannot_list_other_users_order_payments(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->for($owner)->confirmed()->create();

        $response = $this->withHeaders($this->authHeader($intruder))
            ->getJson("/api/orders/{$order->id}/payments");

        $response->assertStatus(403);
    }

    public function test_user_can_pay_confirmed_order_successfully(): void
    {
        config(['services.stripe.secret' => 'sk_test_123']);
        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'pi_123', 'status' => 'succeeded']),
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create(['total_amount' => 50]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'payment_method' => 'pm_card_visa',
            ]);

        $response->assertStatus(201);
        $this->assertSame('successful', $response->json('result.status'));
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'successful',
            'transaction_reference' => 'pi_123',
        ]);
    }

    public function test_payment_is_recorded_as_failed_when_gateway_declines(): void
    {
        config(['services.stripe.secret' => 'sk_test_123']);
        Http::fake([
            'api.stripe.com/*' => Http::response(['error' => ['message' => 'Card declined.']], 402),
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create(['total_amount' => 50]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'payment_method' => 'pm_card_visa',
            ]);

        $response->assertStatus(201);
        $this->assertSame('failed', $response->json('result.status'));
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'failed',
        ]);
    }

    public function test_cannot_pay_a_pending_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->pending()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'payment_method' => 'pm_card_visa',
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_pay_an_order_that_already_has_a_successful_payment(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();
        Payment::factory()->for($order)->successful()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'payment_method' => 'pm_card_visa',
            ]);

        $response->assertStatus(422);
    }

    public function test_user_cannot_pay_for_other_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->for($owner)->confirmed()->create();

        $response = $this->withHeaders($this->authHeader($intruder))
            ->postJson("/api/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'payment_method' => 'pm_card_visa',
            ]);

        $response->assertStatus(403);
    }

    public function test_store_fails_with_invalid_data(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/orders/{$order->id}/payments", []);

        $response->assertStatus(422);
    }

    public function test_store_fails_for_unsupported_method(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/orders/{$order->id}/payments", [
                'method' => 'paypal',
            ]);

        $response->assertStatus(422);
    }
}
