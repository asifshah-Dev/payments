<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StorePaymentIntentRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'integer',
                'min:1',
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                'in:USD,EUR,GBP,PKR,AED,SAR,JPY,CAD,AUD,CHF,CNY,INR',
            ],
            'description' => [
                'nullable',
                'string',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $idempotencyKey = trim(
                (string) ($this->header('Idempotency-Key') ?? $this->header('idempotency-key'))
            );

            if ($idempotencyKey === '') {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'The Idempotency-Key header is required.'
                );

                return;
            }

            if (!Str::isUuid($idempotencyKey)) {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'The Idempotency-Key must be a valid UUID.'
                );
            }
        });
    }
}