FROM php:8.2-cli

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_sqlite curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy project files
COPY . /var/www/html/

# Create db directory with write permissions
RUN mkdir -p /var/www/html/db && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/db

WORKDIR /var/www/html

# Use PHP built-in server (no Apache needed)
CMD php -S 0.0.0.0:${PORT:-8080} -t /var/www/html
