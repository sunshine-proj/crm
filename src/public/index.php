<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';
// print_r("hash:" . password_hash("admin1234", PASSWORD_DEFAULT));
return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
