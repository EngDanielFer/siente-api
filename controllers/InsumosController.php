<?php

/**
 * InsumosController
 * 
 * GET /api/siente/insumos → getAll
 * GET /api/siente/insumos/bajo-stock → getLowStock
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

    public function getLowStock(): void
    {
        $stmt = $this->db->query(
            "SELECT * FROM insumos
             WHERE (cantidad_minima IS NOT NULL AND cantidad_insumo_restante < cantidad_minima)
                OR estado_insumo = 'Agregar más insumos'
             ORDER BY nombre_insumo"
        );
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

        $cantidadTotal = $body['cantidad_insumo_total'] ?? null;
        $cantidadRestante = $body['cantidad_insumo_restante'] ?? $cantidadTotal;
        $precioInsumo = $body['precio_insumo'] ?? null;

        $precioPorGMl = $body['precio_por_g_ml'] ?? null;
        if ($precioPorGMl === null && $precioInsumo !== null && $cantidadTotal > 0) {
            $precioPorGMl = $precioInsumo / $cantidadTotal;
        }

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
            $precioInsumo,
            $precioPorGMl,
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
        if (!empty($body['proveedor_insumo'])) {
            $insumo['proveedor_insumo'] = $body['proveedor_insumo'];
        }
        if (!empty($body['estado_insumo'])) {
            $insumo['estado_insumo'] = $body['estado_insumo'];
        }

        $cantidadCambio = isset($body['cantidad_insumo_total'])
            && (float)$body['cantidad_insumo_total'] >= 0;

        if ($cantidadCambio) {
            $insumo['cantidad_insumo_total'] = (float)$body['cantidad_insumo_total'];
        }

        if (isset($body['cantidad_insumo_restante']) && (float)$body['cantidad_insumo_restante'] >= 0) {
            $insumo['cantidad_insumo_restante'] = (float)$insumo['cantidad_insumo_total'];
        }

        $precioCambio = isset($body['precio_insumo']) && (float)$body['precio_insumo'] > 0;

        $precioPorGMlCambio = isset($body['precio_por_g_ml']) && $body['precio_por_g_ml'] > 0;

        if ($precioCambio && $precioPorGMlCambio) {
            $insumo['precio_insumo'] = (float)$body['precio_insumo'];
            $insumo['precio_por_g_ml'] = (float)$body['precio_por_g_ml'];
        } elseif ($precioCambio) {
            $insumo['precio_insumo'] = (float)$body['precio_insumo'];
            $cantidad = (float)$insumo['cantidad_insumo_total'];
            if ($cantidad > 0) {
                $insumo['precio_por_g_ml'] = $insumo['precio_insumo'] / $cantidad;
            }
        } elseif ($cantidadCambio) {
            $insumo['precio_insumo'] = (float)$insumo['precio_por_g_ml'] = (float)$insumo['cantidad_insumo_total'];
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
