<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\AuthController;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Unit tests: call the controller methods directly (no HTTP, no routes, no middleware).
 */
class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // Build a real form request filled with data, the same way Laravel does before calling the controller.
    private function makeRequest(string $requestClass, array $data)
    {
        $request = $requestClass::create('/', 'POST', $data);
        $request->setContainer($this->app)->validateResolved();

        return $request;
    }

    public function test_register_creates_user_and_returns_token(): void
    {
        $request = $this->makeRequest(RegisterRequest::class, [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response = (new AuthController)->register($request);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($data['result']['access_token']);
        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    public function test_register_hashes_the_password(): void
    {
        $request = $this->makeRequest(RegisterRequest::class, [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        (new AuthController)->register($request);

        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
        ]);

        $request = $this->makeRequest(LoginRequest::class, [
            'email' => 'jane@example.com',
            'password' => 'password123',
        ]);

        $response = (new AuthController)->login($request);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($data['result']['access_token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
        ]);

        $request = $this->makeRequest(LoginRequest::class, [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ]);

        $response = (new AuthController)->login($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_logout_logs_the_user_out(): void
    {
        $user = User::factory()->create();
        Auth::guard('api')->login($user);

        $response = (new AuthController)->logout();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(Auth::guard('api')->user());
    }
}
