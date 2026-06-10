<?php

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

final class PaymentConfigurationException extends RuntimeException
{
    public function __construct(
        string $message = 'Payment provider configured but credentials are missing.',
        public readonly ?string $provider = null,
        /** @var list<string> */
        public readonly array $missing = [],
    ) {
        parent::__construct($message);
    }
}
