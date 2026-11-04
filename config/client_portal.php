<?php

declare(strict_types=1);

return [
    // soft: SMS por el Soft (RingCentral). log: el texto va al log, solo fuera de producción.
    'sms_driver' => env('PORTAL_SMS_DRIVER', 'soft'),

    // Teléfono de Customer Care para llamadas, el que muestra el correo del código.
    'support_phone' => env('PORTAL_SUPPORT_PHONE', '(855) 517-0390'),
];
