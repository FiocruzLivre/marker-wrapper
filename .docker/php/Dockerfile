FROM php:8.5.12-fpm

# Pinned installer with an upstream-verified SHA-256 (release 2.12.3).
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.12.3/install-php-extensions /usr/local/bin/install-php-extensions
RUN set -eux; \
    echo '9c26dd516b8147235c4e924c52e2f076fb2b3f278f5457e88918518ebfee9e01  /usr/local/bin/install-php-extensions' | sha256sum -c -; \
    chmod 0755 /usr/local/bin/install-php-extensions; \
    install-php-extensions \
        imagick \
        opcache \
        @composer; \
    rm -f /usr/local/bin/install-php-extensions

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY .docker/php/config/php.ini "$PHP_INI_DIR/conf.d/zz-marker.ini"
COPY .docker/php/config/marker-fpm.conf /usr/local/etc/php-fpm.d/zz-marker-pool.conf

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --classmap-authoritative \
    && composer audit --locked --no-dev --no-interaction
COPY src ./src
COPY public ./public
COPY bin ./bin
COPY icons/maki/icons ./icons/maki/icons
RUN composer dump-autoload --no-interaction --classmap-authoritative
RUN mkdir -p /app/cache \
    && chown -R www-data:www-data /app/cache \
    && chmod 0750 /app/cache

USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]
