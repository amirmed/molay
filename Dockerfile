FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite headers

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_sqlite curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache config: allow .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy project files
COPY . /var/www/html/

# Create db directory with write permissions
RUN mkdir -p /var/www/html/db && \
    chown -R www-data:www-data /var/www/html/db && \
    chmod -R 775 /var/www/html/db

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Use PORT environment variable (Railway sets this)
RUN sed -i 's/Listen 80/Listen ${PORT}/g' /etc/apache2/ports.conf && \
    sed -i 's/:80/:${PORT}/g' /etc/apache2/sites-available/000-default.conf

EXPOSE ${PORT}

CMD ["apache2-foreground"]
