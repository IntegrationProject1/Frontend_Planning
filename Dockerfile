# Extend the official WordPress image
FROM wordpress:latest
 
# Install system dependencies for Composer + nano
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
        nano && \
    rm -rf /var/lib/apt/lists/*
 
# Install Composer
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer
 
# Set working directory to WordPress root
WORKDIR /var/www/html
 
# Use Composer to require your PHP AMQP library
RUN composer require php-amqplib/php-amqplib google/apiclient

# Ensure proper permissions
RUN chown -R www-data:www-data /var/www/html

