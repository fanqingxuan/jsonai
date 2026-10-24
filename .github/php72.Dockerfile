# Exact minimum runtime; setup-php's 7.2 channel normally selects the final 7.2 patch.
# Used only for disposable CI tests, never as an application deployment image.
FROM php:7.2.6-cli

# The original image is Debian Stretch. Its repositories have moved to the archive.
RUN printf '%s\n' 'deb [trusted=yes] http://archive.debian.org/debian stretch main' > /etc/apt/sources.list \
    && rm -f /etc/apt/sources.list.d/* \
    && apt-get -o Acquire::Check-Valid-Until=false update \
    && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j2 pdo_mysql pdo_pgsql pcntl curl \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/5.3.7.tar.gz -o /tmp/redis.tar.gz \
    && printf '%s\n' '6f5cda93aac8c1c4bafa45255460292571fb2f029b0ac4a5a4dc66987a9529e6  /tmp/redis.tar.gz' | sha256sum -c - \
    && mkdir /tmp/phpredis \
    && tar -xzf /tmp/redis.tar.gz --strip-components=1 -C /tmp/phpredis \
    && cd /tmp/phpredis \
    && phpize \
    && ./configure --enable-redis \
    && make -j2 \
    && make install \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/phpredis /tmp/redis.tar.gz

WORKDIR /package
