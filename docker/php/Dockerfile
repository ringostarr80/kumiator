FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    sqlite-dev \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    icu-dev \
    curl \
    zip \
    unzip \
    git \
    nodejs \
    npm

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_sqlite \
    mbstring \
    xml \
    bcmath \
    zip \
    opcache \
    pcntl \
    intl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy PHP config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

# Copy entrypoint
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
