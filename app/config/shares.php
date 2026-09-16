<?php

return [
    // Existing NULL expirations remain permanent. This value applies only to new links.
    'default_expiry_days' => (int) env('SHARE_DEFAULT_EXPIRY_DAYS', 30),

    // Keep filesystem verification and destructive operations bounded per request.
    'max_bulk_items' => (int) env('SHARE_MAX_BULK_ITEMS', 200),
    'availability_verification_limit' => (int) env('SHARE_AVAILABILITY_VERIFICATION_LIMIT', 100),

    // Threshold that requires the operator to type "ELIMINAR" before bulk delete proceeds.
    'hard_confirmation_threshold' => (int) env('SHARE_HARD_CONFIRMATION_THRESHOLD', 25),
];
