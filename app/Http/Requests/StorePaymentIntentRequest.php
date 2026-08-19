<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
{
    return [
        'amount' => ['required', 'integer', 'min:1'],
        'currency' => ['required', 'string', 'size:3'],
        'description' => ['nullable', 'string'],
    ];
}
}