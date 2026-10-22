<?php

namespace App\Services\Payments;

final class PaymentResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly string $transactionReference,
        public readonly ?string $message = null,
    ) {
    }
}
