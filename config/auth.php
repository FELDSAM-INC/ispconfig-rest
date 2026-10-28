<?php

// API authentication is exclusively handled by ApiKeyAuth. There is no
// application User model, session login, bearer-token guard or password broker.
return [
    'defaults' => ['guard' => null, 'passwords' => null],
    'guards' => [],
    'providers' => [],
    'passwords' => [],
];
