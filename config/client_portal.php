<?php

declare(strict_types=1);

return [
    // soft: SMS por el Soft (RingCentral). log: el texto va al log, solo fuera de producción.
    'sms_driver' => env('PORTAL_SMS_DRIVER', 'soft'),
];
