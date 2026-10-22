<?php

namespace App\Services\Payments;

interface PaymentMethodInterface
{
    /**
     * Gateway-specific validation rules merged into the payment request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Charge the given amount through this gateway and return the outcome.
     *
     * @param  array<string, mixed>  $options  Gateway-specific input (e.g. a tokenized payment method).
     */
    public function charge(float $amount, array $options = []): PaymentResult;
}
