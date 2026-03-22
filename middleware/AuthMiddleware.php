<?php

/**
 * Middleware de JWT
 */

declare(strict_types=1);

class AuthMiddleware
{
    public static function verificar(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            Response::unauthorized('Token no proporcionado');
        }

        $token = substr($header, 7);

        $payload = self::validarToken($token);

        if ($payload === null) {
            Response::unauthorized('Token inválido o expirado');
        }

        $GLOBALS['auth_username'] = $payload['sub'] ?? '';
    }

    public static function generarToken(string $username): string
    {
        $secret = getenv('JWT_SECRET') ?: 'IsOk0AAufKKfb5ejRomJkb8aFc5lTWP3D99fYQrw+p6KcMRNy08y2UkAeZVGsRD3Jbp5qpSt3n1on8rbxRzj8g==';
        $expiration = (int)(getenv('JWT_EXPIRATION') ?: 86400);

        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

        $payload = self::base64UrlEncode(json_encode([
            'sub' => $username,
            'iat' => time(),
            'exp' => time() + $expiration,
        ]));

        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    public static function validarToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $secret = getenv('JWT_SECRET') ?: 'IsOk0AAufKKfb5ejRomJkb8aFc5lTWP3D99fYQrw+p6KcMRNy08y2UkAeZVGsRD3Jbp5qpSt3n1on8rbxRzj8g==';

        // Verificar firma
        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($payload), true);
        if (!$data) {
            return null;
        }

        if (isset($data['exp']) && $data['exp'] < time()) {
            return null;
        }
 
        return $data;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}
