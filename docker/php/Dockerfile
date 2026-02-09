FROM php:8.4-fpm

# Arguments for user mapping (useful for local dev)
ARG USER_ID=1000
ARG GROUP_ID=1000

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    openscad \
    python3-pip \
    python3-venv \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure intl \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Python dependencies for tile generation and GTFS processing
RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir \
        h3 numpy pillow psycopg2-binary shapely pandas

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js (LTS)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Create system user to run Composer and Artisan commands
RUN groupadd --gid ${GROUP_ID} www \
    && useradd --uid ${USER_ID} --gid www --shell /bin/bash --create-home www

# Set working directory
ENV PATH="/opt/venv/bin:$PATH"

WORKDIR /var/www

# Copy PHP configuration
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Set ownership
RUN chown -R www:www /var/www

USER www

EXPOSE 9000

CMD ["php-fpm"]
