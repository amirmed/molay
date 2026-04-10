FROM php:8.2-apache

# Fix MPM conflict: disable prefork, keep mpm_prefork only
RUN a2dismod mpm_event mpm_worker 2>/dev/null; a2enmod mpm_prefork rewrite headers

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
    chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/db

# Dynamic PORT for Railway
RUN echo 'ServerName localhost\n' >> /etc/apache2/apache2.conf

# Use a startup script to handle dynamic PORT
RUN echo '#!/bin/bash\n\
sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf\n\
sed -i "s/:80/:${PORT:-80}/" /etc/apache2/sites-available/000-default.conf\n\
apache2-foreground' > /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

CMD ["start.sh"]
