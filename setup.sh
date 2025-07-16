#!/bin/bash

# Create Symfony project
composer create-project symfony/skeleton .

# Install additional Symfony packages
composer require symfony/orm-pack
composer require symfony/maker-bundle --dev
composer require symfony/security-bundle
composer require symfony/validator
composer require symfony/form
composer require symfony/serializer
composer require symfony/property-access
composer require symfony/asset
composer require symfony/twig-bundle
composer require symfony/webpack-encore-bundle
composer require symfony/dotenv
composer require symfony/monolog-bundle
composer require symfony/debug-bundle --dev
composer require symfony/web-profiler-bundle --dev

# Install Doctrine fixtures for testing
composer require --dev doctrine/doctrine-fixtures-bundle

# Install API Platform for RESTful API
composer require api-platform/core

# Install frontend dependencies
npm install --force
npm install @material-ui/core @material-ui/icons react react-dom react-router-dom axios --force
npm install @babel/preset-react --force

# Build frontend assets
npm run build

# Create database and run migrations
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction

echo "Setup completed successfully!"