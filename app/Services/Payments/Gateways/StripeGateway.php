<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\PaymentMethodInterface;
use App\Services\Payments\PaymentResult;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class StripeGateway implements PaymentMethodInterface
{
    private const API_URL = 'https://api.stripe.com/v1/payment_intents';

    public function __construct(
        #[Config('services.stripe.secret')]
        private readonly ?string $secretKey,
    ) {
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string'],
        ];
    }

    public function charge(float $amount, array $options = []): PaymentResult
    {
        if (empty($this->secretKey)) {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        $paymentMethod = $options['payment_method'] ?? null;

        if (empty($paymentMethod)) {
            throw new RuntimeException('A Stripe payment_method token is required.');
        }

        $response = Http::asForm()
            ->withBasicAuth($this->secretKey, '')
            ->post(self::API_URL, [
                'amount' => (int) round($amount * 100),
                'currency' => $options['currency'] ?? 'usd',
                'payment_method' => $paymentMethod,
                'confirmation_method' => 'automatic',
                'confirm' => 'true',
                'off_session' => 'true',
            ]);

        if ($response->failed()) {
            return new PaymentResult(
                successful: false,
                transactionReference: $response->json('error.payment_intent.id') ?? ('stripe_error_'.Str::uuid()),
                message: $response->json('error.message') ?? 'Stripe charge failed.',
            );
        }

        $status = $response->json('status');

        return new PaymentResult(
            successful: $status === 'succeeded',
            transactionReference: $response->json('id'),
            message: $status,
        );
    }
}
