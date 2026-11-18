<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        $envFile = $_SERVER['LOTTO_ENV_FILE'] ?? $_ENV['LOTTO_ENV_FILE'] ?? getenv('LOTTO_ENV_FILE');
        if (\is_string($envFile) && $envFile !== '' && is_readable($envFile)) {
            (new Dotenv())->overload($envFile);
        }

        parent::boot();
    }

    public function getCacheDir(): string
    {
        $cacheDir = $_SERVER['APP_CACHE_DIR'] ?? $_ENV['APP_CACHE_DIR'] ?? null;
        if (\is_string($cacheDir) && $cacheDir !== '') {
            return rtrim($cacheDir, '/\\').'/'.$this->environment;
        }

        $appData = $_SERVER['LOTTO_APP_DATA_DIR'] ?? $_ENV['LOTTO_APP_DATA_DIR'] ?? null;
        if (\is_string($appData) && $appData !== '') {
            return rtrim($appData, '/\\').'/var/cache/'.$this->environment;
        }

        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        $logDir = $_SERVER['APP_LOG_DIR'] ?? $_ENV['APP_LOG_DIR'] ?? null;
        if (\is_string($logDir) && $logDir !== '') {
            return rtrim($logDir, '/\\');
        }

        $appData = $_SERVER['LOTTO_APP_DATA_DIR'] ?? $_ENV['LOTTO_APP_DATA_DIR'] ?? null;
        if (\is_string($appData) && $appData !== '') {
            return rtrim($appData, '/\\').'/var/log';
        }

        return parent::getLogDir();
    }
}

