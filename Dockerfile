FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Keep function arguments (API key, Defuse key, ciphertext) out of stack traces.
RUN printf 'zend.exception_ignore_args=On\ndisplay_errors=stderr\n' > /usr/local/etc/php/conf.d/sucrets.ini

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app

CMD ["sleep", "infinity"]
