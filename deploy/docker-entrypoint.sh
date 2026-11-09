#!/usr/bin/env bash
# Entrypoint script for PHP-FPM Docker container

set -e

# Get the PID of the current shell
CURRENT_PID=$$

echo "Starting PHP-FPM with PID: $CURRENT_PID"

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
until nc -z mysql 3306; do
  >&2 echo "MySQL is unavailable - sleeping"
  sleep 1
done
>&2 echo "MySQL is available - proceeding"

# Run the entrypoint script if it exists
if [ -f /entrypoint.sh ]; then
  exec /entrypoint.sh "$@"
fi

# Start PHP-FPM
exec php-fpm
