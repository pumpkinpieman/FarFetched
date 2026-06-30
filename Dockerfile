# FarFetched — single-container image (Apache + PHP 8.3 + cron)
FROM php:8.3-apache
# --- PHP extensions: pdo_sqlite (queue) + curl is built-in via libcurl ---
# Also installs OpenSCAD (+ xvfb/xauth for headless rendering) used by the
# Customize & Pose parametric engine. The app auto-wraps OpenSCAD with xvfb-run
# when it detects it on PATH. Adds ~300-500 MB to the image.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        cron ca-certificates libsqlite3-dev libzip-dev \
        openscad xvfb xauth \
 && docker-php-ext-install pdo_sqlite zip \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
# --- App code ---
# webroot/ becomes the Apache DocumentRoot; private/ stays a sibling (volume).
WORKDIR /var/www/html
COPY webroot/ ./webroot/
COPY schema.sql ./schema.sql
# --- Apache vhost (container flavor) ---
COPY deploy/fetcher.docker.conf /etc/apache2/sites-available/000-default.conf
# --- Cron job (runs the worker as www-data) ---
COPY deploy/crontab /etc/cron.d/fetcher
RUN chmod 0644 /etc/cron.d/fetcher && crontab -u www-data /etc/cron.d/fetcher
# --- Entrypoint: prep dirs, start cron, hand off to Apache foreground ---
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
# Volumes: persistent state + the place files land.
VOLUME ["/var/www/html/private", "/downloads"]
ENV FETCHER_DOWNLOAD_DIR=/downloads \
    FETCHER_DOWNLOAD_DELAY=120
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
