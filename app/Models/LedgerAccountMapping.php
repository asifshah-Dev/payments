<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class LedgerAccountMapping extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'context',
        'currency',
        'debit_account_role',
        'credit_account_role',
    ];

    protected static function booted(): void
    {
        $allowedContexts = [
            'successful_payment',
            'merchant_payout',
        ];

        $allowedRoles = [
            'platform_gateway_clearing',
            'merchant_payable',
        ];

        static::creating(function ($mapping) use ($allowedContexts, $allowedRoles) {

            if (!in_array($mapping->context, $allowedContexts, true)) {
                throw new InvalidArgumentException(
                    "Invalid ledger account mapping context: {$mapping->context}"
                );
            }

            if (!in_array($mapping->debit_account_role, $allowedRoles, true)) {
                throw new InvalidArgumentException(
                    "Invalid debit account role: {$mapping->debit_account_role}"
                );
            }

            if (!in_array($mapping->credit_account_role, $allowedRoles, true)) {
                throw new InvalidArgumentException(
                    "Invalid credit account role: {$mapping->credit_account_role}"
                );
            }

            $mapping->currency = strtoupper($mapping->currency);

            if (!preg_match('/^[A-Z]{3}$/', $mapping->currency)) {
                throw new InvalidArgumentException(
                    "Invalid currency code: {$mapping->currency}"
                );
            }
        });

        static::updating(function ($mapping) use ($allowedContexts, $allowedRoles) {

            if (
                $mapping->isDirty('context') &&
                !in_array($mapping->context, $allowedContexts, true)
            ) {
                throw new InvalidArgumentException(
                    "Invalid ledger account mapping context: {$mapping->context}"
                );
            }

            if (
                $mapping->isDirty('debit_account_role') &&
                !in_array($mapping->debit_account_role, $allowedRoles, true)
            ) {
                throw new InvalidArgumentException(
                    "Invalid debit account role: {$mapping->debit_account_role}"
                );
            }

            if (
                $mapping->isDirty('credit_account_role') &&
                !in_array($mapping->credit_account_role, $allowedRoles, true)
            ) {
                throw new InvalidArgumentException(
                    "Invalid credit account role: {$mapping->credit_account_role}"
                );
            }

            if ($mapping->isDirty('currency')) {
                $mapping->currency = strtoupper($mapping->currency);

                if (!preg_match('/^[A-Z]{3}$/', $mapping->currency)) {
                    throw new InvalidArgumentException(
                        "Invalid currency code: {$mapping->currency}"
                    );
                }
            }
        });
    }
}