#!/bin/sh
set -e

composer install --no-interaction --quiet --working-dir=/app

until mysqladmin ping -h db -uroot -prootpass --silent --skip-ssl; do
    sleep 1
done

mysql -h db -uroot -prootpass --skip-ssl -e "CREATE DATABASE IF NOT EXISTS wordpress_test"

exec /app/vendor/bin/phpunit --configuration /app/phpunit.xml.dist "$@"
