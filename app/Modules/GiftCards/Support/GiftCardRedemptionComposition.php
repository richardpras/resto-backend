<?php

namespace App\Modules\GiftCards\Support;

final class GiftCardRedemptionComposition
{
    public function __construct(
        public float $giftCardAmount = 0.0,
        public float $storeCreditAmount = 0.0,
        /** @var list<int> */
        public array $settlementIds = [],
    ) {}

    public function total(): float
    {
        return round($this->giftCardAmount + $this->storeCreditAmount, 2);
    }

    public function isEmpty(): bool
    {
        return $this->total() <= 0;
    }
}
