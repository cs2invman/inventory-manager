FROM php:8.4-fpm-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    mysql-client \
    shadow \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        gd \
        intl \
        pdo \
        pdo_mysql \
        zip \
        opcache \
        mbstring

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Create user with same UID/GID as host user
ARG USER_ID=1001
ARG GROUP_ID=1001
RUN addgroup -g ${GROUP_ID} appuser && \
    adduser -D -u ${USER_ID} -G appuser appuser

# Create application directory
WORKDIR /var/www/html

# Copy composer files first for better Docker layer caching
COPY composer.json ./

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application files
COPY --chown=appuser:appuser . .

# Set proper permissions
RUN chown -R appuser:appuser /var/www/html \
    && chmod -R 755 /var/www/html

# Create var directory structure if it doesn't exist
RUN mkdir -p var/cache var/log var/sessions \
    && chown -R appuser:appuser var/ \
    && chmod -R 777 var/

# Copy PHP-FPM configuration
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Run composer scripts after copying all files
RUN composer run-script --no-dev post-install-cmd || true

EXPOSE 9000

# PHP-FPM needs to run as root to bind to port, but will drop privileges via pool config
CMD ["php-fpm"]