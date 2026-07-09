<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Services\Costing\FifoCostingStrategy;
use App\Modules\Inventory\Services\Costing\InventoryCostingStrategy;
use App\Modules\Inventory\Services\Costing\MovingAverageCostingStrategy;
use App\Modules\Inventory\Support\InventoryCostingMethod;

final class InventoryCostingStrategyResolver
{
    public function __construct(
        private readonly InventoryCostingPolicyService $policyService,
        private readonly MovingAverageCostingStrategy $movingAverageStrategy,
        private readonly FifoCostingStrategy $fifoStrategy,
    ) {}

    public function resolve(): InventoryCostingStrategy
    {
        return $this->policyService->getMethod() === InventoryCostingMethod::FIFO
            ? $this->fifoStrategy
            : $this->movingAverageStrategy;
    }

    public function resolveMethod(): string
    {
        return $this->policyService->getMethod();
    }
}
