<?php

namespace App\Services\Payments;

use InvalidArgumentException;

class PaymentGatewayFactory
{
    public static function make(string $method): PaymentMethodInterface
    {
        $class = config("payments.gateways.{$method}");

        if (! $class || ! is_subclass_of($class, PaymentMethodInterface::class)) {
            throw new InvalidArgumentException("Unsupported payment method [{$method}].");
        }

        return app($class);
    }

    /**
     * @return array<int, string>
     */
    public static function supported(): array
    {
        return array_keys(config('payments.gateways', []));
    }
}
