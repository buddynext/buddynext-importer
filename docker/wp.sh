#!/bin/bash
# Shorthand for wp-cli inside the fixture container.
exec docker compose exec -T wp php -d memory_limit=1024M /usr/local/bin/wp --allow-root --path=/var/www/html "$@"
