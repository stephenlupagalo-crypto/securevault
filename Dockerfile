# SecureVault — Railway Dockerfile
# Railway detects and builds this automatically on push; no other
# config needed beyond setting Variables in the Railway dashboard.

FROM php:8.2-apache

# PDO MySQL driver (the app uses PDO exclusively, not mysqli)
RUN docker-php-ext-install pdo pdo_mysql

# Enable the Apache modules our .htaccess relies on
RUN a2enmod rewrite headers

# Allow .htaccess to actually take effect (Apache's default is to ignore it)
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Realistic upload limits, matching MAX_FILE_SIZE in includes/config.php
RUN { \
      echo 'upload_max_filesize = 50M'; \
      echo 'post_max_size = 55M'; \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 120'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# App files
COPY . /var/www/html/

# The uploads/ folder needs to be writable by Apache's user, and (see
# DEPLOY_RAILWAY.md) should be mounted as a Railway Volume so files
# survive redeploys
RUN chown -R www-data:www-data /var/www/html/uploads

# Railway assigns $PORT at container startup (it varies), so Apache's
# listen port is set at runtime by this script, not baked in at build time
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
ENV PORT=8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

