# PHP 8.5.12 for Hyperf (Linux)
FROM php:8.5.12-cli-trixie

# Install system dependencies and Composer
RUN apt-get update && \
    apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip \
    libzip-dev autoconf g++ make re2c pkg-config wget libicu-dev \
    libssl-dev zlib1g-dev libbrotli-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP extensions for Hyperf (including Swoole)
RUN pecl install swoole \
    && docker-php-ext-enable swoole \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath soap zip intl sockets gd \
    && rm -rf /tmp/pear

RUN echo "swoole.use_shortname = Off" >> /usr/local/etc/php/conf.d/99-swoole.ini

# Configure PHP OPcache and display settings (in single RUN command)
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/default.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/default.ini && \
    echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/default.ini && \
    echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/default.ini && \
    echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/default.ini && \
    echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/default.ini && \
    echo "opcache.revalidate_freq=0" >> /usr/local/etc/php/conf.d/default.ini

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/Hyperf-Ju

EXPOSE 9501 9502

CMD ["php", "bin/hyperf.php", "start"]
