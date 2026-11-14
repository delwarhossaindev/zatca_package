<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default ZATCA environment
    |--------------------------------------------------------------------------
    |
    | Used for new devices. Every device stores its own environment.
    |
    | sandbox    - Developer portal (no Fatoora account needed, OTP 123345)
    | simulation - Fatoora simulation portal (real OTP from fatoora.zatca.gov.sa)
    | production - Live core environment
    |
    */

    'env' => env('ZATCA_ENV', 'sandbox'),

    'sandbox_otp' => env('ZATCA_SANDBOX_OTP', '123345'),

    'environments' => [
        'sandbox' => [
            'label' => 'Sandbox (Developer Portal)',
            'url' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
            'certificate_template' => 'TSTZATCA-Code-Signing',
        ],
        'simulation' => [
            'label' => 'Simulation',
            'url' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
            'certificate_template' => 'PREZATCA-Code-Signing',
        ],
        'production' => [
            'label' => 'Production',
            'url' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
            'certificate_template' => 'ZATCA-Code-Signing',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice timezone
    |--------------------------------------------------------------------------
    |
    | Issue date and time are written in Saudi local time.
    |
    */

    'timezone' => env('ZATCA_TIMEZONE', 'Asia/Riyadh'),

    'timeout' => env('ZATCA_TIMEOUT', 60),

];
