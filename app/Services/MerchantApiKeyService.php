<?php

namespace App\Services;

use App\Models\Merchant;

class MerchantApiKeyService
{
    public function generate(Merchant $merchant): string
    {
        $rawKey = 'pk_test_' . bin2hex(random_bytes(32));

        $merchant->update([
            'api_key_hash' => hash('sha256', $rawKey),
        ]);

        return $rawKey;
    }
}