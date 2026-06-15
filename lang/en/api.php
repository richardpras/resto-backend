<?php

return [
    'validation' => [
        'failed' => 'The given data was invalid.',
    ],
    'auth' => [
        'unauthenticated' => 'Unauthenticated.',
        'forbidden' => 'This action is unauthorized.',
    ],
    'generic' => [
        'not_found' => 'Resource not found.',
        'server_error' => 'Something went wrong. Please try again.',
    ],
    'shift_close' => [
        'outlet_required' => 'Select an outlet before closing the shift.',
        'already_running' => 'A shift close is already in progress for this outlet.',
        'blocked' => 'Shift close is blocked until preflight issues are resolved.',
    ],
    'purchase' => [
        'invalid_status' => 'This purchase document cannot be updated in its current status.',
        'pr_not_approved' => 'Purchase request must be approved before converting to PO.',
    ],
    'accounting' => [
        'period_closed' => 'The accounting period is closed.',
        'unbalanced_journal' => 'Journal entry is not balanced.',
    ],
    'payroll' => [
        'run_locked' => 'This payroll run is locked and cannot be modified.',
    ],
];
