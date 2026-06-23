<?php
declare(strict_types=1);

// config/app.php

return [
    'jwt_secret'    => $_ENV['JWT_SECRET'] ?? 'change-this-in-production-please',
    'jwt_ttl'       => (int) ($_ENV['JWT_TTL'] ?? 86400), // 24 hours, in seconds
    'cors_origin'   => $_ENV['CORS_ORIGIN'] ?? '*',
];
