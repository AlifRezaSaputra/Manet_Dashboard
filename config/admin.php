<?php

return [
    'password_hash' => env('ADMIN_PASSWORD_HASH'),
    'token_ttl'     => 3 * 60 * 60, // 3 jam, dalam detik
];