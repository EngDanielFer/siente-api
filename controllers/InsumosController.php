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
             WHERE cantidad_minima IS NOT NULL AND cantidad_insumo_restante <= cantidad_minima
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
        $cantidadMinima = isset($body['cantidad_minima']) && $body['cantidad_minima'] !== ''
            ? (float)$body['cantidad_minima']
            : null;

        $precioPorGMl = $body['precio_por_g_ml'] ?? null;
        if ($precioPorGMl === null && $precioInsumo !== null && $cantidadTotal > 0) {
            $precioPorGMl = $precioInsumo / $cantidadTotal;
        }

        if ($cantidadMinima !== null && (float)$cantidadRestante <= $cantidadMinima) {
            $estado = 'Agregar más insumos';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO insumos
             (nombre_insumo, cantidad_insumo_total, cantidad_insumo_restante,
              proveedor_insumo, precio_insumo, precio_por_g_ml, estado_insumo,
              cantidad_minima, fecha_actualizacion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $body['nombre_insumo'] ?? null,
            $cantidadTotal,
            $cantidadRestante,
            $body['proveedor_insumo'] ?? null,
            $precioInsumo,
            $precioPorGMl,
            $estado,
            $cantidadMinima,
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

        if (array_key_exists('cantidad_minima', $body)) {
            $insumo['cantidad_minima'] = ($body['cantidad_minima'] !== null && $body['cantidad_minima'] !== '')
                ? (float)$body['cantidad_minima']
                : null;
        }

        $cantidadCambio = isset($body['cantidad_insumo_total'])
            && is_numeric($body['cantidad_insumo_total'])
            && (float)$body['cantidad_insumo_total'] >= 0;

        if ($cantidadCambio) {
            $nuevoTotal = (float)$body['cantidad_insumo_total'];

            $insumo['cantidad_insumo_total'] = $nuevoTotal;
            $insumo['cantidad_insumo_restante'] = $nuevoTotal;
        } elseif (isset($body['cantidad_insumo_restante']) && (float)$body['cantidad_insumo_restante'] >= 0) {
            $insumo['cantidad_insumo_restante'] = (float)$body['cantidad_insumo_restante'];
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
            $insumo['precio_insumo'] = (float)$insumo['precio_por_g_ml'] * (float)$insumo['cantidad_insumo_total'];
        }

        $cantidadMinima = $insumo['cantidad_minima'] ?? null;
        $cantidadRestante = (float)($insumo['cantidad_insumo_restante'] ?? 0);

        if ($cantidadMinima !== null && $cantidadRestante <= (float)$cantidadMinima) {
            $insumo['estado_insumo'] = 'Agregar más insumos';
        } elseif ($insumo['estado_insumo'] === 'Agregar más insumos') {
            $insumo['estado_insumo'] = 'Disponible';
        }
        

        $stmt = $this->db->prepare(
            'UPDATE insumos SET
             nombre_insumo = ?, cantidad_insumo_total = ?, cantidad_insumo_restante = ?,
             proveedor_insumo = ?, precio_insumo = ?, precio_por_g_ml = ?, estado_insumo = ?,
             cantidad_minima = ?, fecha_actualizacion = NOW()
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
            $insumo['cantidad_minima'],
            $id,
        ]);

        Response::success($this->findOrFail($id));
    }

    public function delete(int $id): void
    {
        $this->findOrFail($id);

        try {
            $this->db->beginTransaction();
 
            // 1. Eliminar referencias en insumos_por_producto
            $stmt = $this->db->prepare('DELETE FROM insumos_por_producto WHERE id_insumo = ?');
            $stmt->execute([$id]);
 
            // 2. Eliminar el insumo
            $stmt = $this->db->prepare('DELETE FROM insumos WHERE id = ?');
            $stmt->execute([$id]);
 
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            Response::serverError('Error al eliminar el insumo: ' . $e->getMessage());
        }

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
