<?php

return [
    'email_enabled' => env('COMPRE_EMAIL_ENABLED', false),
    'sms_enabled' => env('COMPRE_SMS_ENABLED', false),
    'twilio_sid' => env('COMPRE_TWILIO_SID'),
    'twilio_token' => env('COMPRE_TWILIO_TOKEN'),
    'twilio_from' => env('COMPRE_TWILIO_FROM'),
];
