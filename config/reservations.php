<?php

return [
    'no_show_grace_minutes' => (int) env('RESERVATION_NO_SHOW_GRACE_MINUTES', 15),
    'party_size_min' => (int) env('RESERVATION_PARTY_SIZE_MIN', 1),
    'party_size_max' => (int) env('RESERVATION_PARTY_SIZE_MAX', 50),
    'deposit_proof_max_kb' => (int) env('RESERVATION_DEPOSIT_PROOF_MAX_KB', 5120),
    'deposit_proof_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
];
