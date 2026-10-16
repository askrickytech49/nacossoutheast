<?php
// Copy this file to config.local.php on your own server and add your private values.
return [
    'database' => [
        'host' => '127.0.0.1',
        'name' => 'nacos_portal',
        'user' => 'nacos_app',
        'password' => 'CHANGE_THIS_DATABASE_PASSWORD',
    ],
    'brevo' => [
        'api_key' => 'ADD_YOUR_BREVO_API_KEY',
        'sender_email' => 'hello@diamondnestbk.com',
        'sender_name' => 'NACOS South East',
    ],
    'paystack' => [
        'public_key' => 'ADD_YOUR_PAYSTACK_PUBLIC_KEY',
        'secret_key' => 'ADD_YOUR_PAYSTACK_SECRET_KEY',
    ],
    'admin_email' => 'nacosoutheast@gmail.com',
    'session_secure' => false, // Set true when the site is served over HTTPS.
];
