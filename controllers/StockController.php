<?php

/**
 * StockController
 * 
 * GET  /api/siente/stock → getAll
 * GET  /api/siente/stock/bajo-stock   → getLowStock
 * POST /api/siente/stock → create (usa SP p_insertar_stock_producto_ganancias)
 * PUT    /api/siente/stock/{id}      → update   (ajusta lote y revierte/descuenta insumos)
 * DELETE /api/siente/stock/{id}      → delete   (solo lotes con cantidad_producto = 0)
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
                p.nombre_producto,
                p.stock_minimo
            FROM productos_stock ps
            JOIN productos p ON p.id = ps.id_producto
            ORDER BY ps.fecha_insercion DESC
        ';

        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        Response::success($rows);
    }

    public function getLowStock(): void
    {
        $sql = '
            SELECT
                p.id,
                p.nombre_producto,
                p.stock_producto,
                p.stock_minimo
            FROM productos p
            WHERE p.stock_minimo IS NOT NULL
              AND p.stock_producto < p.stock_minimo
            ORDER BY p.nombre_producto
        ';

        $stmt = $this->db->query($sql);
        Response::success($stmt->fetchAll());
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

    public function update(int $idProducto): void
    {
        $body = Response::getBody();

        $cantidadProducto = $body['cantidad_producto'] ?? null;
        $diferencia = $body['diferencia'] ?? null;

        if ($cantidadProducto === null || !is_numeric($cantidadProducto) || (int)$cantidadProducto < 0) {
            Response::error('El campo cantidad_producto debe ser un número mayor o igual a 0');
        }
        if ($diferencia === null || !is_numeric($diferencia)) {
            Response::error('El campo diferencia es requerido y debe ser un número');
        }

        $cantidadProducto = (int)$cantidadProducto;
        $diferencia = (int)$diferencia;

        if ($diferencia === 0) {
            Response::success(['mensaje' => 'Sin cambios en el stock']);
        }

        $stmtProd = $this->db->prepare('SELECT id, stock_producto FROM productos WHERE id = ?');
        $stmtProd->execute([$idProducto]);
        $producto = $stmtProd->fetch();

        if (!$producto) {
            Response::notFound("No se encontró el producto con ID: {$idProducto}");
        }

        $stmtInsumos = $this->db->prepare(
            'SELECT ip.id_insumo, ip.cantidad_insumo AS cantidad_por_unidad,
                    i.cantidad_insumo_restante, i.nombre_insumo, i.cantidad_minima
             FROM insumos_por_producto ip
             JOIN insumos i ON i.id = ip.id_insumo
             WHERE ip.id_producto = ?'
        );
        $stmtInsumos->execute([$idProducto]);
        $insumos = $stmtInsumos->fetchAll();

        if ($diferencia > 0 && !empty($insumos)) {
            foreach ($insumos as $insumo) {
                $cantidadNecesaria = (float)$insumo['cantidad_por_unidad'] * $diferencia;
                $cantidadDisponible = (float)$insumo['cantidad_insumo_restante'];

                if ($cantidadDisponible < $cantidadNecesaria) {
                    Response::json([
                        'mensaje' => "Insumos insuficientes para realizar el ajuste",
                        'detalle' => "El insumo '{$insumo['nombre_insumo']}' requiere {$cantidadNecesaria} "
                            . "pero solo hay {$cantidadDisponible} disponibles"
                    ], 422);
                }
            }
        }

        $stmtLote = $this->db->prepare(
            'SELECT id_producto_stock, cantidad_producto
             FROM productos_stock
             WHERE id_producto = ?
             ORDER BY
                 CASE WHEN cantidad_producto = 0 THEN 0 ELSE 1 END ASC,
                 fecha_insercion DESC
             LIMIT 1'
        );
        $stmtLote->execute([$idProducto]);
        $lote = $stmtLote->fetch();

        try {
            $this->db->beginTransaction();

            if ($diferencia > 0 && !empty($insumos)) {
                $stmtActInsumo = $this->db->prepare(
                    'UPDATE insumos
                     SET cantidad_insumo_restante = cantidad_insumo_restante - (?),
                        fecha_actualizacion = NOW(),
                         estado_insumo = CASE
                             WHEN cantidad_minima IS NOT NULL
                                  AND (cantidad_insumo_restante - ?) <= cantidad_minima
                                  THEN \'Agregar más insumos\'
                             WHEN cantidad_minima IS NULL
                                  AND (cantidad_insumo_restante - ?) <= (cantidad_insumo_total * 0.10)
                                  THEN \'Agregar más insumos\'
                             ELSE \'Disponible\'
                         END
                     WHERE id = ?'
                );

                foreach ($insumos as $insumo) {
                    $ajuste = (float)$insumo['cantidad_por_unidad'] * $diferencia;

                    $stmtActInsumo->bindValue(1, $ajuste);
                    $stmtActInsumo->bindValue(2, $ajuste);
                    $stmtActInsumo->bindValue(3, $ajuste);
                    $stmtActInsumo->bindValue(4, (int)$insumo['id_insumo'], PDO::PARAM_INT);
                    $stmtActInsumo->execute();
                }
            }

            $stmtActStock = $this->db->prepare(
                'UPDATE productos SET stock_producto = ? WHERE id = ?'
            );
            $stmtActStock->execute([$cantidadProducto, $idProducto]);

            if ($cantidadProducto === 0) {
                $stmtAllLotes = $this->db->prepare(
                    'UPDATE productos_stock SET cantidad_producto = 0 WHERE id_producto = ?'
                );
                $stmtAllLotes->execute([$idProducto]);
            } elseif ($diferencia > 0 && $lote) {
                $nuevaCantidadLote = (int)$lote['cantidad_producto'] + $diferencia;
                if ($nuevaCantidadLote < 0) {
                    $nuevaCantidadLote = 0;
                }
                $stmtActLote = $this->db->prepare(
                    'UPDATE productos_stock SET cantidad_producto = ? WHERE id_producto_stock = ?'
                );
                $stmtActLote->execute([$nuevaCantidadLote, (int)$lote['id_producto_stock']]);
            } elseif ($diferencia < 0) {
                $stmtTodosLotes = $this->db->prepare(
                    'SELECT id_producto_stock, cantidad_producto
                     FROM productos_stock
                     WHERE id_producto = ? AND cantidad_producto > 0
                     ORDER BY fecha_insercion ASC'
                );

                $stmtTodosLotes->execute([$idProducto]);
                $todosLotes = $stmtTodosLotes->fetchAll();

                $porDescontar = abs($diferencia);
                $stmtActLote = $this->db->prepare(
                    'UPDATE productos_stock SET cantidad_producto = ? WHERE id_producto_stock = ?'
                );

                foreach ($todosLotes as $l) {
                    if ($porDescontar <= 0) break;
 
                    $enEsteLote = (int)$l['cantidad_producto'];
                    $quitar = min($enEsteLote, $porDescontar);
                    $stmtActLote->execute([
                        $enEsteLote - $quitar,
                        (int)$l['id_producto_stock']
                    ]);
                    $porDescontar -= $quitar;
                }
            }

            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            Response::serverError('Error al actualizar el stock: ' . $e->getMessage());
        }

        Response::success([
            'success' => true,
            'mensaje' => 'Stock actualizado correctamente',
            'id_producto' => $idProducto,
            'stock_nuevo' => $cantidadProducto,
            'diferencia' => $diferencia,
            'insumos_ajustados' => count($insumos),
        ]);
    }

    public function delete(int $idProductoStock): void
    {
        $stmt = $this->db->prepare(
            'SELECT id_producto_stock, cantidad_producto FROM productos_stock WHERE id_producto_stock = ?'
        );
        $stmt->execute([$idProductoStock]);
        $lote = $stmt->fetch();

        if (!$lote) {
            Response::notFound("No se encontró el lote de stock con ID: {$idProductoStock}");
        }

        if ((int)$lote['cantidad_producto'] !== 0) {
            Response::error('Solo se pueden eliminar lotes con cantidad igual a 0', 400);
        }

        try {
            $this->db->beginTransaction();

            $stmtGan = $this->db->prepare(
                'DELETE FROM ganancias_productos WHERE id_producto_stock = ?'
            );
            $stmtGan->execute([$idProductoStock]);

            $stmtDel = $this->db->prepare('DELETE FROM productos_stock WHERE id_producto_stock = ?');
            $stmtDel->execute([$idProductoStock]);

            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            Response::serverError('Error al eliminar el lote: ' . $e->getMessage());
        }

        Response::success(['mensaje' => 'Lote de stock eliminado correctamente']);
    }

    public static function descontarStockFIFO(PDO $db, int $idProducto, int $cantidad): bool
    {
        $stmtLotes = $db->prepare(
            'SELECT id_producto_stock, cantidad_producto
             FROM productos_stock
             WHERE id_producto = ? AND cantidad_producto > 0
             ORDER BY fecha_insercion ASC'
        );
        $stmtLotes->execute([$idProducto]);
        $lotes = $stmtLotes->fetchAll();

        $totalDisponible = array_sum(array_column($lotes, 'cantidad_producto'));
        if ($totalDisponible < $cantidad) {
            return false;
        }

        $restante = $cantidad;
        $stmtUpdate = $db->prepare(
            'UPDATE productos_stock SET cantidad_producto = ? WHERE id_producto_stock = ?'
        );

        foreach ($lotes as $lote) {
            if ($restante <= 0) break;

            $enEsteLote = (int)$lote['cantidad_producto'];
            $aDescontar = min($enEsteLote, $restante);
            $nuevaCantidad = $enEsteLote - $aDescontar;

            $stmtUpdate->execute([$nuevaCantidad, (int)$lote['id_producto_stock']]);
            $restante -= $aDescontar;
        }

        $stmtProd = $db->prepare(
            'UPDATE productos SET stock_producto = stock_producto - ? WHERE id = ?'
        );
        $stmtProd->execute([$cantidad, $idProducto]);

        return true;
    }
}
