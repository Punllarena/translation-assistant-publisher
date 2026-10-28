FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client git libpng-dev libzip-dev unzip \
    && docker-php-ext-install mysqli gd zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["--testsuite", "Translation Assistant Publisher"]
