<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'method' => ['required', 'string', Rule::in(PaymentGatewayFactory::supported())],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];

        $method = $this->input('method');

        if (is_string($method) && in_array($method, PaymentGatewayFactory::supported(), true)) {
            $rules = array_merge($rules, PaymentGatewayFactory::make($method)->rules());
        }

        return $rules;
    }
}
