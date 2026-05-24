# XAMPP-style Apache + PHP 8.2 container for Symfony
FROM php:8.2-apache

# Enable Apache mod_rewrite (required by Symfony .htaccess)
RUN a2enmod rewrite

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Configure and compile PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) \
    intl \
    zip \
    gd \
    pdo_mysql \
    opcache \
    bcmath \
    mbstring

# Copy php.ini production overrides
COPY ./docker/php.ini /usr/local/etc/php/conf.d/docker-vars.ini

# Set Apache DocumentRoot to Symfony's public/ directory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides in DocumentRoot
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Get composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy project files
COPY . .

# Create required directories & set ownership
RUN mkdir -p var/cache var/log var/share && \
    chown -R www-data:www-data var public

# Install dependencies (production optimized)
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Set final ownership
RUN chown -R www-data:www-data .

EXPOSE 80
