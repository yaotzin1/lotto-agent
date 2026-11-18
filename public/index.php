<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

if (!is_file(dirname(__DIR__).'/.env')) {
    $_SERVER['APP_RUNTIME_OPTIONS'] = array_merge($_SERVER['APP_RUNTIME_OPTIONS'] ?? [], [
        'disable_dotenv' => true,
    ]);
    if (!isset($_SERVER['APP_SECRET']) && !isset($_ENV['APP_SECRET']) && getenv('APP_SECRET') === false) {
        $fallbackSecret = $_SERVER['LOTTO_DESKTOP_TOKEN'] ?? $_ENV['LOTTO_DESKTOP_TOKEN'] ?? getenv('LOTTO_DESKTOP_TOKEN');
        $_SERVER['APP_SECRET'] = (is_string($fallbackSecret) && $fallbackSecret !== '') ? $fallbackSecret : 'lotto-agent-desktop-local';
        $_ENV['APP_SECRET'] = $_SERVER['APP_SECRET'];
    }
}

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

