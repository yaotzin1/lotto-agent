# Lotto Agent - AI Integration Guide

This application acts as an AI-powered agent for analyzing lottery-related data. It leverages external AI models (specifically Google's Gemini models) to process information.

## Agent Architecture

The agent is built using Symfony Console Commands, which provide a robust interface for CLI execution.

### Key Commands

1. **LottoAgentCommand (`src/Command/LottoAgentCommand.php`)**
   This is the primary orchestrator. It likely handles the core logic of the agent, fetching the required data, sending it to the AI model, and presenting the output.

2. **LottoGeneratorCommand (`src/Command/LottoGeneratorCommand.php`)**
   Mathematical generation suite. Integrates advanced generation algorithms (like Floating Bankers, Smart Croupier, Fractal generator) with an optional AI-driven pool selection.

3. **GeminiModelsCommand (`src/Command/GeminiModelsCommand.php`)**
   This command focuses on interaction with the Google Gemini API. It can be used for tasks like listing available models, testing the connection, or running specific, isolated prompts.

4. **LottoCommand (`src/Command/LottoCommand.php`)**
   Handles the standard lottery operations. This might involve scraping or downloading recent draw results, parsing rules, or structuring data before it's passed to the AI agent.

## Environment Configuration

For the agent to function properly, it must authenticate with the AI provider.
Make sure your `.env` (or `.env.dev` if running locally via Docker Compose) contains the appropriate API keys:

```env
# Example
GEMINI_API_KEY=your_api_key_here
```

## Executing Agent Tasks

To run a specific agent task, use `docker-compose` to execute the PHP console:

```bash
docker-compose run --rm app php bin/console <command-name>
```

If you wish to interact with the container directly (e.g., to run multiple commands without restarting the container), you can open a shell:

```bash
docker-compose run --rm app sh
# then inside the container:
php bin/console <command-name>
```
