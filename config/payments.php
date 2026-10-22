<?php

use App\Services\Payments\Gateways\StripeGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Map of payment method key => gateway class. Each class must implement
    | PaymentMethodInterface. Register a new gateway here to enable it;
    | no other code needs to change.
    |
    */

    'gateways' => [
        'stripe' => StripeGateway::class,
    ],

];
