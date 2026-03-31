FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk update && \
    apk add --no-cache \
    curl \
    freetype-dev \
    g++ \
    git \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    make \
    nodejs \
    npm \
    oniguruma-dev \
    php84-dev \
    sqlite-dev \
    unzip \
    zip

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install \
    bcmath \
    gd \
    mbstring \
    intl \
    opcache \
    pcntl \
    pdo_sqlite \
    xml \
    zip

RUN pecl install pcov && \
    docker-php-ext-enable pcov

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
