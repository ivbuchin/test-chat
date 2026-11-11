FROM php:8.3.36-apache@sha256:a800bbd2c0aa322702259ed475556b2cbc311076a8d3c4bba9bfe96838745472

ADD --checksum=sha256:27fa8d80bd4e22feb9ddcadff1ca40c8f3e8ea942eb2dd8f446ed1afd2f2f0ab \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions redis-6.3.0 intl \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/chat.ini /usr/local/etc/php/conf.d/zz-chat.ini
COPY docker/apache.conf /etc/apache2/conf-available/zz-chat.conf
RUN a2enconf zz-chat

COPY --chown=root:root public/ /var/www/html/
COPY --chown=root:root src/ /var/www/src/
COPY --chown=root:root tests/ /var/www/tests/
RUN chmod -R go-w /var/www/html /var/www/src /var/www/tests

HEALTHCHECK --interval=10s --timeout=3s --retries=5 CMD curl -fsS http://localhost/api.php?action=health || exit 1
