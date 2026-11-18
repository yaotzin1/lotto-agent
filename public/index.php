<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    $envFile = $_SERVER['LOTTO_ENV_FILE'] ?? $_ENV['LOTTO_ENV_FILE'] ?? getenv('LOTTO_ENV_FILE');
    if (is_string($envFile) && $envFile !== '' && is_readable($envFile)) {
        (new Dotenv())->overload($envFile);
        foreach ($_ENV as $k => $v) {
            $context[$k] = $v;
        }
    }

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};

