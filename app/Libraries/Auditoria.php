<?php
namespace App\Libraries;

class Auditoria
{
    public static function log(
        string $accion,
        string $modulo,
        string $descripcion,
        object $usuario  // el payload del JWT
    ): void {
        $db = \Config\Database::connect();
        $db->table('auditoria')->insert([
            'escuela_id'  => $usuario->escuela_id ?? null,
            'usuario_id'  => $usuario->id,
            'nombre'      => $usuario->nombre,
            'rol'         => $usuario->rol,
            'accion'      => $accion,
            'modulo'      => $modulo,
            'descripcion' => $descripcion,
            'ip'          => service('request')->getIPAddress(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}