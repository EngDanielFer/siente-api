<?php

/**
 * CostosFijosController
 * 
 * GET /api/siente/productos/{id}/costos-fijos → getByProducto
 */

declare(strict_types=1);

class CostosFijosController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function getByProducto(int $idProducto): void
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM costos_fijos_productos WHERE id_producto = ?'
        );
        $stmt->execute([$idProducto]);
        $costos = $stmt->fetch();

        if (!$costos) {
            Response::success([]);
            return;
        }

        $lista = [];
 
        $campos = [
            'costo_luz' => 'Luz',
            'costo_agua' => 'Agua',
            'costo_gas' => 'Gas',
            'costo_aseo' => 'Aseo',
            'costo_internet' => 'Internet',
            'mano_de_obra' => 'Mano de obra',
            'costo_transporte' => 'Transporte',
            'costo_perdidas' => 'Pérdidas',
            'costo_herramientas' => 'Herramientas',
            'costo_marketing_redes' => 'Marketing Redes Sociales',
            'costo_marketing_disenador' => 'Marketing Diseñador',
            'costo_admin' => 'Administración',
            'costo_etiqueta' => 'Etiqueta',
        ];
 
        foreach ($campos as $columna => $nombre) {
            $valor = (float)($costos[$columna] ?? 0);
            if ($valor > 0) {
                $lista[] = [
                    'nombre' => $nombre,
                    'costo' => $valor,
                ];
            }
        }
 
        Response::success($lista);
    }

    
}
