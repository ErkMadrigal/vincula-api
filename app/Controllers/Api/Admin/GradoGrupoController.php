<?php
namespace App\Controllers\Api\Admin;

use App\Libraries\Auditoria;
use CodeIgniter\RESTful\ResourceController;

class GradoGrupoController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // GET /api/admin/grados-grupos
    public function index()
    {
        $usuario = $this->request->usuario;

        $lista = $this->db->table('grados_grupos')
            ->where('escuela_id', $usuario->escuela_id)
            ->where('activo', 1)
            ->orderBy('grado', 'ASC')
            ->orderBy('grupo', 'ASC')
            ->get()->getResultArray();

        return $this->respond(['status' => 'ok', 'grados_grupos' => $lista]);
    }

    // POST /api/admin/grados-grupos/nuevo
    public function nuevo()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $json  = $this->request->getJSON();
        $rules = [
            'grado' => 'required|max_length[20]',
            'grupo' => 'required|max_length[10]',
            'turno' => 'required|in_list[matutino,vespertino]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        // Verificar que no exista
        $existe = $this->db->table('grados_grupos')
            ->where('escuela_id', $usuario->escuela_id)
            ->where('grado', $json->grado)
            ->where('grupo', $json->grupo)
            ->where('turno', $json->turno)
            ->countAllResults();

        if ($existe) {
            return $this->fail('Ya existe ese grado/grupo/turno.', 409);
        }

        $this->db->table('grados_grupos')->insert([
            'escuela_id' => $usuario->escuela_id,
            'grado'      => $json->grado,
            'grupo'      => $json->grupo,
            'turno'      => $json->turno,
            'activo'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Auditoria::log('crear', 'grados_grupos',
            "Nuevo grado/grupo: {$json->grado} {$json->grupo} {$json->turno}", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Grado/grupo creado correctamente.',
        ]);
    }

    // DELETE /api/admin/grados-grupos/:id
    public function eliminar(int $id = 0)
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $item = $this->db->table('grados_grupos')
            ->where('id', $id)
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getRowArray();

        if (!$item) {
            return $this->failNotFound('Grado/grupo no encontrado.');
        }

        $this->db->table('grados_grupos')->update(
            ['activo' => 0],
            ['id'     => $id]
        );

        return $this->respond(['status' => 'ok', 'mensaje' => 'Eliminado correctamente.']);
    }
}