FROM php:8.3-fpm-alpine

WORKDIR /workspace

RUN apk add --no-cache \
    git curl zip unzip bash \
    libpng-dev libjpeg-turbo-dev libwebp-dev \
    oniguruma-dev libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring xml bcmath gd pcntl

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Node (pour Vite)
RUN apk add --no-cache nodejs npm

COPY . .

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
