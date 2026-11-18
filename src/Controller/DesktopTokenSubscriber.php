<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class DesktopTokenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%lotto_desktop_token%')]
        private readonly string $configuredToken = ''
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/') && $path !== '/api') {
            return;
        }

        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', Response::HTTP_NO_CONTENT);
            $this->applyCorsHeaders($request->headers->get('Origin'), $response);
            $event->setResponse($response);

            return;
        }

        $expectedToken = $this->resolveExpectedToken();
        if ($expectedToken === '') {
            return;
        }

        $authHeader = (string) $request->headers->get('Authorization', '');
        $providedToken = '';
        if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
            $providedToken = trim($matches[1]);
        }

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            $response = new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            $this->applyCorsHeaders($request->headers->get('Origin'), $response);
            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/') && $path !== '/api') {
            return;
        }

        $this->applyCorsHeaders($request->headers->get('Origin'), $event->getResponse());
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/') && $path !== '/api') {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        $message = $this->sanitizeMessage($exception->getMessage());
        if ($message === '') {
            $message = 'Internal server error.';
        }

        $response = new JsonResponse(['error' => $message], $status);
        $this->applyCorsHeaders($request->headers->get('Origin'), $response);
        $event->setResponse($response);
    }

    private function resolveExpectedToken(): string
    {
        $runtimeToken = $_SERVER['LOTTO_DESKTOP_TOKEN']
            ?? $_ENV['LOTTO_DESKTOP_TOKEN']
            ?? (getenv('LOTTO_DESKTOP_TOKEN') !== false ? (string) getenv('LOTTO_DESKTOP_TOKEN') : null);

        if (\is_string($runtimeToken)) {
            return trim($runtimeToken);
        }

        return trim($this->configuredToken);
    }

    private function applyCorsHeaders(?string $origin, Response $response): void
    {
        if ($origin !== null && $this->isAllowedOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
        }
    }

    private function isAllowedOrigin(string $origin): bool
    {
        if (\in_array($origin, ['tauri://localhost', 'http://tauri.localhost', 'https://tauri.localhost'], true)) {
            return true;
        }

        return (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin);
    }

    private function sanitizeMessage(string $message): string
    {
        foreach (['GEMINI_API_KEY', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'DEEPSEEK_API_KEY', 'LOTTO_API_KEY', 'LOTTO_DESKTOP_TOKEN'] as $envKey) {
            $secret = $_SERVER[$envKey] ?? $_ENV[$envKey] ?? getenv($envKey);
            if (\is_string($secret) && strlen(trim($secret)) >= 4) {
                $message = str_replace(trim($secret), '[REDACTED]', $message);
            }
        }

        return $message;
    }
}
