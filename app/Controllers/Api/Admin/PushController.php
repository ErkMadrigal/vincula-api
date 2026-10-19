<?php
namespace App\Controllers\Api\Admin;

use CodeIgniter\RESTful\ResourceController;

/**
 * GET /api/admin/push/diagnostico
 * Explica por qué una notificación podría no llegar: Firebase configurado,
 * cuántas familias tienen un celular/navegador registrado y quiénes no.
 */
class PushController extends ResourceController
{
    protected $format = 'json';

    public function diagnostico()
    {
        $usuario = $this->request->usuario;
        if (!in_array($usuario->rol, ['super_admin', 'admin', 'director'], true)) {
            return $this->fail('No tienes permiso.', 403);
        }

        $db = \Config\Database::connect();

        // 1. Firebase en el servidor
        $credPath  = APPPATH . 'Config/firebase-credentials.json';
        $cred      = is_file($credPath) ? json_decode((string) file_get_contents($credPath), true) : null;
        $firebase  = [
            'credenciales' => is_array($cred) && !empty($cred['project_id']),
            'libreria'     => class_exists(\Google\Auth\ApplicationDefaultCredentials::class),
            'proyecto'     => $cred['project_id'] ?? null,
        ];
        $firebase['ok'] = $firebase['credenciales'] && $firebase['libreria'];

        // 2. Alumnos activos y cuántos tienen al menos un dispositivo de un tutor
        $alumnos = $db->table('alumnos a')
            ->select('a.id, a.nombre, a.grado, a.grupo, COUNT(DISTINCT d.id) as dispositivos', false)
            ->join('usuario_alumno ua', 'ua.alumno_id = a.id', 'left')
            ->join('dispositivos d', 'd.usuario_id = ua.usuario_id AND d.activo = 1', 'left')
            ->where('a.escuela_id', $usuario->escuela_id)
            ->where('a.activo', 1)
            ->groupBy('a.id')
            ->orderBy('a.grado')->orderBy('a.grupo')->orderBy('a.nombre')
            ->get()->getResultArray();

        $sin = array_values(array_filter($alumnos, fn($a) => (int) $a['dispositivos'] === 0));

        $porPlataforma = $db->table('dispositivos d')
            ->select('d.plataforma, COUNT(DISTINCT d.id) as total', false)
            ->join('usuario_alumno ua', 'ua.usuario_id = d.usuario_id')
            ->join('alumnos a', 'a.id = ua.alumno_id')
            ->where('a.escuela_id', $usuario->escuela_id)
            ->where('d.activo', 1)
            ->groupBy('d.plataforma')
            ->get()->getResultArray();

        return $this->respond([
            'status'          => 'ok',
            'firebase'        => $firebase,
            'total_alumnos'   => count($alumnos),
            'con_dispositivo' => count($alumnos) - count($sin),
            'sin_dispositivo' => count($sin),
            'por_plataforma'  => array_column($porPlataforma, 'total', 'plataforma'),
            'alumnos_sin_dispositivo' => array_map(
                fn($a) => ['nombre' => $a['nombre'], 'grado' => $a['grado'], 'grupo' => $a['grupo']],
                array_slice($sin, 0, 300)
            ),
        ]);
    }
}
