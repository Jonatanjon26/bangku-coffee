FROM php:8.4-cli

# System dependencies & PHP extensions required by Laravel 13 + Filament 5
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libicu-dev \
    libsqlite3-dev \
    libpq-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    curl \
    ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        intl \
        pdo_sqlite \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        gd \
        zip \
        bcmath \
        opcache \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js 22 LTS for Vite build
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY . .

RUN composer dump-autoload --optimize \
    && npm run build \
    && rm -rf node_modules \
    && chmod +x deploy/render-start.sh

EXPOSE 8000

CMD ["./deploy/render-start.sh"]
