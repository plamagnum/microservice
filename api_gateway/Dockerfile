# user_service/Dockerfile
FROM php:8.1-apache

# Встановлення системних залежностей для розширень PHP
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    librabbitmq-dev \
    libssh-dev \
  && docker-php-ext-install pdo pdo_mysql mysqli zip \
  && pecl install amqp \
  && docker-php-ext-enable amqp

# Налаштування Apache
COPY ./public /var/www/html/
RUN chown -R www-data:www-data /var/www/html && \
    a2enmod rewrite

# Встановлення Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копіювання файлів додатка
WORKDIR /var/www/app
COPY ./composer.json ./composer.lock* ./
RUN composer install --no-dev --optimize-autoloader

COPY ./src ./src
COPY ./public ./public
COPY ./logs /var/www/app/logs
RUN mkdir -p /var/www/app/logs && chown -R www-data:www-data /var/www/app/logs && chmod -R 775 /var/www/app/logs

# Для UserService/ProductService/ApiGateway, які є веб-сервісами, Apache запустить index.php з public
# Важливо: Apache DocumentRoot вказує на /var/www/html.
# Нам потрібно, щоб запити до /index.php (або /) оброблялись /var/www/html/index.php, який є копією public/index.php
# Або змінити DocumentRoot Apache на /var/www/app/public
# Простіший варіант - скопіювати вміст папки public нашого сервісу в /var/www/html
COPY ./public/ /var/www/html/
COPY ./src/ /var/www/app/src/

# Для коректної роботи з шляхами в public/index.php, який тепер в /var/www/html/
# відносні шляхи на кшталт __DIR__ . '/../vendor/autoload.php' можуть зламатися.
# Рішення:
# 1. WORKDIR /var/www/html (і тоді всі шляхи відносно цього)
# 2. Змінити DocumentRoot в Apache
# 3. Зробити public/index.php більш розумним щодо шляхів

# Давайте спробуємо змінити DocumentRoot.
# Створіть файл `apache-vhost.conf` в папці сервісу (user_service, product_service, api_gateway):
# <VirtualHost *:80>
#     DocumentRoot /var/www/app/public
#     <Directory /var/www/app/public>
#         AllowOverride All
#         Require all granted
#     </Directory>
#     ErrorLog ${APACHE_LOG_DIR}/error.log
#     CustomLog ${APACHE_LOG_DIR}/access.log combined
# </VirtualHost>
#
# І потім в Dockerfile:
# COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# Для простоти цього прикладу, я залишу копіювання в /var/www/html
# і припущу, що public/index.php зможе знайти vendor і src
# Наприклад, так:
# require_once __DIR__ . '/../app/vendor/autoload.php'; // Якщо index.php в /var/www/html, а app в /var/www/app
# АБО простіше:
# WORKDIR /var/www/app
# COPY ./public ./public
# COPY ./src ./src
# COPY ./composer.json ./composer.lock* ./
# RUN composer install --no-dev --optimize-autoloader
# COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf
# RUN a2ensite 000-default.conf && a2enmod rewrite
# Це вимагає файлу `apache-vhost.conf` у кожній папці сервісу, що вказує DocumentRoot на `/var/www/app/public`

# **Оновлений підхід для Apache (простіший для шляхів):**
WORKDIR /var/www/app
COPY ./composer.json ./composer.lock* ./
RUN composer install --no-interaction --optimize-autoloader --no-dev

COPY . .

# Налаштовуємо Apache, щоб він вказував на public директорію нашого додатку
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
RUN mkdir -p /var/www/app/logs && chown -R www-data:www-data /var/www/app/logs && chmod -R 777 /var/www/app/logs
# Важливо: права 777 для логів - це для простоти, в продакшені використовуйте більш безпечні налаштування.

EXPOSE 80
# Команда запуску Apache вже є в базовому образі php:8.1-apache