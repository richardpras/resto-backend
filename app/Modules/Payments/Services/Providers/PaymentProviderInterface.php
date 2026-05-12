<?php

namespace App\Modules\Payments\Services\Providers;

use App\Modules\Payments\Contracts\PaymentGatewayProviderContract;

/**
 * Legacy alias for {@see PaymentGatewayProviderContract}; resolved via {@see \App\Modules\Payments\Registry\PaymentGatewayRegistry}.
 */
interface PaymentProviderInterface extends PaymentGatewayProviderContract
{
}
