<?php

/**
 * FacturasController
 * 
 * GET  /api/siente/facturas → getAll
 * GET  /api/siente/facturas/{id} → getById
 * GET  /api/siente/facturas/email/{email} → getByEmail
 * POST /api/siente/facturas → create (usa SP p_insertar_factura)
 */

declare(strict_types=1);

class FacturasController
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
                f.id, f.fecha,
                f.nombre_cliente, f.apellido_cliente, f.email_cliente,
                f.direccion_cliente, f.complemento_direccion,
                f.telefono_cliente, f.pais_cliente, f.region_cliente, f.ciudad_cliente,
                f.valor_pagado, f.precio_envio, f.valor_total, f.metodo_pago
            FROM facturas f
            ORDER BY f.fecha DESC
        ';

        $facturas = [];
        $rows = $this->db->query($sql)->fetchAll();

        foreach ($rows as $row) {
            $factura  = $this->mapFactura($row);
            $factura['detalle'] = $this->getDetalle((int)$row['id']);
            $facturas[] = $factura;
        }

        Response::success($facturas);
    }

    public function getById(int $id): void
    {
        $stmt = $this->db->prepare('
            SELECT
                f.id, f.fecha,
                f.nombre_cliente, f.apellido_cliente, f.email_cliente,
                f.direccion_cliente, f.complemento_direccion,
                f.telefono_cliente, f.pais_cliente, f.region_cliente, f.ciudad_cliente,
                f.valor_pagado, f.precio_envio, f.valor_total, f.metodo_pago
            FROM facturas f
            WHERE f.id = ?
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::notFound("Factura no encontrada con ID: {$id}");
        }

        $factura = $this->mapFactura($row);
        $factura['detalle'] = $this->getDetalle($id);

        Response::success($factura);
    }

    public function getByEmail(string $email): void
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM facturas WHERE email_cliente = ? ORDER BY fecha DESC'
        );
        $stmt->execute([$email]);
        Response::success($stmt->fetchAll());
    }

    public function create(): void
    {
        $body = Response::getBody();

        $cliente  = $body['datosCliente'] ?? [];
        $productos = $body['productos'] ?? [];
        $errores  = [];

        if (empty($cliente['nombre_cliente'])) {
            $errores['nombre_cliente'] = 'El nombre del cliente es requerido';
        }
        if (empty($cliente['apellido_cliente'])) {
            $errores['apellido_cliente'] = 'El apellido del cliente es requerido';
        }
        if (empty($cliente['email_cliente']) || !filter_var($cliente['email_cliente'], FILTER_VALIDATE_EMAIL)) {
            $errores['email_cliente'] = 'Email inválido';
        }
        if (empty($cliente['direccion_cliente'])) {
            $errores['direccion_cliente'] = 'La dirección es requerida';
        }
        if (empty($cliente['telefono_cliente'])) {
            $errores['telefono_cliente'] = 'El teléfono es requerido';
        }
        if (empty($cliente['pais_cliente'])) {
            $errores['pais_cliente'] = 'El país es requerido';
        }
        if (empty($cliente['region_cliente'])) {
            $errores['region_cliente'] = 'La región es requerida';
        }
        if (empty($cliente['ciudad_cliente'])) {
            $errores['ciudad_cliente'] = 'La ciudad es requerida';
        }
        if (empty($body['metodo_pago'])) {
            $errores['metodo_pago'] = 'El método de pago es requerido';
        }
        if (!isset($body['precio_envio']) || $body['precio_envio'] < 0) {
            $errores['precio_envio'] = 'El precio de envío no puede ser negativo';
        }
        if (empty($body['tipo_precio']) || !in_array($body['tipo_precio'], ['mayor', 'detal'])) {
            $errores['tipo_precio'] = "El tipo de precio debe ser 'mayor' o 'detal'";
        }
        if (empty($productos)) {
            $errores['productos'] = 'Debe incluir al menos un producto';
        }

        if (!empty($errores)) {
            Response::json(['mensaje' => 'Errores de validación', 'errores' => $errores], 400);
        }

        $productosJson = json_encode(array_map(fn($p) => [
            'id_producto' => (int)$p['id_producto'],
            'cantidad_producto' => (int)$p['cantidad_producto'],
        ], $productos));

        $complemento = $cliente['complemento_direccion'] ?? null;
        if (empty($complemento)) {
            $complemento = null;
        }

        try {
            $this->db->prepare("SET @out_id_factura = 0")->execute();

            $stmt = $this->db->prepare('CALL p_insertar_factura(
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @out_id_factura
            )');

            $stmt->execute([
                $cliente['nombre_cliente'],
                $cliente['apellido_cliente'],
                $cliente['email_cliente'],
                $cliente['direccion_cliente'],
                $complemento,
                $cliente['telefono_cliente'],
                $cliente['pais_cliente'],
                $cliente['region_cliente'],
                $cliente['ciudad_cliente'],
                $productosJson,
                $body['precio_envio'],
                $body['metodo_pago'],
                $body['tipo_precio'],
            ]);

            $result = $this->db->query('SELECT @out_id_factura AS id_factura')->fetch();
            $idFactura = (int)($result['id_factura'] ?? 0);

            if ($idFactura === 0) {
                Response::serverError('El procedimiento no retornó un ID de factura');
            }
        } catch (PDOException $e) {
            Response::serverError('Error al crear la factura: ' . $e->getMessage());
        }

        $stmtF = $this->db->prepare(
            'SELECT valor_total, valor_pagado, precio_envio FROM facturas WHERE id = ?'
        );
        $stmtF->execute([$idFactura]);
        $factura = $stmtF->fetch();

        Response::created([
            'id_factura' => $idFactura,
            'mensaje' => 'Factura creada exitosamente',
            'valor_total' => $factura['valor_total'] ?? null,
            'valor_pagado' => $factura['valor_pagado'] ?? null,
            'precio_envio' => $factura['precio_envio'] ?? null,
        ]);
    }

    private function mapFactura(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'fecha' => $row['fecha'],
            'nombreCliente' => $row['nombre_cliente'],
            'apellidoCliente' => $row['apellido_cliente'],
            'emailCliente' => $row['email_cliente'],
            'direccionCliente' => $row['direccion_cliente'],
            'complementoDireccion' => $row['complemento_direccion'],
            'telefonoCliente' => $row['telefono_cliente'],
            'paisCliente' => $row['pais_cliente'],
            'regionCliente' => $row['region_cliente'],
            'ciudadCliente' => $row['ciudad_cliente'],
            'valorPagado' => $row['valor_pagado'],
            'precioEnvio' => $row['precio_envio'],
            'valorTotal' => $row['valor_total'],
            'metodoPago' => $row['metodo_pago'],
        ];
    }

    private function getDetalle(int $idFactura): array
    {
        $stmt = $this->db->prepare('
            SELECT
                fd.id_producto,
                p.nombre_producto,
                fd.cantidad_producto,
                fd.precio_unitario,
                fd.subtotal
            FROM factura_detalle fd
            INNER JOIN productos p ON p.id = fd.id_producto
            WHERE fd.id_factura = ?
        ');
        $stmt->execute([$idFactura]);

        return array_map(fn($r) => [
            'idProducto' => (int)$r['id_producto'],
            'nombreProducto' => $r['nombre_producto'],
            'cantidadProducto' => (int)$r['cantidad_producto'],
            'precioUnitario' => $r['precio_unitario'],
            'subtotal' => $r['subtotal'],
        ], $stmt->fetchAll());
    }
}
