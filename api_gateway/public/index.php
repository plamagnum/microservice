<?php
// api_gateway/public/index.php
require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$log = new Logger('APIGateway');
$log->pushHandler(new StreamHandler(__DIR__.'/../logs/gateway.log', Logger::DEBUG));

$userServiceUrl = getenv('USER_SERVICE_URL') ?: 'http://user_service_app/index.php'; // 'user_service_app' - ім'я сервісу в Docker Compose
$productServiceUrl = getenv('PRODUCT_SERVICE_URL') ?: 'http://product_service_app/index.php';

$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
//echo $requestUri;
$requestBody = file_get_contents('php://input');
$headers = getallheaders(); // Отримуємо всі заголовки

// Дуже спрощена автентифікація/авторизація
// У реальному світі тут був би JWT або інший механізм
$simulatedUserRole = 'guest'; // За замовчуванням
$simulatedUserId = null;

if (isset($headers['Authorization'])) {
    // Припустимо, токен 'Bearer admin-token' дає роль admin, 'Bearer user-token' - user
    if ($headers['Authorization'] === 'Bearer admin-token') {
        $simulatedUserRole = 'admin';
        $simulatedUserId = '100'; // Припустимий ID адміна
        $log->info("Admin token detected. Role: admin");
    } elseif ($headers['Authorization'] === 'Bearer user-token') {
        $simulatedUserRole = 'user';
        $simulatedUserId = '101'; // Припустимий ID користувача
        $log->info("User token detected. Role: user");
    } else {
         $log->info("Unknown token in Authorization header: " . $headers['Authorization']);
    }
}


$log->info("Gateway received: {$requestMethod} {$requestUri}");
$log->debug("Checking against '/api/users'. strpos result: " . (strpos($requestUri, '/api/users') === 0 ? 'MATCH' : 'NO MATCH'));
$log->debug("Checking against '/api/products'. strpos result: " . (strpos($requestUri, '/api/products') === 0 ? 'MATCH' : 'NO MATCH'));

$targetUrl = null;
$pathInfo = ''; // Шлях, який буде переданий сервісу

// Маршрутизація
if (strpos($requestUri, '/api/users') === 0) {
    $pathInfo = str_replace('/api/users', '/users', $requestUri); // /api/users/1 -> /users/1
    //echo $requestUri;
    if (strpos($pathInfo, '?') !== false) { // видаляємо query string з pathInfo
        $pathInfo = substr($pathInfo, 0, strpos($pathInfo, '?'));
    }
    $targetUrl = $userServiceUrl . $pathInfo;
    //echo $userServiceUrl;
    //echo $pathInfo;
    if (!empty($_SERVER['QUERY_STRING'])) {
        $targetUrl .= '?' . $_SERVER['QUERY_STRING'];
    }
    $log->info("Routing to UserService: {$targetUrl}");
} elseif (strpos($requestUri, '/api/products') === 0) {
    $pathInfo = str_replace('/api/products', '/products', $requestUri);
    if (strpos($pathInfo, '?') !== false) {
        $pathInfo = substr($pathInfo, 0, strpos($pathInfo, '?'));
    }
    $targetUrl = $productServiceUrl . $pathInfo;
     if (!empty($_SERVER['QUERY_STRING'])) {
        $targetUrl .= '?' . $_SERVER['QUERY_STRING'];
    }
    $log->info("Routing to ProductService: {$targetUrl}");
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found in API Gateway']);
    //echo $requestUri; 
    $log->warning("Unknown route: {$requestUri}");
    exit;
}

// Формуємо заголовки для передачі
$forwardHeaders = [
    'Content-Type: ' . ($headers['Content-Type'] ?? 'application/json'),
    'Accept: ' . ($headers['Accept'] ?? 'application/json'),
    'X-User-Role: ' . $simulatedUserRole, // Додаємо роль користувача
];
if ($simulatedUserId) {
    $forwardHeaders[] = 'X-User-Id: ' . $simulatedUserId; // Додаємо ID користувача
}


$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestMethod);
curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
curl_setopt($ch, CURLOPT_HEADER, true); // Якщо потрібно бачити заголовки відповіді від сервісу

// Потрібно передати PATH_INFO, якщо ваш фреймворк/скрипт на бекенді його використовує
// Це може бути складніше з PHP вбудованим сервером, який використовується в Dockerfile.
// Для Apache/Nginx з mod_rewrite це працює краще.
// У нашому випадку user_service/product_service використовують REQUEST_URI, який буде правильним.

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502); // Bad Gateway
    echo json_encode(['error' => 'Failed to connect to backend service', 'details' => $curlError]);
    $log->error("cURL error to {$targetUrl}: {$curlError}");
    exit;
}

http_response_code($httpCode);
// Видалення заголовків Transfer-Encoding, які можуть спричинити проблеми
//header_remove("Transfer-Encoding");
echo $response;
$log->info("Response from {$targetUrl} [{$httpCode}]");