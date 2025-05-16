# notification_service/Dockerfile
FROM php:8.1-cli-alpine

# Встановлення системних залежностей, необхідних для розширень PHP
# Для Alpine використовуємо apk add
RUN apk add --no-cache \
    rabbitmq-c-dev \
    make \
    autoconf \
    g++ \
    libzip-dev \
    zip \
    unzip

# Встановлення розширень PHP: amqp для RabbitMQ, zip
RUN docker-php-ext-install zip sockets\
  && pecl install amqp \
  && docker-php-ext-enable amqp

# Встановлення Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Встановлення робочої директорії
WORKDIR /app

# Копіювання файлів Composer та встановлення залежностей
COPY ./composer.json ./composer.lock* ./
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Копіювання решти коду додатка
COPY . .

# Створення та надання прав на папку логів
RUN mkdir -p /app/logs && \
    # В Alpine образі PHP зазвичай запускається від root,
    # але якщо ви змінюєте користувача, переконайтеся, що він має права на запис.
    # Для простоти, якщо запускається від root, права вже будуть.
    # Якщо потрібен конкретний користувач, додайте `USER phpuser` і `chown phpuser:phpuser /app/logs`
    chmod -R 777 /app/logs # 777 для простоти в прикладі, налаштуйте безпечніше для продакшену

# Команда для запуску консюмера
CMD ["php", "src/consumer.php"]