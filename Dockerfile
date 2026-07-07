FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        cron ca-certificates libsqlite3-dev libzip-dev \
        openscad xvfb xauth \
        fonts-dejavu-core fonts-liberation2 fonts-noto-core fonts-roboto \
 && docker-php-ext-install pdo_sqlite zip \
 && a2enmod rewrite headers \
 && printf '<?xml version="1.0"?>\n<!DOCTYPE fontconfig SYSTEM "fonts.dtd">\n<fontconfig>\n  <dir>/var/www/html/private/fonts</dir>\n  <cachedir>/var/www/html/private/fontcache</cachedir>\n</fontconfig>\n' > /etc/fonts/local.conf \
 && rm -rf /var/lib/apt/lists/*

# OpenSCAD library: BOSL2 on OPENSCADPATH
RUN mkdir -p /opt/openscad-libs \
 && curl -fsSL https://github.com/BelfrySCAD/BOSL2/archive/refs/heads/master.tar.gz \
      | tar xz -C /opt/openscad-libs \
 && mv /opt/openscad-libs/BOSL2-master /opt/openscad-libs/BOSL2
ENV OPENSCADPATH=/opt/openscad-libs

WORKDIR /var/www/html
COPY webroot/ ./webroot/
COPY schema.sql ./schema.sql
COPY deploy/fetcher.docker.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/crontab /etc/cron.d/fetcher
RUN chmod 0644 /etc/cron.d/fetcher && crontab -u www-data /etc/cron.d/fetcher
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

VOLUME ["/var/www/html/private", "/downloads"]
ENV FETCHER_DOWNLOAD_DIR=/downloads \
    FETCHER_DOWNLOAD_DELAY=120
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]