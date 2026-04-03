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

        $rules = [
            'curp'     => 'required|min_length[18]|max_length[18]',
            'password' => 'required|min_length[8]',
            'relacion' => 'required|in_list[padre,madre,tutor]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $curp = strtoupper($json->curp);

        // 1. Buscar al usuario del hijo en la misma escuela
        $usuarioModel  = new UsuarioModel();
        $usuarioHijo   = $usuarioModel
            ->where('curp', $curp)
            ->where('escuela_id', $usuario->escuela_id)
            ->where('activo', 1)
            ->first();

        if (!$usuarioHijo) {
            return $this->failNotFound('Alumno no encontrado en esta escuela.');
        }

        if (!password_verify($json->password, $usuarioHijo['password'])) {
            return $this->fail('Credenciales incorrectas.', 401);
        }

        // 2. Buscar al alumno vinculado a ese usuario
        $alumnoModel = new AlumnoModel();
        $alumno = $alumnoModel
            ->where('curp', $curp)
            ->where('escuela_id', $usuario->escuela_id)
            ->first();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        // 3. Verificar que no esté ya vinculado
        $yaVinculado = $this->db->table('usuario_alumno')
            ->where('usuario_id', $usuario->id)
            ->where('alumno_id', $alumno['id'])
            ->countAllResults();

        if ($yaVinculado) {
            return $this->fail('Este alumno ya está vinculado a tu cuenta.', 409);
        }

        // 4. Vincular
        $this->db->table('usuario_alumno')->insert([
            'usuario_id' => $usuario->id,
            'alumno_id'  => $alumno['id'],
            'relacion'   => $json->relacion,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Auditoria::log('crear', 'alumnos',
            "Vinculó alumno: {$alumno['nombre']} (CURP: {$curp})", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Alumno vinculado correctamente.',
            'alumno'  => [
                'nombre'  => $alumno['nombre'],
                'grado'   => $alumno['grado'],
                'grupo'   => $alumno['grupo'],
                'relacion'=> $json->relacion,
            ]
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