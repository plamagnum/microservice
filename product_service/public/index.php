<?php
// product_service/public/index.php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// Завантаження змінних середовища (якщо .env файл існує)
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Налаштування логера
$logFilePath = __DIR__.'/../logs/service.log';
$log = new Logger('ProductService');
$handler = new StreamHandler($logFilePath, Logger::DEBUG);
// Формат логування: [YYYY-MM-DD HH:MM:SS] channel.LEVEL: message context extra
$formatter = new LineFormatter(null, null, true, true);
$handler->setFormatter($formatter);
$log->pushHandler($handler);


// Встановлення заголовків за замовчуванням
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *"); // Для простоти; налаштуйте для продакшену
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Role, X-User-Id");

// Обробка OPTIONS запитів для CORS Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

// Підключення до бази даних
$dbHost = $_ENV['DB_HOST'] ?? 'mysql_db';
$dbName = $_ENV['DB_DATABASE'] ?? 'microservices_example';
$dbUser = $_ENV['DB_USER'] ?? 'appuser';
$dbPass = $_ENV['DB_PASSWORD'] ?? 'apppassword';
$dbCharset = 'utf8mb4';

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (\PDOException $e) {
    $log->critical('Database connection failed', ['exception' => $e->getMessage()]);
    http_response_code(503); // Service Unavailable
    echo json_encode(['error' => 'Database service is temporarily unavailable. Please try again later.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_SERVER['PATH_INFO'] ?? '/';

// Отримання ролі користувача з заголовка (встановлюється API Gateway)
$userRole = $_SERVER['HTTP_X_USER_ROLE'] ?? 'guest';
// $userId = $_SERVER['HTTP_X_USER_ID'] ?? null; // Може знадобитися для більш детальної логіки

$log->info("Request received", [
    'method' => $method,
    'path' => $pathInfo,
    'role' => $userRole,
    // 'userId' => $userId
]);


try {
    // Проста маршрутизація
    if ($pathInfo === '/products' && $method === 'GET') {
        // Отримати всі продукти (доступно для всіх)
        $stmt = $pdo->query("SELECT id, name, description, price, stock_quantity, created_at FROM products ORDER BY id DESC");
        $products = $stmt->fetchAll();
        http_response_code(200);
        echo json_encode($products);
        $log->info("Listed all products", ['count' => count($products)]);

    } elseif (preg_match('/\/products\/(\d+)/', $pathInfo, $matches) && $method === 'GET') {
        // Отримати продукт за ID (доступно для всіх)
        $productId = (int)$matches[1];
        $stmt = $pdo->prepare("SELECT id, name, description, price, stock_quantity, created_at FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if ($product) {
            http_response_code(200);
            echo json_encode($product);
            $log->info("Fetched product by ID", ['productId' => $productId]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            $log->warning("Product not found by ID", ['productId' => $productId]);
        }

    } elseif ($pathInfo === '/products' && $method === 'POST') {
        // Створити новий продукт (тільки адміністратор)
        if ($userRole !== 'admin') {
            http_response_code(403); // Forbidden
            echo json_encode(['error' => 'Forbidden: Admin access required to create products.']);
            $log->warning("Forbidden attempt to create product", ['role' => $userRole]);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON payload']);
            $log->error("Invalid JSON in POST /products", ['json_error' => json_last_error_msg()]);
            exit;
        }

        if (empty($data['name']) || !isset($data['price']) || !isset($data['stock_quantity'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Missing required fields: name, price, stock_quantity.']);
            $log->warning("Missing fields for creating product", ['data' => $data]);
            exit;
        }

        $sql = "INSERT INTO products (name, description, price, stock_quantity) VALUES (:name, :description, :price, :stock_quantity)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':price' => (float)$data['price'],
            ':stock_quantity' => (int)$data['stock_quantity'],
        ]);
        $newProductId = $pdo->lastInsertId();

        http_response_code(201); // Created
        echo json_encode([
            'message' => 'Product created successfully.',
            'id' => (int)$newProductId,
            'name' => $data['name'],
            'price' => (float)$data['price']
        ]);
        $log->info("Product created successfully", ['productId' => $newProductId, 'name' => $data['name']]);

    } elseif (preg_match('/\/products\/(\d+)/', $pathInfo, $matches) && $method === 'PUT') {
        // Оновити продукт (тільки адміністратор)
        if ($userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: Admin access required to update products.']);
            $log->warning("Forbidden attempt to update product", ['role' => $userRole, 'productId' => $matches[1]]);
            exit;
        }
        $productId = (int)$matches[1];
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON payload']);
            $log->error("Invalid JSON in PUT /products/{$productId}", ['json_error' => json_last_error_msg()]);
            exit;
        }

        // Перевірка, чи продукт існує
        $checkStmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $checkStmt->execute([$productId]);
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found for update.']);
            $log->warning("Product not found for PUT", ['productId' => $productId]);
            exit;
        }

        // Тут можна додати більш детальну валідацію полів
        if (empty($data['name']) || !isset($data['price']) || !isset($data['stock_quantity'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields for update: name, price, stock_quantity.']);
            $log->warning("Missing fields for updating product", ['productId' => $productId, 'data' => $data]);
            exit;
        }

        $sql = "UPDATE products SET name = :name, description = :description, price = :price, stock_quantity = :stock_quantity WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':price' => (float)$data['price'],
            ':stock_quantity' => (int)$data['stock_quantity'],
            ':id' => $productId,
        ]);

        if ($stmt->rowCount() > 0) {
            http_response_code(200); // OK
            echo json_encode(['message' => 'Product updated successfully.', 'id' => $productId]);
            $log->info("Product updated successfully", ['productId' => $productId]);
        } else {
            // Можливо, дані не змінилися, або помилка (хоча помилка мала б викликати виняток)
            http_response_code(200); // Або 304 Not Modified, якщо ви реалізуєте перевірку на зміни
            echo json_encode(['message' => 'Product data was not changed or update failed silently.', 'id' => $productId]);
            $log->info("Product update resulted in no changed rows", ['productId' => $productId]);
        }


    } elseif (preg_match('/\/products\/(\d+)/', $pathInfo, $matches) && $method === 'DELETE') {
        // Видалити продукт (тільки адміністратор)
        if ($userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: Admin access required to delete products.']);
            $log->warning("Forbidden attempt to delete product", ['role' => $userRole, 'productId' => $matches[1]]);
            exit;
        }
        $productId = (int)$matches[1];

        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$productId]);

        if ($stmt->rowCount() > 0) {
            http_response_code(200); // Або 204 No Content
            echo json_encode(['message' => 'Product deleted successfully.']);
            $log->info("Product deleted successfully", ['productId' => $productId]);
        } else {
            http_response_code(404); // Not Found
            echo json_encode(['error' => 'Product not found or already deleted.']);
            $log->warning("Product not found for DELETE", ['productId' => $productId]);
        }

    } else {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Endpoint not found.']);
        $log->notice("Endpoint not found", ['method' => $method, 'path' => $pathInfo]);
    }

} catch (\PDOException $e) {
    $log->error('Database query failed', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An internal error occurred processing your request.']);
} catch (\Throwable $e) {
    $log->error('An unexpected error occurred', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
}