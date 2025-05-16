# product_service/Dockerfile
FROM php:8.1-apache

# Встановлення системних залежностей для розширень PHP та інструментів
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
  && docker-php-ext-install pdo pdo_mysql mysqli zip

# Встановлення Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Встановлення робочої директорії
WORKDIR /var/www/app

# Копіювання файлів Composer та встановлення залежностей
COPY ./composer.json ./composer.lock* ./
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Копіювання решти коду додатка
COPY . .

# Налаштування Apache, щоб він вказував на public директорію нашого додатка
RUN echo "<VirtualHost *:80>\n\
    DocumentRoot /var/www/app/public\n\
    <Directory /var/www/app/public>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog \${APACHE_LOG_DIR}/error.log\n\
    CustomLog \${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>" > /etc/apache2/sites-available/000-default.conf

RUN a2enmod rewrite

# Створення та надання прав на папку логів
RUN mkdir -p /var/www/app/logs && \
    chown -R www-data:www-data /var/www/app/logs && \
    chmod -R 775 /var/www/app/logs
# Права 777 не рекомендуються для продакшену; 775 з правильною групою www-data є кращим варіантом.

EXPOSE 80
# Команда запуску Apache вже є в базовому образі php:8.1-apache