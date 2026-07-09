<?php

return [
    'stock_enforcement_mode' => env('INVENTORY_STOCK_ENFORCEMENT_MODE', 'deferred'),
    'allow_negative_stock' => env('INVENTORY_ALLOW_NEGATIVE_STOCK', true),
    'costing_method' => env('INVENTORY_COSTING_METHOD', 'moving_average'),
    'deferred_consumption_trigger' => env('INVENTORY_DEFERRED_CONSUMPTION_TRIGGER', 'shift_close'),
];
