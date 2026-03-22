<?php

/**
 * Helper de PHP
 */

declare(strict_types=1);

class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data, int $status = 200): void
    {
        self::json($data, $status);
    }

    public static function created(mixed $data): void
    {
        self::json($data, 201);
    }

    public static function error(string $mensaje, int $status = 400): void
    {
        self::json(['mensaje' => $mensaje], $status);
    }

    public static function notFound(string $mensaje = 'Recurso no encontrado'): void
    {
        self::json(['mensaje' => $mensaje], 404);
    }

    public static function unauthorized(string $mensaje = 'No autorizado'): void
    {
        self::json(['mensaje' => $mensaje], 401);
    }

    public static function forbidden(string $mensaje = 'Acceso prohibido'): void
    {
        self::json(['mensaje' => $mensaje], 403);
    }

    public static function conflict(string $mensaje): void
    {
        self::json(['mensaje' => $mensaje], 409);
    }

    public static function serverError(string $mensaje = 'Error interno del servidor'): void
    {
        self::json(['mensaje' => $mensaje], 500);
    }

    public static function methodNotAllowed(): void
    {
        self::json(['mensaje' => 'Método no permitido'], 405);
    }

    public static function getBody(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::error('JSON inválido en el cuerpo de la petición');
        }

        return $data ?? [];
    }
}
