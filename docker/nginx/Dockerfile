# nginx with the site config baked in, so platforms that don't support
# bind-mounting files from the repo (e.g. Coolify) get the same config.
FROM nginx:1.27-alpine

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
