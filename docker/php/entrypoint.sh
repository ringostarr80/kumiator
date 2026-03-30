#!/bin/sh
set -e

# Ensure writable directories have correct permissions
chmod -R 777 storage bootstrap/cache

# Create SQLite database if it does not exist
if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi

# Install Composer dependencies if vendor directory is missing
if [ ! -d vendor ]; then
    composer install --no-interaction --optimize-autoloader
fi

# Build frontend assets if not already built
if [ ! -d public/build ]; then
    npm ci && npm run build
fi

# Run database migrations
php artisan migrate --force

exec "$@"
