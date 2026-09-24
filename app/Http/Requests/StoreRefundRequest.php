<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication is enforced by MerchantAuthentication middleware.
        // By the time we reach here, $request->user() is the merchant.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The Idempotency-Key lives in a header, but validation and error
        // reporting work more naturally on the input bag. Promote it so the
        // 422 error surfaces under 'idempotency_key' like any other field.
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            // ---- From header ----
            'idempotency_key' => ['required', 'uuid'],

            // ---- From body ----
            'payment_intent_id' => ['required', 'uuid'],
            'amount'            => ['required', 'integer', 'min:1'],
            'reason'            => ['nullable', 'string', 'max:255'],

            // ---- Fields the client must never control ----
            // `prohibited` means: if this key is present in the request at
            // all (even if it matches what we'd have computed), reject with
            // 422. It makes the invalid input *impossible*, not merely
            // ignored, which is stronger than silently dropping it.
            'merchant_id' => ['prohibited'],
            'currency'    => ['prohibited'],
            'status'      => ['prohibited'],
        ];
    }
}