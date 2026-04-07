<?php
namespace App\Controllers\Api;

use App\Libraries\Auditoria;
use App\Models\AlumnoModel;
use App\Models\UsuarioModel;
use CodeIgniter\RESTful\ResourceController;

class AlumnoController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // GET /api/alumno/mis-hijos
    public function misHijos()
    {
        $usuario = $this->request->usuario;
        $model   = new AlumnoModel();
        $hijos   = $model->misHijos($usuario->id);

        return $this->respond([
            'status' => 'ok',
            'hijos'  => $hijos,
        ]);
    }

    // POST /api/alumno/vincular
    // El papá agrega a otro hijo con su CURP y contraseña
    public function vincular()
    {
        $usuario = $this->request->usuario;
        $json    = $this->request->getJSON();

        if (empty($json->curp) || empty($json->password)) {
            return $this->fail('CURP y contraseña son requeridos.', 400);
        }

        $curp = strtoupper($json->curp);

        // Buscar el alumno directamente por CURP
        $alumno = $this->db->table('alumnos')
            ->where('curp', $curp)
            ->where('activo', 1)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->fail('Alumno no encontrado.', 404);
        }

        // Buscar el usuario padre del alumno para verificar contraseña
        $usuarioPadre = $this->db->table('usuarios')
            ->where('curp', $curp)
            ->where('escuela_id', $alumno['escuela_id'])
            ->get()->getRowArray();

        if (!$usuarioPadre || !password_verify($json->password, $usuarioPadre['password'])) {
            return $this->fail('Contraseña incorrecta.', 401);
        }

        // Verificar si ya está vinculado
        $yaVinculado = $this->db->table('usuario_alumno')
            ->where('usuario_id', $usuario->id)
            ->where('alumno_id', $alumno['id'])
            ->countAllResults();

        if ($yaVinculado) {
            return $this->fail('Este alumno ya está vinculado.', 409);
        }

        // Vincular
        $this->db->table('usuario_alumno')->insert([
            'usuario_id' => $usuario->id,
            'alumno_id'  => $alumno['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Alumno vinculado correctamente.',
            'alumno'  => [
                'nombre' => $alumno['nombre'],
                'grado'  => $alumno['grado'],
                'grupo'  => $alumno['grupo'],
            ],
        ]);
    }

    // POST /api/admin/alumno/nuevo
    public function nuevo()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $json  = $this->request->getJSON();
        $rules = [
            'curp'   => 'required|min_length[18]|max_length[18]',
            'nombre' => 'required',
            'grado'  => 'required',
            'grupo'  => 'required',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $curp = strtoupper($json->curp);

        // Verificar que no exista en esta escuela
        $existe = $this->db->table('alumnos')
            ->where('curp', $curp)
            ->where('escuela_id', $usuario->escuela_id)
            ->countAllResults();

        if ($existe) {
            return $this->fail('Este alumno ya está registrado en la escuela.', 409);
        }

        $uuid     = $this->_generarUuid();
        $password = password_hash(substr($curp, 0, 8), PASSWORD_DEFAULT);

        $this->db->transStart();

        // 1. Insertar alumno
        $this->db->table('alumnos')->insert([
            'escuela_id' => $usuario->escuela_id,
            'uuid'       => $uuid,
            'curp'       => $curp,
            'nombre'     => $json->nombre,
            'grado'      => $json->grado,
            'grupo'      => $json->grupo,
            'activo'     => 1,
            'pagado'     => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $alumnoId = $this->db->insertID();

        // 2. Insertar usuario
        $usuarioExistente = $this->db->table('usuarios')
            ->where('curp', $curp)
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getRowArray();

        if ($usuarioExistente) {
            $usuarioId = $usuarioExistente['id'];
        } else {
            $this->db->table('usuarios')->insert([
                'escuela_id' => $usuario->escuela_id,
                'curp'       => $curp,
                'nombre'     => $json->nombre,
                'password'   => $password,
                'rol'        => 'padre',
                'activo'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $usuarioId = $this->db->insertID();
        }

        // 3. Vincular
        $this->db->table('usuario_alumno')->insert([
            'usuario_id' => $usuarioId,
            'alumno_id'  => $alumnoId,
            'relacion'   => 'padre',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            return $this->fail('Error al guardar el alumno.', 500);
        }

        Auditoria::log('crear', 'alumnos',
            "Nuevo alumno: {$json->nombre} ({$curp})", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Alumno creado correctamente.',
            'alumno'  => [
                'uuid'   => $uuid,
                'curp'   => $curp,
                'nombre' => $json->nombre,
                'grado'  => $json->grado,
                'grupo'  => $json->grupo,
            ]
        ]);
    }

    private function _generarUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}