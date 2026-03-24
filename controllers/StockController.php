<?php

/**
 * StockController
 * 
 * GET  /api/siente/stock → getAll
 * POST /api/siente/stock → create (usa SP p_insertar_stock_producto_ganancias)
 */

declare(strict_types=1);

class StockController
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
                ps.id_producto_stock,
                ps.cantidad_producto,
                ps.fecha_insercion,
                ps.id_producto,
                p.nombre_producto
            FROM productos_stock ps
            JOIN productos p ON p.id = ps.id_producto
            ORDER BY ps.fecha_insercion DESC
        ';

        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        Response::success($rows);
    }

    public function create(): void
    {
        $body = Response::getBody();

        $errores = [];
        $idProducto = $body['id_producto'] ?? null;
        $cantidadProducto = $body['cantidad_producto'] ?? null;

        if ($idProducto === null || (int)$idProducto < 1) {
            $errores['id_producto'] = 'El ID del producto debe ser mayor a 0';
        }
        if ($cantidadProducto === null || (int)$cantidadProducto < 1) {
            $errores['cantidad_producto'] = 'La cantidad debe ser mayor a 0';
        }

        if (!empty($errores)) {
            Response::json(['mensaje' => 'Errores de validación', 'errores' => $errores], 400);
        }

        try {
            $stmt = $this->db->prepare(
                'CALL p_insertar_stock_producto_ganancias(:prod_id, :prod_cantidad)'
            );
            $stmt->bindValue(':prod_id', (int)$idProducto, PDO::PARAM_INT);
            $stmt->bindValue(':prod_cantidad', (int)$cantidadProducto, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            $msg = $e->getMessage();

            $erroresMap = [
                'ID de producto inválido' => 'ID de producto inválido',
                'La cantidad debe ser mayor a 0' => 'La cantidad debe ser mayor a 0',
                'No existe el producto' => 'No existe el producto',
                'No hay insumos definidos' => 'No hay insumos definidos para este producto',
                'No hay suficiente insumo' => 'Stock de insumos insuficiente',
            ];

            foreach ($erroresMap as $key => $mensaje) {
                if (str_contains($msg, $key)) {
                    Response::error($mensaje);
                }
            }

            Response::serverError('Error al procesar el stock: ' . $msg);
        }

        Response::success([
            'success' => true,
            'message' => 'Se ha insertado el stock exitosamente',
        ]);
    }
}
