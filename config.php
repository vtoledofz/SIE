<?php
return [
    'db_host' => 'bbdd.gadgetsymas.onl',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'currency' => 'EUR',
    'tax_rate' => 0.21,
    'shipping_fee' => 3.95,
    'shipping_step_grams' => 500,
    'shipping_extra_per_step' => 1.50,
    'free_shipping_from' => 45.00,
    'base_url' => 'https://www.gadgetsymas.onl',
    'stripe' => [
        'enabled' => true,
        'allow_live_payments' => false,
        'secret_key' => '',
        'webhook_secret' => '',
        'currency' => 'eur',
        'timeout' => 25,
    ],
    'admin_key' => '',
    'mail' => [
        'enabled' => true,
        'host' => 'smtp.dondominio.com',
        'port' => 587,
        'security' => 'starttls',
        'timeout' => 15,
        'orders' => [
            'address' => 'pedidos@gadgetsymas.onl',
            'password' => '',
        ],
        'support' => [
            'address' => 'soporte@gadgetsymas.onl',
            'password' => '',
        ],
    ],
];
