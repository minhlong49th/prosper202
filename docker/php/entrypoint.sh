#!/bin/sh
set -e

# Run composer install if composer.json exists
if [ -f "composer.json" ]; then
    echo "Running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Ensure proper permissions (optional based on your need)
chown -R www-data:www-data /var/www/html

# Run automatic database migrations if Prosper202 is already installed
echo "Checking for database migrations..."
php docker/php/run_migrations.php || true

# Execute the main container command (apache2-foreground)
exec "$@"
