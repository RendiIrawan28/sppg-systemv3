<?php

return [
    /* Masa berlaku token aplikasi Android. */
    'token_expiration_days' => max(1, (int) env('MOBILE_TOKEN_EXPIRATION_DAYS', 30)),

    'firebase' => [
        'enabled' => filter_var(env('FIREBASE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    'notifications' => [
        'reminder_lead_minutes' => max(0, (int) env('MOBILE_NOTIFICATION_LEAD_MINUTES', 15)),
    ],
];
