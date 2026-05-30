<?php

/**
 * API de Siente
 */

declare(strict_types=1);

// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || str_starts_with($linea, '#')) continue;
        if (str_contains($linea, '=')) {
            [$clave, $valor] = explode('=', $linea, 2);
            putenv(trim($clave) . '=' . trim($valor));
        }
    }
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

$allowedOrigins = explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost:4200');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array(trim($origin), array_map('trim', $allowedOrigins))) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('BASE_PATH', str_replace('\\', '/', __DIR__));

require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/InsumosController.php';
require_once __DIR__ . '/controllers/ProductosController.php';
require_once __DIR__ . '/controllers/StockController.php';
require_once __DIR__ . '/controllers/FacturasController.php';
require_once __DIR__ . '/controllers/GananciasController.php';
require_once __DIR__ . '/controllers/CostosFijosController.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');

$segments = array_values(array_filter(explode('/', $uri)));

$base = $segments[0] ?? '';
$modulo = $segments[1] ?? '';
$recurso = $segments[2] ?? '';
$id = $segments[3] ?? null;
$sub = $segments[4] ?? null;

if ($base !== 'api') {
    Response::notFound('Ruta no encontrada');
}

if ($modulo === 'auth') {
    $ctrl = new AuthController();
    switch ($recurso) {
        case 'login':
            if ($method === 'POST') {
                $ctrl->login();
            } else {
                Response::methodNotAllowed();
            }
            break;
        case 'registro':
            if ($method === 'POST') {
                $ctrl->registro();
            } else {
                Response::methodNotAllowed();
            }
            break;
        default:
            Response::notFound('Ruta de auth no encontrada');
    }
    exit;
}

if ($modulo === 'siente') {
    AuthMiddleware::verificar();

    switch ($recurso) {
        case 'insumos':
            $ctrl = new InsumosController();
            if ($id === null) {
                if ($method === 'GET') {
                    $ctrl->getAll();
                } elseif ($method === 'POST') {
                    $ctrl->create();
                } else {
                    Response::methodNotAllowed();
                }
            } else {
                if ($method === 'GET') {
                    $ctrl->getById((int)$id);
                } elseif ($method === 'PUT') {
                    $ctrl->update((int)$id);
                } elseif ($method === 'DELETE') {
                    $ctrl->delete((int)$id);
                } else {
                    Response::methodNotAllowed();
                }
            }
            break;

        case 'productos':
            $ctrl = new ProductosController();
            if ($id === null) {
                if ($method === 'GET') {
                    isset($_GET['stock']) ? $ctrl->getByStock() : $ctrl->getAll();
                } elseif ($method === 'POST') {
                    $ctrl->create();
                } else {
                    Response::methodNotAllowed();
                }
            } elseif ($id === 'stock') {
                // GET /api/siente/productos/stock
                if ($method === 'GET') {
                    $ctrl->getByStock();
                } else {
                    Response::methodNotAllowed();
                }
            } elseif (is_numeric($id) && $sub === null) {
                // GET|PUT|DELETE /api/siente/productos/{id}
                if ($method === 'GET') {
                    $ctrl->getById((int)$id);
                } elseif ($method === 'PUT') {
                    $ctrl->update((int)$id);
                } elseif ($method === 'DELETE') {
                    $ctrl->delete((int)$id);
                } else {
                    Response::methodNotAllowed();
                }
            } elseif (is_numeric($id) && $sub === 'completo') {
                // GET /api/siente/productos/{id}/completo
                if ($method === 'GET') {
                    $ctrl->getCompleto((int)$id);
                } else {
                    Response::methodNotAllowed();
                }
            } elseif (is_numeric($id) && $sub === 'insumos') {
                // GET /api/siente/productos/{id}/insumos
                if ($method === 'GET') {
                    $ctrl->getInsumos((int)$id);
                } else {
                    Response::methodNotAllowed();
                }
            } elseif (is_numeric($id) && $sub === 'costos-fijos') {
                // GET /api/siente/productos/{id}/costos-fijos
                if ($method === 'GET') {
                    $cCtrl = new CostosFijosController();
                    $cCtrl->getByProducto((int)$id);
                } else {
                    Response::methodNotAllowed();
                }
            } else {
                Response::notFound('Sub-ruta de productos no encontrada');
            }
            break;

        case 'stock':
            $ctrl = new StockController();
            if ($id === null) {
                if ($method === 'GET') {
                    $ctrl->getAll();
                } elseif ($method === 'POST') {
                    $ctrl->create();
                } else {
                    Response::methodNotAllowed();
                }
            } else {
                Response::notFound('Ruta de stock no encontrada');
            }
            break;

        case 'facturas':
            $ctrl = new FacturasController();
            if ($id === null) {
                if ($method === 'GET') {
                    $ctrl->getAll();
                } elseif ($method === 'POST') {
                    $ctrl->create();
                } else {
                    Response::methodNotAllowed();
                }
            } elseif ($id === 'email') {
                // GET /api/siente/facturas/email/{email}
                $email = $sub ?? '';
                if ($method === 'GET') {
                    $ctrl->getByEmail(urldecode($email));
                } else {
                    Response::methodNotAllowed();
                }
            } elseif (is_numeric($id)) {
                if ($method === 'GET') {
                    $ctrl->getById((int)$id);
                } elseif ($method === 'DELETE') {
                    $ctrl->delete((int)$id);
                } else {
                    Response::methodNotAllowed();
                }
            } else {
                Response::notFound('Ruta de facturas no encontrada');
            }
            break;

        case 'ganancias':
            $ctrl = new GananciasController();
            if ($id === null && $method === 'GET') {
                $ctrl->getAll();
            } else {
                Response::methodNotAllowed();
            }
            break;

        default:
            Response::notFound('Módulo no encontrado');
    }
    exit;
}

Response::notFound('Ruta no encontrada');
