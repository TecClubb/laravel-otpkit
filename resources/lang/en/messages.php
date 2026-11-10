<?php

return [

    // Collapsed messages (default). Never reveal whether a code exists.
    'invalid' => 'The code is invalid or has expired.',
    'too_many_attempts' => 'Too many attempts. Request a new code.',
    'rate_limited' => 'Too many attempts. Try again in :seconds seconds.',

    'login' => [
        'sent' => 'If an account matches, we sent a login code.',
    ],

    // One message per status, used when otpkit.messages.verbose is true.
    'verbose' => [
        'invalid' => 'The code is incorrect.',
        'expired' => 'The code has expired. Request a new code.',
        'not_found' => 'No code was requested, or it has been replaced by a newer one.',
        'already_used' => 'The code has already been used.',
        'context_mismatch' => 'The code cannot be used from here.',
        'too_many_attempts' => 'Too many attempts. Request a new code.',
        'rate_limited' => 'Too many attempts. Try again in :seconds seconds.',
    ],

];
