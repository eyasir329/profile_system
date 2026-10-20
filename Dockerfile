# Web service image.
#
# Builds on the official PHP 8.3 + Apache image and adds:
#   - GD (with freetype/jpeg/webp) for image upload processing
#   - pdo_mysql for the database connection
#   - fileinfo for MIME-type sniffing on uploads
#   - mariadb-client so the entrypoint can run schema + migrations
#   - bash + coreutils (some base images ship with dash as /bin/sh; we use bash)
#
# Project layout (on the host):
#   public/        Apache DocumentRoot (baked into /var/www/html)
#   src/           Application PHP source (baked into /var/www/src)
#   database/      Schema + migrations (baked into /var/www/database)
#   docker/        Entrypoint + db-init scripts
#
# On first start, /usr/local/bin/web-entrypoint.sh:
#   - waits for the database
#   - runs database/schema.sql
#   - applies database/migrations/*.sql in order (tracked in a migrations table)
#   - launches Apache in the foreground

FROM public.ecr.aws/docker/library/php:8.3-apache

ENV DEBIAN_FRONTEND=noninteractive

# System deps:
#   - libpng/libjpeg/libwebp/freetype: build-time deps for GD
#   - libzip-dev: useful for future zip/unzip support
#   - mariadb-client: provides the `mariadb` CLI used by the entrypoint
#   - bash: explicit, even though most Debian variants have it
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libzip-dev \
        mariadb-client bash \
    && rm -rf /var/lib/apt/lists/*

# Install + enable PHP extensions.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd fileinfo

# Apache modules.
RUN a2enmod rewrite headers

# Apache and PHP settings: error pages, no directory listings, no code in
# uploads, no version headers, errors logged instead of shown.
# "zz-" so it loads after the image's security.conf, which it overrides.
COPY docker/apache/app.conf /etc/apache2/conf-available/zz-profile-system.conf
RUN a2enconf zz-profile-system
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-profile-system.ini

# Working directory. The whole project is copied to /var/www, but Apache's
# doc root is /var/www/html. We sync the public/ tree into html/ below.
WORKDIR /var/www

# Copy the app. The .dockerignore keeps the build context small.
COPY . /var/www

# Materialize the public/ tree as Apache's DocumentRoot, and stage the uploads
# directory so the named volume can overlay it cleanly.
RUN mkdir -p /var/www/html/uploads/profiles \
 && cp -r /var/www/public/. /var/www/html/ \
 && chown -R www-data:www-data /var/www/html /var/www/src /var/www/database

# Entrypoint waits for DB, runs schema/migrations, then execs the CMD.
COPY docker/web-entrypoint.sh /usr/local/bin/web-entrypoint.sh
RUN chmod +x /usr/local/bin/web-entrypoint.sh

# Default Apache port; compose.yml maps host:8080 -> container:80.
EXPOSE 80

# Healthcheck: a 200 on / means Apache is serving PHP correctly.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS -o /dev/null http://localhost/ || exit 1

ENTRYPOINT ["/usr/local/bin/web-entrypoint.sh"]
CMD ["apache2-foreground"]
