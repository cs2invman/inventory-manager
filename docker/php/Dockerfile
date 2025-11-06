FROM northern/php-symfony:8.4-fpm as php-base

ARG USER
ARG UID
ARG GID

RUN usermod -u $UID $USER || true
RUN groupmod -g $GID $USER || true

COPY ./docker/php/symfony.ini /usr/local/etc/php/conf.d/symfony.ini

RUN mkdir -p /var/www/html

RUN chown -R $USER:$USER /var/www

WORKDIR /var/www/html
USER $USER

FROM php-base as php-development

ARG USER

USER root
RUN pecl install xdebug

USER $USER

FROM php-base as php-production

ARG USER
ARG UID
ARG GID

# Switch to root to install production configs and copy files
USER root

# Install production PHP-FPM configs
COPY ./docker/php/disable-access-logs.conf /usr/local/etc/php-fpm.d/zzz-disable-access-logs.conf
COPY ./docker/php/increase-fpm-children.conf /usr/local/etc/php-fpm.d/zzz-increase-fpm-children.conf

# Copy application code
COPY --chown=$USER:$USER . /var/www/html

# Create writable directories
RUN mkdir -p /var/www/html/var/log /var/www/html/var/cache /var/www/html/var/sessions /var/www/html/var/data && \
    chown -R $USER:$USER /var/www/html/var

# Switch to app user for composer install
USER $USER

# Install production dependencies (no database needed)
RUN APP_ENV=prod composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Note: cache:clear and cache:warmup are deferred to runtime (entrypoint/first request)
# These commands require database connectivity and environment variables

# Copy OPcache preload configuration (loaded after composer autoload is ready)
USER root
COPY --chown=$USER:$USER ./docker/php/preload.ini /usr/local/etc/php/conf.d/preload.ini

# Switch back to app user
USER $USER
