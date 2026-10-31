#!/bin/sh
set -eu

# Local HTTPS certificate: generated at container startup, never copied into the image.
if [ "${1:-}" = "apache2-foreground" ]; then
    mkdir -p /tmp/organilog-tls
    if [ ! -f /tmp/organilog-tls/cert.pem ] || [ ! -f /tmp/organilog-tls/key.pem ]; then
        openssl req -x509 -newkey rsa:2048 -noenc \
            -keyout /tmp/organilog-tls/key.pem -out /tmp/organilog-tls/cert.pem \
            -days 30 -subj '/CN=localhost' \
            -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' 2>/dev/null
        chmod 0600 /tmp/organilog-tls/key.pem
    fi
fi

exec docker-php-entrypoint "$@"
