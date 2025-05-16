<?php
// notification_service/src/consumer.php
require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use PhpAmqpLib\Connection\AMQPStreamConnection;

$log = new Logger('NotificationService');
$log->pushHandler(new StreamHandler(__DIR__.'/../logs/consumer.log', Logger::DEBUG));
$log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG)); // Для виводу в Docker логи

$rabbitHost = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
$rabbitPort = getenv('RABBITMQ_PORT') ?: 5672;
$rabbitUser = getenv('RABBITMQ_USER') ?: 'guest';
$rabbitPass = getenv('RABBITMQ_PASS') ?: 'guest';

$log->info('NotificationService starting...');

try {
    $connection = new AMQPStreamConnection($rabbitHost, $rabbitPort, $rabbitUser, $rabbitPass);
    $channel = $connection->channel();

    $queueName = 'user_created_queue';
    $channel->queue_declare($queueName, false, true, false, false); // durable

    $log->info("Waiting for messages on '{$queueName}'. To exit press CTRL+C");

    $callback = function ($msg) use ($log) {
        $log->info("Received message: " . $msg->body);
        $data = json_decode($msg->body, true);
        if ($data && isset($data['email'])) {
            // Симуляція відправки email
            $log->info("Simulating sending welcome email to: " . $data['email'] . " for user " . ($data['name'] ?? $data['user_id']));
        } else {
            $log->warning("Could not decode message or email missing: " . $msg->body);
        }
        $msg->ack(); // Підтвердження обробки повідомлення
    };

    // no_ack = false, щоб ми могли надсилати ack() вручну
    $channel->basic_consume($queueName, '', false, false, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();
} catch (\Exception $e) {
    $log->error('AMQP Consumer Error: ' . $e->getMessage());
    // Додайте логіку перепідключення, якщо потрібно
    sleep(5); // Затримка перед спробою перезапуску (якщо Docker налаштований на restart)
    exit(1); // Вихід з помилкою, щоб Docker міг перезапустити
}