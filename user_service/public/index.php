<?php
// user_service/public/index.php
require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Завантаження .env (якщо використовується)
// $dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/../');
// $dotenv->load();

// Логер
$log = new Logger('UserService');
$log->pushHandler(new StreamHandler(__DIR__.'/../logs/service.log', Logger::DEBUG));

// Підключення до RabbitMQ
$rabbitHost = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
$rabbitPort = getenv('RABBITMQ_PORT') ?: 5672;
$rabbitUser = getenv('RABBITMQ_USER') ?: 'guest';
$rabbitPass = getenv('RABBITMQ_PASS') ?: 'guest';

try {
    $rabbitConnection = new AMQPStreamConnection($rabbitHost, $rabbitPort, $rabbitUser, $rabbitPass);
    $rabbitChannel = $rabbitConnection->channel();
    $rabbitChannel->queue_declare('user_created_queue', false, true, false, false); // true для durable
    $log->info('RabbitMQ connected');
} catch (\Exception $e) {
    $log->error('RabbitMQ connection failed: ' . $e->getMessage());
    $rabbitConnection = null; // Важливо обробляти помилки підключення
}


// Підключення до БД (спрощено)
$dbHost = getenv('DB_HOST') ?: 'mysql';
$dbName = getenv('DB_DATABASE') ?: 'microservices_example';
$dbUser = getenv('DB_USER') ?: 'root'; // Краще використовувати окремого користувача
$dbPass = getenv('DB_PASSWORD') ?: 'password'; // Змініть на ваш пароль з docker-compose

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $log->info('Database connected');
} catch (PDOException $e) {
    $log->error('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error']);
    exit;
}

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];
//$path = $_SERVER['PATH_INFO'] ?? '/';

$path = $_SERVER['REQUEST_URI'];
if (false !== $pos = strpos($path, '?')) {
    $path = substr($path, 0, $pos);
}

// Проста авторизація на основі заголовка (передається з API Gateway)
$userRole = $_SERVER['HTTP_X_USER_ROLE'] ?? 'guest';
$userId = $_SERVER['HTTP_X_USER_ID'] ?? null;

$log->info("Request: {$method} {$path}, Role: {$userRole}, UserID: {$userId}");

// Маршрутизація
if ($method === 'POST' && ($path === '/users' || $path === '/users/')) {
    // Створення користувача
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['name']) || !isset($data['email']) || !isset($data['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: name, email, password']);
        exit;
    }
    $role = $data['role'] ?? 'user'; // Адмін може встановити роль, інакше 'user'
    if ($userRole !== 'admin' && isset($data['role']) && $data['role'] !== 'user') {
        http_response_code(403);
        $log->warning("Forbidden: User {$userId} tried to set role to {$data['role']}");
        echo json_encode(['error' => 'Forbidden to set custom role']);
        exit;
    }

    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['name'], $data['email'], $hashedPassword, $role]);
        $newUserId = $pdo->lastInsertId();
        $log->info("User created with ID: {$newUserId}");

        // Публікація повідомлення в RabbitMQ
        if ($rabbitConnection) {
            $msgBody = json_encode(['user_id' => $newUserId, 'email' => $data['email'], 'name' => $data['name']]);
            $message = new AMQPMessage($msgBody, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
            $rabbitChannel->basic_publish($message, '', 'user_created_queue');
            $log->info("Message published to user_created_queue for user ID {$newUserId}");
        }

        http_response_code(201);
        echo json_encode(['id' => $newUserId, 'name' => $data['name'], 'email' => $data['email'], 'role' => $role]);
    } catch (PDOException $e) {
        http_response_code(500);
        $log->error("Error creating user: " . $e->getMessage());
        echo json_encode(['error' => 'Error creating user', 'details' => $e->getMessage()]);
    }
} elseif ($method === 'GET' && ($path === '/users' || $path === '/users/')) {
    if ($userRole !== 'admin') {
        http_response_code(403);
        $log->warning("Forbidden: User {$userId} with role {$userRole} tried to list all users.");
        echo json_encode(['error' => 'Forbidden: Admin access required']);
        exit;
    }
    $stmt = $pdo->query("SELECT id, name, email, role, created_at FROM users");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    $log->info("Admin listed all users.");

} elseif ($method === 'GET' && preg_match('/\/users\/(\d+)/', $path, $matches)) {
    $requestedId = (int)$matches[1];
    // Адмін може бачити будь-кого, користувач - тільки себе
    if ($userRole !== 'admin' && $userId != $requestedId) {
        http_response_code(403);
        $log->warning("Forbidden: User {$userId} tried to access user {$requestedId}");
        echo json_encode(['error' => 'Forbidden to access this user']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, name, email, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$requestedId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo json_encode($user);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
    }
    $log->info("User {$userId} accessed user data for ID {$requestedId}");
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found']);
}

if ($rabbitConnection) {
    $rabbitChannel->close();
    $rabbitConnection->close();
}