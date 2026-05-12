<?php

namespace App\Modules\Payments\Contracts;

/**
 * Maps a provider-native webhook JSON body into the normalized shape consumed by
 * {@see \App\Modules\Payments\Services\PaymentGatewayService::handleWebhook()}.
 */
interface PaymentWebhookHandlerContract
{
    /**
     * @param  array<string, mixed>  $decodedJson
     * @return array<string, mixed>
     */
    public function normalize(array $decodedJson): array;
}
