#!/bin/bash
set -e

# Wait for any initialization if needed
echo "Starting DrugLab on Railway..."

# Handle PORT environment variable (Railway sets this)
PORT=${PORT:-80}
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Ensure data directory exists with correct permissions
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data
chmod 775 /var/www/html/data

# Generate APP_KEY if not set (for encryption)
if [ -z "$APP_KEY" ]; then
    export APP_KEY=$(php -r "echo base64_encode(random_bytes(32));")
    echo "Generated APP_KEY: $APP_KEY"
fi

# Start Apache
exec apache2-foreground