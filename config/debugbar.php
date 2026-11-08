<?php

return [
    'enabled' => env('APP_ENV', 'production') === 'local'
        && env('APP_DEBUG', false)
        && env('DEBUGBAR_ENABLED', true),
];
