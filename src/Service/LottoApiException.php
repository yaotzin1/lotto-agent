<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Sygnalizuje, że dane z LOTTO OpenAPI nie są dostępne.
 *
 * Istnieje po to, aby awaria pobierania danych NIE była cicho zamieniana
 * na pustą tablicę częstotliwości. Bez prawdziwych danych cała warstwa
 * statystyczna (hot/cold, affinity, ranking synergii) jest bezwartościowa
 * i użytkownik musi zostać o tym wyraźnie poinformowany.
 */
class LottoApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isAuthFailure(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }

    /**
     * Komunikat gotowy do pokazania użytkownikowi w CLI.
     */
    public function getUserMessage(): string
    {
        if ($this->isAuthFailure()) {
            return sprintf(
                "LOTTO OpenAPI odrzuciło klucz (HTTP %d).\n"
                . "Ustaw prawidłowy LOTTO_API_KEY w .env.dev (klucz uzyskasz na https://developers.lotto.pl).\n"
                . "Bez danych historycznych analiza częstotliwości, hot/cold i synergii NIE jest wykonywana.",
                $this->httpStatus
            );
        }

        return sprintf(
            "Nie udało się pobrać danych z LOTTO OpenAPI: %s\n"
            . "Bez danych historycznych analiza częstotliwości, hot/cold i synergii NIE jest wykonywana.",
            $this->getMessage()
        );
    }
}
