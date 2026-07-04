<?php

/**
 * Configuración de MySQL
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            // $host = getenv('DB_HOST') ?: 'srv1783.hstgr.io';
            // $port = getenv('DB_PORT') ?: '3306';
            // $name = getenv('DB_NAME') ?: 'u797242175_siente_bd';
            // $user = getenv('DB_USERNAME') ?: 'u797242175_danielf';
            // $pass = getenv('DB_PASSWORD') ?: 'IngSis9501';

            $host = getenv('DB_HOST') ?: 'localhost';
            $port = getenv('DB_PORT') ?: '3306';
            $name = getenv('DB_NAME') ?: 'siente-prueba';
            $user = getenv('DB_USERNAME') ?: 'root';
            $pass = getenv('DB_PASSWORD') ?: '';

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
                Timezone::apply(self::$instance);
            } catch (PDOException $e) {
                // http_response_code(500);
                // echo json_encode(['mensaje' => 'Error de conexión a la base de datos: ' . $e->getMessage()]);
                // exit;
                http_response_code(500);
                echo json_encode([
                    'mensaje' => 'Error de conexión a la base de datos',
                    'detalle' => $e->getMessage(),
                    'dsn' => "mysql:host={$host};port={$port};dbname={$name}",
                    'usuario' => $user,
                ]);
                exit;
            }
        }

        return self::$instance;
    }

    private function __clone() {}
    private function __construct() {}
}
