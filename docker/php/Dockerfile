FROM php:8.4-fpm

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libzip-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    libpq-dev \
    unzip \
    git \
    curl \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar GD con JPEG
RUN docker-php-ext-configure gd --with-jpeg

# Configurar PDO PGSQL
RUN docker-php-ext-configure pdo_pgsql --with-pdo-pgsql=/usr

# Instalar extensiones PHP
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    zip \
    gd \
    curl \
    mbstring \
    opcache \
    pcntl \
    bcmath

# Instalar Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Configurar PHP
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

RUN mkdir -p storage/logs bootstrap/cache

RUN sed -i 's/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_children.*/pm.max_children=50/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.start_servers.*/pm.start_servers=5/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.min_spare_servers.*/pm.min_spare_servers=5/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_spare_servers.*/pm.max_spare_servers=35/' /usr/local/etc/php-fpm.d/www.conf

EXPOSE 9000

CMD ["php-fpm"]