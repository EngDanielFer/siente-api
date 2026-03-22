<?php

/**
 * ProductosController
 * 
 * GET /api/siente/productos → getAll
 * GET /api/siente/productos/stock → getByStock
 * GET /api/siente/productos/{id} → getById
 * GET /api/siente/productos/{id}/completo → getCompleto
 * GET /api/siente/productos/{id}/insumos → getInsumos
 * POST /api/siente/productos → create (usa SP p_insertar_producto_insumos)
 * PUT /api/siente/productos/{id} → update (usa SP p_insertar_producto_insumos)
 * DELETE /api/siente/productos/{id} → delete
 */

declare(strict_types=1);

class ProductosController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function getAll(): void
    {
        $stmt = $this->db->query('SELECT * FROM productos ORDER BY id');
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row = $this->encodeImagen($row);
        }

        Response::success($rows);
    }

    public function getByStock(): void
    {
        $stmt = $this->db->query('SELECT * FROM productos WHERE stock_producto > 0 ORDER BY id');
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row = $this->encodeImagen($row);
        }

        Response::success($rows);
    }

    public function getById(int $id): void
    {
        $producto = $this->findOrFail($id);
        Response::success($this->encodeImagen($producto));
    }

    public function getCompleto(int $id): void
    {
        $producto = $this->findOrFail($id);

        // Insumos por producto
        $stmt = $this->db->prepare(
            'SELECT ip.id_insumo, ip.cantidad_insumo AS cantidad
             FROM insumos_por_producto ip
             WHERE ip.id_producto = ?'
        );
        $stmt->execute([$id]);
        $insumosRaw = $stmt->fetchAll();

        $insumos = array_map(fn($r) => [
            'id_insumo' => (int)$r['id_insumo'],
            'cantidad' => (float)$r['cantidad'],
        ], $insumosRaw);

        // Costos fijos
        $stmtCf = $this->db->prepare(
            'SELECT * FROM costos_fijos_productos WHERE id_producto = ?'
        );
        $stmtCf->execute([$id]);
        $costos = $stmtCf->fetch() ?: [];

        $dto = [
            'id' => $producto['id'],
            'nombre_producto' => $producto['nombre_producto'],
            'descripcion_producto' => $producto['descripcion_producto'],
            'peso_producto' => (int)$producto['peso_producto'],
            'costo_produccion' => (float)$producto['costo_produccion'],
            'ganancia_por_mayor' => (float)$producto['ganancia_por_mayor'],
            'ganancia_detal' => (float)$producto['ganancia_detal'],
            'precio_por_mayor' => (float)$producto['precio_por_mayor'],
            'precio_detal' => (float)$producto['precio_detal'],
            'stock_producto' => (int)$producto['stock_producto'],
            'imagen_producto' => $producto['imagen_producto']
                ? base64_encode($producto['imagen_producto']) : null,
            'insumos' => $insumos,
            'costo_luz' => (float)($costos['costo_luz'] ?? 0),
            'costo_agua' => (float)($costos['costo_agua'] ?? 0),
            'costo_gas' => (float)($costos['costo_gas'] ?? 0),
            'costo_aseo' => (float)($costos['costo_aseo'] ?? 0),
            'costo_internet' => (float)($costos['costo_internet'] ?? 0),
            'costo_mano_obra' => (float)($costos['mano_de_obra'] ?? 0),
            'comentario_mano_obra' => $costos['comentario_mano_de_obra'] ?? null,
            'costo_transporte' => (float)($costos['costo_transporte'] ?? 0),
            'costo_perdidas' => (float)($costos['costo_perdidas'] ?? 0),
            'costo_herramientas' => (float)($costos['costo_herramientas'] ?? 0),
            'costo_mark_redes' => (float)($costos['costo_marketing_redes'] ?? 0),
            'costo_mark_disenador' => (float)($costos['costo_marketing_disenador'] ?? 0),
            'costo_admin' => (float)($costos['costo_admin'] ?? 0),
            'costo_etiqueta' => (float)($costos['costo_etiqueta'] ?? 0),
        ];

        Response::success($dto);
    }

    public function getInsumos(int $id): void
    {
        $this->findOrFail($id);

        $stmt = $this->db->prepare(
            'SELECT ipp.id_insumo, i.nombre_insumo, ipp.cantidad_insumo, ipp.precio_insumo
             FROM insumos_por_producto ipp
             JOIN insumos i ON i.id = ipp.id_insumo
             WHERE ipp.id_producto = ?'
        );
        $stmt->execute([$id]);

        $rows = $stmt->fetchAll();
        $result = array_map(fn($r) => [
            'idInsumo' => (int)$r['id_insumo'],
            'nombreInsumo' => $r['nombre_insumo'],
            'cantidadInsumo' => (float)$r['cantidad_insumo'],
            'precioInsumo' => (float)$r['precio_insumo'],
        ], $rows);

        Response::success($result);
    }

    public function create(): void
    {
        $body = Response::getBody();
        $id   = $body['id'] ?? null;

        if ($id !== null) {
            $stmt = $this->db->prepare('SELECT id FROM productos WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->fetch()) {
                Response::conflict("El producto con ID {$id} ya existe. Use PUT para actualizar.");
            }
        }

        $this->ejecutarSpProducto($body);
        Response::created(['mensaje' => 'Producto creado exitosamente']);
    }

    public function update(int $id): void
    {
        $this->findOrFail($id);
        $body = Response::getBody();
        $body['id'] = $id; // forzar el ID del path

        $this->ejecutarSpProducto($body);
        Response::success(['mensaje' => 'Producto actualizado exitosamente']);
    }

    public function delete(int $id): void
    {
        $this->findOrFail($id);

        $stmt = $this->db->prepare('DELETE FROM productos WHERE id = ?');
        $stmt->execute([$id]);

        Response::success(['mensaje' => 'Producto eliminado exitosamente']);
    }

    private function ejecutarSpProducto(array $body): void
    {
        $imagen = null;
        if (!empty($body['imagen_producto'])) {
            $raw = $body['imagen_producto'];
            if (str_contains($raw, ',')) {
                $raw = explode(',', $raw, 2)[1];
            }
            $imagen = base64_decode($raw);
        }

        $insumosJson = json_encode($body['insumos'] ?? []);

        try {
            $stmt = $this->db->prepare('CALL p_insertar_producto_insumos(
                :prod_id, :prod_nombre, :prod_descripcion, :prod_peso, :prod_imagen, :prod_insumos,
                :prod_costo_luz, :prod_costo_agua, :prod_costo_gas, :prod_costo_aseo,
                :prod_costo_internet, :prod_costo_mano_obra, :prod_comentario_mano_obra,
                :prod_costo_transporte, :prod_costo_perdidas, :prod_costo_herramientas,
                :prod_costo_mark_redes, :prod_costo_mark_disenador, :prod_costo_admin,
                :prod_costo_etiqueta
            )');

            $stmt->bindValue(':prod_id', $body['id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':prod_nombre', $body['nombre_producto'] ?? null);
            $stmt->bindValue(':prod_descripcion', $body['descripcion_producto'] ?? null);
            $stmt->bindValue(':prod_peso', $body['peso_producto'] ?? 0, PDO::PARAM_INT);
            $stmt->bindValue(':prod_imagen', $imagen, PDO::PARAM_LOB);
            $stmt->bindValue(':prod_insumos', $insumosJson);
            $stmt->bindValue(':prod_costo_luz', $body['costo_luz'] ?? 0);
            $stmt->bindValue(':prod_costo_agua', $body['costo_agua'] ?? 0);
            $stmt->bindValue(':prod_costo_gas', $body['costo_gas'] ?? 0);
            $stmt->bindValue(':prod_costo_aseo', $body['costo_aseo'] ?? 0);
            $stmt->bindValue(':prod_costo_internet', $body['costo_internet'] ?? 0);
            $stmt->bindValue(':prod_costo_mano_obra', $body['costo_mano_obra'] ?? 0);
            $stmt->bindValue(':prod_comentario_mano_obra', $body['comentario_mano_obra'] ?? null);
            $stmt->bindValue(':prod_costo_transporte', $body['costo_transporte'] ?? 0);
            $stmt->bindValue(':prod_costo_perdidas', $body['costo_perdidas'] ?? 0);
            $stmt->bindValue(':prod_costo_herramientas', $body['costo_herramientas'] ?? 0);
            $stmt->bindValue(':prod_costo_mark_redes', $body['costo_mark_redes'] ?? 0);
            $stmt->bindValue(':prod_costo_mark_disenador', $body['costo_mark_disenador'] ?? 0);
            $stmt->bindValue(':prod_costo_admin', $body['costo_admin'] ?? 0);
            $stmt->bindValue(':prod_costo_etiqueta', $body['costo_etiqueta'] ?? 0);

            $stmt->execute();
        } catch (PDOException $e) {
            Response::serverError('Error al procesar el producto: ' . $e->getMessage());
        }
    }

    private function findOrFail(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM productos WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::notFound("Producto no encontrado con ID: {$id}");
        }

        return $row;
    }

    private function encodeImagen(array $row): array
    {
        if (isset($row['imagen_producto']) && $row['imagen_producto'] !== null) {
            $row['imagen_producto'] = base64_encode($row['imagen_producto']);
        }
        return $row;
    }
}
