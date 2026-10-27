# DormLink API image for Render (or any Docker host).
# Base: PHP 8.4 + PHP-FPM + Nginx, listening on port 8080.
# On start, the image's "autorun" runs migrate --force, storage:link and the
# config/route/view caches (AUTORUN_* variables, see render.yaml).
FROM serversideup/php:8.4-fpm-nginx

USER root

# GD with WebP for photo resizing (intervention/image); pdo_pgsql is built in.
RUN install-php-extensions gd

# Makes sure the uploads folders exist on the mounted disk before autorun.
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/

# Queue worker as a third s6 service beside Nginx and PHP-FPM; it starts
# after migrations (entrypoint.d) and PHP-FPM, and s6 restarts it if it stops.
COPY --chmod=755 docker/s6-rc.d/laravel-queue/ /etc/s6-overlay/s6-rc.d/laravel-queue/
RUN touch /etc/s6-overlay/user-bundles.d/user/contents.d/laravel-queue

USER www-data

# Dependencies first, so code changes don't reinstall them.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi
