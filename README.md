# Lotto Agent

A Symfony 7 CLI application containerized with Docker, designed as an AI lotto agent utilizing Gemini models.

## Requirements
- Docker
- Docker Compose

## Setup and Installation

1. **Environment Setup:**
   Ensure your `.env.dev` or `.env` files are configured with the required API keys (e.g., Gemini API keys).
   ```bash
   cp .env.dev .env
   ```

2. **Build the Docker Container:**
   ```bash
   docker-compose build
   ```

3. **Install Dependencies (Composer):**
   ```bash
   docker-compose run --rm app composer install
   ```

## Usage

You can interact with the application via the Symfony Console inside the Docker container.

To list all available commands:
```bash
docker-compose run --rm app php bin/console list
```

### Running the Commands

Run the main agent command. You will be prompted interactively to choose a game and specify the number of numbers to analyze if you do not provide them as options:
```bash
docker-compose run --rm app php bin/console app:lotto-agent
```

You can also pass them directly as options:
```bash
docker-compose run --rm app php bin/console app:lotto-agent --game=EuroJackpot --pick=5 --bets=8 --months=12 --neighbours
```

To run the advanced mathematical generator:
```bash
docker-compose run --rm app php bin/console app:lotto-generator
```

## Project Architecture
- **PHP 8.2 CLI (Alpine)** - Base image for the application.
- **Symfony 7.4.x** - Underlying framework handling the console application and dependency injection.
- `src/Command/` - Contains the custom commands (`LottoAgentCommand`, `GeminiModelsCommand`, `LottoCommand`).
