FROM northern/php-symfony:8.4-fpm as php-base

ARG USER
ARG UID
ARG GID

RUN usermod -u $UID $USER || true
RUN groupmod -g $GID $USER || true

# Install curl extension if not present
RUN if ! php -m | grep -q curl; then \
    apt-get update && \
    apt-get install -y libcurl4-openssl-dev && \
    docker-php-ext-install curl && \
    apt-get clean && rm -rf /var/lib/apt/lists/*; \
    fi

COPY ./docker/php/symfony.ini /usr/local/etc/php/conf.d/symfony.ini

RUN mkdir -p /var/www/html

RUN chown -R $USER:$USER /var/www

WORKDIR /var/www/html
USER $USER

FROM php-base as php-development

ARG USER

USER root
RUN pecl install xdebug && docker-php-ext-enable xdebug

USER $USER

FROM php-base as php-production

ARG USER

COPY ./docker/php/disable-access-logs.conf /usr/local/etc/php-fpm.d/zzz-disable-access-logs.conf
COPY ./docker/php/increase-fpm-children.conf /usr/local/etc/php-fpm.d/zzz-increase-fpm-children.conf

COPY --chown=$USER . /var/www/html

RUN mkdir -p /var/www/html/var/log

RUN APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader
RUN composer dump-env prod
RUN bin/console cache:clear
RUN bin/console cache:warmup

COPY --chown=$USER ./docker/php/preload.ini /usr/local/etc/php/conf.d/preload.ini
