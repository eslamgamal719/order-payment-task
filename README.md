# Order & Payment Task

A Laravel 12 API for managing orders and payments, with JWT authentication and an extensible payment gateway system (currently shipping with Stripe).

## Requirements

- PHP 8.2+
- Composer
- SQLite (default) or another database supported by Laravel

## Setup Instructions

1. **Clone the repository**

    ```bash
    git clone <repo-url>
    cd ecommerce
    ```

2. **Install PHP dependencies**

    ```bash
    composer install
    ```

3. **Configure environment**

    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

    Then edit `.env` and set the following:
    - `DB_CONNECTION` and related `DB_*` values (defaults to `sqlite`; create the file with `touch database/database.sqlite` if it doesn't exist).
    - `JWT_SECRET` — generate one with:

        ```bash
        php artisan jwt:secret
        ```

    - `STRIPE_KEY` and `STRIPE_SECRET` — required for the Stripe payment gateway.

4. **Run database migrations**

    ```bash
    php artisan migrate
    ```

5. **Serve the application**

    ```bash
    php artisan serve
    ```

6. **Run the test suite**

    ```bash
    composer test
    ```

## Payment Gateway Extensibility

Payments are built around a small abstraction so new gateways can be added without touching the controller, request validation, or any existing gateway code.

**Key pieces** (`app/Services/Payments/`):

- `PaymentMethodInterface` — the contract every gateway implements:
    - `rules(): array` — gateway-specific validation rules, merged into `StorePaymentRequest` at request time.
    - `charge(float $amount, array $options = []): PaymentResult` — performs the charge and returns the outcome.
- `PaymentResult` — an immutable DTO (`successful`, `transactionReference`, `message`) returned by every gateway, regardless of provider.
- `PaymentGatewayFactory` — resolves a gateway instance from the `method` key in the request (`PaymentGatewayFactory::make('stripe')`) via the Laravel container, and exposes `PaymentGatewayFactory::supported()` (used for validating the `method` field).
- `config/payments.php` — maps a payment method key (e.g. `stripe`) to its gateway class. This is the single place a new gateway gets registered.
- `Gateways/StripeGateway.php` — the reference implementation, charging via Stripe's Payment Intents API.

### Adding a new payment gateway

1. **Create the gateway class** under `app/Services/Payments/Gateways/`, implementing `PaymentMethodInterface`:

    ```php
    namespace App\Services\Payments\Gateways;

    use App\Services\Payments\PaymentMethodInterface;
    use App\Services\Payments\PaymentResult;

    class NewGateway implements PaymentMethodInterface
    {
        public function rules(): array
        {
            return [
                // gateway-specific fields required in the payment request
            ];
        }

        public function charge(float $amount, array $options = []): PaymentResult
        {
            // call the provider's API and map its response to a PaymentResult
            return new PaymentResult(
                successful: true,
                transactionReference: '...',
                message: '...',
            );
        }
    }
    ```

2. **Add any required credentials/config** to `config/services.php` and `.env.example`, then inject them into the constructor (see `StripeGateway`, which injects `services.stripe.secret` via `#[Config(...)]`).

3. **Register the gateway** in `config/payments.php`:

    ```php
    'gateways' => [
        'stripe' => StripeGateway::class,
        'new_gateway' => NewGateway::class,
    ],
    ```

4. **Done.** The `method` field now accepts `new_gateway`, `StorePaymentRequest` automatically validates against its `rules()`, and `PaymentController` can charge through it — no other code changes needed.

5. **Add tests** covering `rules()` and `charge()` (success, failure, and unreachable-gateway cases), following the existing Stripe gateway tests.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
