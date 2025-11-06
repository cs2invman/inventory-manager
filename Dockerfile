# ============================================================================
# Base Stage: Common dependencies for all stages
# ============================================================================
FROM php:8.4-fpm-alpine AS base

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

# Install APCu for production caching
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del .build-deps

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

# ============================================================================
# Development Stage: For local development with volumes
# ============================================================================
FROM base AS development

# Create user with same UID/GID as host user (for development)
ARG USER_ID=1001
ARG GROUP_ID=1001
RUN addgroup -g ${GROUP_ID} appuser && \
    adduser -D -u ${USER_ID} -G appuser appuser

# Copy PHP-FPM configuration
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Create var directory structure
RUN mkdir -p var/cache var/log var/sessions var/data \
    && chown -R appuser:appuser var/ \
    && chmod -R 777 var/

EXPOSE 9000

# PHP-FPM needs to run as root to bind to port, but will drop privileges via pool config
CMD ["php-fpm"]

# ============================================================================
# Production Build Stage: Install dependencies
# ============================================================================
FROM base AS production-builder

# Copy composer files first for better Docker layer caching
COPY composer.json composer.lock symfony.lock ./

# Install production dependencies (no dev dependencies)
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --prefer-dist

# ============================================================================
# Production Stage: Final production image
# ============================================================================
FROM base AS production

# Create non-root user for running the application
RUN addgroup -g 1000 appuser && \
    adduser -D -u 1000 -G appuser appuser

# Copy application files
COPY --chown=appuser:appuser . .

# Copy vendor from builder stage
COPY --from=production-builder --chown=appuser:appuser /var/www/html/vendor ./vendor

# Copy PHP-FPM configuration
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Create var directory structure with proper permissions
RUN mkdir -p var/cache var/log var/sessions var/data/steam-items/import var/data/steam-items/processed \
    && chown -R appuser:appuser var/ \
    && chmod -R 775 var/

# Run composer scripts (post-install tasks)
RUN composer run-script --no-dev post-install-cmd || true

# Set proper permissions for application files
RUN chown -R appuser:appuser /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 var/

# Remove Composer from production image to reduce size
RUN rm -f /usr/local/bin/composer

# Clean up unnecessary files
RUN rm -rf /tmp/* /var/tmp/* /usr/share/man /usr/share/doc

EXPOSE 9000

# PHP-FPM needs to run as root to bind to port, but will drop privileges via pool config
CMD ["php-fpm"]