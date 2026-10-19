<?php
namespace App\Controllers\Api;

use App\Libraries\Auditoria;
use App\Libraries\EscuelaAcceso;
use CodeIgniter\RESTful\ResourceController;

/**
 * Escuelas visibles para el usuario (selector del panel) y alta de escuelas por el admin.
 */
class EscuelaController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // GET /api/mis-escuelas
    public function misEscuelas()
    {
        $usuario = $this->request->usuario;

        return $this->respond([
            'status'   => 'ok',
            'escuelas' => EscuelaAcceso::listar($usuario),
        ]);
    }

    // POST /api/escuelas/nueva   { nombre }
    // El admin crea una escuela y queda asignada a él automáticamente.
    public function nueva()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'super_admin'], true)) {
            return $this->fail('No tienes permiso para crear escuelas.', 403);
        }

        $json   = $this->request->getJSON();
        $nombre = trim((string) ($json->nombre ?? ''));

        if (mb_strlen($nombre) < 3) {
            return $this->fail('El nombre de la escuela debe tener al menos 3 caracteres.', 400);
        }

        $subdominio = $this->_subdominioUnico($json->subdominio ?? $nombre);
        $ahora      = date('Y-m-d H:i:s');

        $this->db->transStart();

        $this->db->table('escuelas')->insert([
            'nombre'       => $nombre,
            'subdominio'   => $subdominio,
            'plan'         => 'basico',   // planes y precios: solo el super admin
            'activa'       => 1,
            'cobro_activo' => 0,
            'creada_por'   => $usuario->id,
            'created_at'   => $ahora,
        ]);
        $escuelaId = (int) $this->db->insertID();

        if ($usuario->rol === 'admin') {
            $this->db->table('admin_escuelas')->insert([
                'usuario_id' => $usuario->id,
                'escuela_id' => $escuelaId,
                'origen'     => 'creada',
                'created_at' => $ahora,
            ]);
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            return $this->fail('No se pudo crear la escuela.', 500);
        }

        Auditoria::log('crear', 'escuela', "Creó la escuela {$nombre}", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Escuela creada correctamente.',
            'escuela' => ['id' => $escuelaId, 'nombre' => $nombre, 'activa' => 1, 'origen' => 'creada'],
        ]);
    }

    private function _subdominioUnico(string $base): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $base)), '-'));
        $slug = substr($slug ?: 'escuela', 0, 40);
        $final = $slug;
        $n = 2;
        while ($this->db->table('escuelas')->where('subdominio', $final)->countAllResults()) {
            $final = $slug . '-' . $n++;
        }
        return $final;
    }
}
