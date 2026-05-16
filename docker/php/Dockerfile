FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk update && \
    apk add --no-cache \
    curl \
    freetype-dev \
    g++ \
    git \
    icu-dev \
    libavif-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
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
# gd mit --with-avif: der ProfilePhotoOptimizer speichert Profilfotos als AVIF.
# --with-webp: WebP-Uploads sind in der Profilfoto-Validierung freigegeben.
# exif: für die EXIF-Orientierungskorrektur von Hochformat-Fotos beim Upload.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-avif --with-webp && \
    docker-php-ext-install \
    bcmath \
    exif \
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
