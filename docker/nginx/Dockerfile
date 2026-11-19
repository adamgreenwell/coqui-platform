# nginx with the site config baked in, so platforms that don't support
# bind-mounting files from the repo (e.g. Coolify) get the same config.
FROM nginx:1.27-alpine

# FastCGI upstream for PHP. default.conf (shared with local development, where
# the PHP-FPM service is `app`) uses app:9000; the Coolify stack names its
# PHP-FPM service `php` so the domain-facing `app` service can be this image.
ARG PHP_UPSTREAM=app:9000

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

RUN sed -i "s|set \$php_upstream app:9000;|set \$php_upstream ${PHP_UPSTREAM};|" /etc/nginx/conf.d/default.conf \
    && grep -q "set \$php_upstream ${PHP_UPSTREAM};" /etc/nginx/conf.d/default.conf
