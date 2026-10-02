FROM composer:2.8.12 AS composer
FROM php:8.2.30-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip locales-all libicu-dev libzip-dev libpq-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libxml2-dev libsodium-dev libcurl4-openssl-dev libonig-dev curl ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && missing_extensions="$(php -r 'foreach (["intl","zip","pgsql","gd","soap","exif","sodium","Zend OPcache","curl","mbstring","dom","xml","xmlreader"] as $ext) { if (!extension_loaded($ext)) echo ($ext === "Zend OPcache" ? "opcache" : $ext) . " "; }')" \
    && if [ -n "$missing_extensions" ]; then docker-php-ext-install -j2 $missing_extensions; fi \
    && rm -rf /var/lib/apt/lists/*
# Match the dump/restore client major to the PostgreSQL 16 test server.
# Debian bookworm's default client is 15 and cannot dump a newer server.
RUN install -d /usr/share/postgresql-common/pgdg \
    && curl --fail --silent --show-error https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo 'deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main' > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends postgresql-client-16 \
    && /usr/lib/postgresql/16/bin/pg_dump --version \
    && rm -rf /var/lib/apt/lists/*
ENV PATH="/usr/lib/postgresql/16/bin:${PATH}"
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY tests/stage/runtime.json /opt/ustar-runtime.json
RUN printf 'max_input_vars=5000\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/ustar-tests.ini
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN git clone --depth 1 --branch v5.1.1 https://github.com/moodle/moodle.git /opt/moodle \
    && test "$(git -C /opt/moodle rev-parse HEAD)" = "$(php -r 'echo json_decode(file_get_contents("/opt/ustar-runtime.json"), true)["moodle_commit"];')" \
    && cp /usr/local/bin/composer /opt/moodle/composer.phar \
    && cd /opt/moodle && composer install --no-interaction --prefer-dist --no-progress
WORKDIR /stage
ENTRYPOINT ["bash", "/source/tests/stage/run.sh"]
