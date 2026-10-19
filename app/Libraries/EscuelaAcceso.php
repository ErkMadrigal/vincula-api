<?php
namespace App\Libraries;

/**
 * Qué escuelas puede ver cada rol:
 *  - super_admin: todas
 *  - admin: las de admin_escuelas (asignadas por el super admin + las que creó)
 *  - director / maestro / padre: solo la suya (usuarios.escuela_id)
 */
class EscuelaAcceso
{
    /** @return int[]|null  null = todas */
    public static function idsPermitidos(object $usuario): ?array
    {
        if ($usuario->rol === 'super_admin') {
            return null;
        }

        if ($usuario->rol === 'admin') {
            $filas = \Config\Database::connect()->table('admin_escuelas')
                ->select('escuela_id')
                ->where('usuario_id', $usuario->id)
                ->get()->getResultArray();
            return array_map('intval', array_column($filas, 'escuela_id'));
        }

        return isset($usuario->escuela_id) ? [(int) $usuario->escuela_id] : [];
    }

    public static function puedeVer(object $usuario, int $escuelaId): bool
    {
        $ids = self::idsPermitidos($usuario);
        if ($ids === null) {
            return (bool) \Config\Database::connect()->table('escuelas')->where('id', $escuelaId)->countAllResults();
        }
        return in_array($escuelaId, $ids, true);
    }

    /** Lista de escuelas visibles (para el selector) */
    public static function listar(object $usuario): array
    {
        $db  = \Config\Database::connect();
        $ids = self::idsPermitidos($usuario);

        $q = $db->table('escuelas e')->select('e.id, e.nombre, e.activa')->orderBy('e.nombre', 'ASC');

        if ($usuario->rol === 'admin') {
            $q->select('ae.origen')
              ->join('admin_escuelas ae', 'ae.escuela_id = e.id')
              ->where('ae.usuario_id', $usuario->id);
        } elseif ($ids !== null) {
            if (!$ids) return [];
            $q->whereIn('e.id', $ids);
        }

        return $q->get()->getResultArray();
    }
}
