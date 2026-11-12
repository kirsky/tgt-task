<?php

/**
 * Front controller of the HR panel (novichok.indigit.info): starts the session,
 * hands the request to the router and sends the response.
 */

declare(strict_types=1);

use App\Container;
use App\Web\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_name('novichok');
session_start();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $response = (new Router(Container::boot()))->dispatch($method, $path, $_POST, $_SESSION);
} catch (\Throwable $e) {
    $dir = Container::ROOT . '/var/log';
    is_dir($dir) || mkdir($dir, 0775, true);
    file_put_contents("$dir/web.log", date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
    $response = new App\Web\Response(500, 'Что-то пошло не так. Ошибка записана в журнал.');
}
$response->send();
