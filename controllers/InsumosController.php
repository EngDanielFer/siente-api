<?php

/**
 * InsumosController
 * 
 * GET /api/siente/insumos → getAll
 * GET /api/siente/insumos/{id} → getById
 * POST /api/siente/insumos → create
 * PUT /api/siente/insumos/{id} → update
 * DELETE /api/siente/insumos/{id} → delete
 */

declare(strict_types=1);

class InsumosController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function getAll(): void
    {
        $stmt = $this->db->query('SELECT * FROM insumos ORDER BY id');
        Response::success($stmt->fetchAll());
    }

    public function getById(int $id): void
    {
        $insumo = $this->findOrFail($id);
        Response::success($insumo);
    }

    public function create(): void
    {

        $body = Response::getBody();

        $estado = $body['estado_insumo'] ?? 'Disponible';
        if (empty($estado)) {
            $estado = 'Disponible';
        }

        $cantidadTotal    = $body['cantidad_insumo_total'] ?? null;
        $cantidadRestante = $body['cantidad_insumo_restante'] ?? $cantidadTotal;

        $stmt = $this->db->prepare(
            'INSERT INTO insumos
             (nombre_insumo, cantidad_insumo_total, cantidad_insumo_restante,
              proveedor_insumo, precio_insumo, precio_por_g_ml, estado_insumo)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $body['nombre_insumo'] ?? null,
            $cantidadTotal,
            $cantidadRestante,
            $body['proveedor_insumo'] ?? null,
            $body['precio_insumo'] ?? null,
            $body['precio_por_g_ml'] ?? null,
            $estado,
        ]);

        $id = (int)$this->db->lastInsertId();
        $insumo = $this->findOrFail($id);

        Response::created($insumo);
    }

    public function update(int $id): void
    {
        $insumo = $this->findOrFail($id);
        $body = Response::getBody();

        if (!empty($body['nombre_insumo'])) {
            $insumo['nombre_insumo'] = $body['nombre_insumo'];
        }
        if (isset($body['cantidad_insumo_total']) && $body['cantidad_insumo_total'] >= 0) {
            $insumo['cantidad_insumo_total'] = $body['cantidad_insumo_total'];
        }
        if (isset($body['cantidad_insumo_restante']) && $body['cantidad_insumo_restante'] >= 0) {
            $insumo['cantidad_insumo_restante'] = $insumo['cantidad_insumo_total'];
        }
        if (!empty($body['proveedor_insumo'])) {
            $insumo['proveedor_insumo'] = $body['proveedor_insumo'];
        }
        if (isset($body['precio_insumo']) && $body['precio_insumo'] > 0) {
            $insumo['precio_insumo'] = $body['precio_insumo'];
        }
        if (isset($body['precio_por_g_ml']) && $body['precio_por_g_ml'] > 0) {
            $insumo['precio_por_g_ml'] = $body['precio_por_g_ml'];
        }
        if (!empty($body['estado_insumo'])) {
            $insumo['estado_insumo'] = $body['estado_insumo'];
        }

        $stmt = $this->db->prepare(
            'UPDATE insumos SET
             nombre_insumo = ?, cantidad_insumo_total = ?, cantidad_insumo_restante = ?,
             proveedor_insumo = ?, precio_insumo = ?, precio_por_g_ml = ?, estado_insumo = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $insumo['nombre_insumo'],
            $insumo['cantidad_insumo_total'],
            $insumo['cantidad_insumo_restante'],
            $insumo['proveedor_insumo'],
            $insumo['precio_insumo'],
            $insumo['precio_por_g_ml'],
            $insumo['estado_insumo'],
            $id,
        ]);

        Response::success($this->findOrFail($id));
    }

    public function delete(int $id): void
    {
        $this->findOrFail($id);
 
        $stmt = $this->db->prepare('DELETE FROM insumos WHERE id = ?');
        $stmt->execute([$id]);
 
        Response::success(['mensaje' => 'Insumo eliminado correctamente']);
    }

    private function findOrFail(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM insumos WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::notFound("No se encontró el insumo con ID: {$id}");
        }

        return $row;
    }
}
