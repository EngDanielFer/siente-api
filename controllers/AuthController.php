<?php

/**
 * AuthController
 * 
 * POST /api/auth/login → login
 * POST /api/auth/registro → registro
 */

declare(strict_types=1);

class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function login(): void
    {
        $body = Response::getBody();

        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        $errores = [];
        if (empty($username)) {
            $errores['username'] = 'El usuario es requerido';
        }
        if (empty($password)) {
            $errores['password'] = 'La contraseña es requerida';
        }

        if (!empty($errores)) {
            Response::json(['mensaje' => 'Errores de validación', 'errores' => $errores], 400);
        }

        $stmt = $this->db->prepare(
            'SELECT id, username, password, nombre, rol, activo FROM usuarios_admin WHERE username = ?'
        );
        $stmt->execute([$username]);
        $usuario = $stmt->fetch();

        if (!$usuario || !$usuario['activo']) {
            if ($usuario && !$usuario['activo']) {
                Response::json(['mensaje' => 'Usuario inactivo'], 403);
            }
            Response::json(['mensaje' => 'Credenciales inválidas'], 401);
        }

        if (!password_verify($password, $usuario['password'])) {
            Response::json(['mensaje' => 'Credenciales inválidas'], 401);
        }

        $token = AuthMiddleware::generarToken($usuario['username']);

        Response::success([
            'token' => $token,
            'username' => $usuario['username'],
            'tipo' => 'Bearer',
        ]);
    }

    public function registro(): void
    {
        $body = Response::getBody();

        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $nombre   = trim($body['nombre'] ?? '');

        $errores = [];
        if (empty($username) || strlen($username) < 3 || strlen($username) > 50) {
            $errores['username'] = 'El usuario debe tener entre 3 y 50 caracteres';
        }
        if (empty($password) || strlen($password) < 6 || strlen($password) > 100) {
            $errores['password'] = 'La contraseña debe tener entre 6 y 100 caracteres';
        }
        if (empty($nombre) || strlen($nombre) < 2 || strlen($nombre) > 100) {
            $errores['nombre'] = 'El nombre debe tener entre 2 y 100 caracteres';
        }

        if (!empty($errores)) {
            Response::json(['mensaje' => 'Errores de validación', 'errores' => $errores], 400);
        }

        $stmt = $this->db->prepare('SELECT id FROM usuarios_admin WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            Response::conflict("El nombre de usuario '{$username}' ya está en uso");
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare(
            'INSERT INTO usuarios_admin (username, password, nombre, rol, activo, fecha_creacion)
             VALUES (?, ?, ?, ?, 1, NOW())'
        );
        $stmt->execute([$username, $hashedPassword, $nombre, 'ADMIN']);

        Response::created(['mensaje' => 'Usuario creado correctamente']);
    }
}
