<?php

/**
 * GananciasController
 * 
 * GET /api/siente/ganancias → getAll
 */

declare(strict_types=1);

class GananciasController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function getAll(): void
    {
        $sql = '
            SELECT
                g.id_ganancia,
                g.id_producto_stock,
                g.precio_insumos_total,
                g.ganancia_total,
                g.precio_total,
                g.id_producto,
                p.nombre_producto
            FROM ganancias_productos g
            JOIN productos p ON p.id = g.id_producto
            ORDER BY g.id_ganancia DESC
        ';

        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        $result = array_map(fn($r) => [
            'id_ganancia' => (int)$r['id_ganancia'],
            'id_producto_stock' => (int)$r['id_producto_stock'],
            'precio_insumos_total' => (float)$r['precio_insumos_total'],
            'ganancia_total' => (float)$r['ganancia_total'],
            'precio_total' => (float)$r['precio_total'],
            'id_producto' => (int)$r['id_producto'],
            'nombre_producto' => $r['nombre_producto'],
        ], $rows);

        Response::success($result);
    }
}
