# Node kommt aus dem offiziellen Image, weil Alpines nodejs-Paket der Alpine-Version des PHP-Images
# folgt statt der Major-Version aus .nvmrc.
FROM node:26-alpine AS node

FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk update && \
    apk add --no-cache \
    curl \
    freetype-dev \
    g++ \
    git \
    icu-dev \
    libatomic \
    libavif-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libstdc++ \
    libwebp-dev \
    libxml2-dev \
    libzip-dev \
    make \
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

# Install Node
# libatomic und libstdc++ aus dem apk-Block braucht node zur Laufzeit.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm && \
    ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Copy PHP config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

# Copy entrypoint
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
