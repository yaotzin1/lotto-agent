# Mini-Lotto Simulator

A Dockerized Symfony 7 web application that analyzes Mini-Lotto number combinations against historical draw data.

## Features

- Loads Mini-Lotto draw data from CSV
- Allows users to select 6-12 numbers
- Allows users to specify the range of past draws to analyze (50-500)
- Generates all possible 5-number combinations from the selected numbers
- Evaluates combinations against historical draw data
- Displays counts of 3/5, 4/5, and 5/5 hits
- Responsive UI with Bootstrap 5

## Technical Stack

- PHP 8.4
- Symfony 7
- FrankenPHP
- Caddy web server
- PostgreSQL database (optional)
- Docker & Docker Compose

## Setup and Installation

1. Clone this repository:
   ```
   git clone https://github.com/yourusername/mini-lotto-simulator.git
   cd mini-lotto-simulator
   ```

2. Copy the local configuration files:
   ```
   copy docker-compose.override.yml.local docker-compose.override.yml
   ```

3. Start the Docker containers:
   ```
   docker-compose up -d
   ```

4. Install dependencies:
   ```
   docker-compose exec app composer install
   ```

5. Access the application:
   ```
   http://localhost:9990
   ```

## Project Structure

- `src/Controller/LottoController.php`: Main controller for the application
- `src/Service/DataLoader.php`: Service for loading draw data from CSV
- `src/Service/Evaluator.php`: Service for generating combinations and evaluating hits
- `templates/lotto/index.html.twig`: Main template for the UI
- `data/results.csv`: Sample draw data

## Development

- The application is configured for development with volume mounts for live code changes
- Modify the code and see changes immediately without rebuilding containers
- Add more draw data to `data/results.csv` for more comprehensive analysis

## License

MIT