FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libcurl4-openssl-dev libonig-dev openssl \
    && docker-php-ext-install -j"$(nproc)" curl mbstring \
    && a2enmod rewrite headers ssl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/organilog
COPY src/ src/
COPY public/ public/
COPY bin/ bin/
COPY data/ data/
COPY tests/ tests/
COPY config.example.php .htaccess LICENSE NOTICE ./
COPY deploy/docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/organilog-entrypoint

RUN sed -i 's/\r$//' /usr/local/bin/organilog-entrypoint \
    && chmod +x /usr/local/bin/organilog-entrypoint \
    && mkdir -p var/usage var/logs \
    && chown -R www-data:www-data var \
    && chmod 0750 var var/usage var/logs

EXPOSE 443
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD curl --insecure --fail --silent https://localhost/ > /dev/null || exit 1

ENTRYPOINT ["organilog-entrypoint"]
CMD ["apache2-foreground"]
