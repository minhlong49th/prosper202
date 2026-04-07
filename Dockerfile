FROM php:8.3-apache

# Enable Apache mod_rewrite and mod_ssl
RUN a2enmod rewrite ssl

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libmemcached-dev \
    zlib1g-dev \
    libssl-dev \
    libzip-dev \
    unzip \
    git \
    openssl \
    && rm -rf /var/lib/apt/lists/*

# Generate self-signed certificate and enable default SSL site
RUN openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/ssl/private/ssl-cert-snakeoil.key \
    -out /etc/ssl/certs/ssl-cert-snakeoil.pem \
    -subj "/CN=prosper202.test" \
    && a2ensite default-ssl

# Install PHP extensions
RUN pecl install memcached \
    && docker-php-ext-enable memcached \
    && docker-php-ext-install mysqli pdo_mysql zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Custom entrypoint to run composer install and set permissions
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
