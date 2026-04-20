<?php

// Fixture: deliberately references env keys, some declared and some not,
// so the UndeclaredEnvCallsRule has a stable target to validate against.
return [
    'app_name' => env('APP_NAME'),                      // declared in .env.example — OK
    'mail_host' => env('MAIL_HOST'),                    // declared — OK
    'totally_made_up' => env('TOTALLY_MADE_UP_KEY'),    // UNDECLARED — must fire
    'stripe_secret' => env('STRIPE_SECRET', 'sk_test'), // UNDECLARED — must fire
    'dynamic' => env($foo ?? 'X'),                      // dynamic first arg — must NOT fire
];
