<?php

/**
 * Timezone
 *
 * Centraliza la configuración de zona horaria para PHP y MySQL.
 * Colombia no observa horario de verano, por lo que su offset es
 * siempre UTC-5 (America/Bogota).
 *
 * Uso:
 *   Timezone::apply();          → PHP únicamente
 *   Timezone::apply($pdo);      → PHP + MySQL (recomendado)
 */

declare(strict_types=1);

class Timezone
{
    private const TZ_PHP = 'America/Bogota';
    private const TZ_MYSQL = '-05:00';

    /**
     * Fija la zona horaria en PHP y, opcionalmente, en la sesión MySQL.
     *
     * @param PDO|null $pdo  Conexión activa. Si se pasa, ejecuta
     *                       SET time_zone para que NOW() devuelva
     *                       la hora colombiana.
     */

    public static function apply(?PDO $pdo = null) : void 
    {
        date_default_timezone_set(self::TZ_PHP);

        if ($pdo !== null) {
            $pdo->exec("SET time_zone = '" . self::TZ_MYSQL . "'");
        }
    }
}
