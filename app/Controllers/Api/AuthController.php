<?php

namespace App\Controllers\Api;

use App\Libraries\JwtHelper;
use App\Models\UsuarioModel;
use CodeIgniter\RESTful\ResourceController;

class AuthController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }


    public function login()
    {
        $rules = [
            'curp'     => 'required|min_length[18]|max_length[18]',
            'password' => 'required|min_length[8]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $curp     = strtoupper($this->request->getJSON()->curp);
        $password = $this->request->getJSON()->password;

        $model   = new UsuarioModel();
        $usuario = $model->findByCurp($curp);

        if (!$usuario) {
            return $this->failNotFound('Usuario no encontrado.');
        }

        if (!password_verify($password, $usuario['password'])) {
            return $this->fail('Contraseña incorrecta.', 401);
        }

        // Generar token JWT
        $token = JwtHelper::generate([
            'id'         => $usuario['id'],
            'escuela_id' => $usuario['escuela_id'],
            'rol'        => $usuario['rol'],
            'nombre'     => $usuario['nombre'],
            'curp'        => $usuario['curp'],
        ]);

        // Actualizar FCM token si viene en el request
        $fcmToken = $this->request->getJSON()->fcm_token ?? null;
        if ($fcmToken) {
            $model->update($usuario['id'], ['fcm_token' => $fcmToken]);
        }

        return $this->respond([
        'status'  => 'ok',
        'token'   => $token,
        'usuario' => [
            'id'               => $usuario['id'],
            'nombre'           => $usuario['nombre'],
            'rol'              => $usuario['rol'],
            'escuela_id'       => $usuario['escuela_id'],
            'password_changed' => (bool)$usuario['password_changed'],
            'curp'            => $usuario['curp'],
        ]
    ]);
    }

    public function cambiarPassword()
    {
        $rules = [
            'id'           => 'required',
            'password_old' => 'required',
            'password_new' => 'required|min_length[8]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $json = $this->request->getJSON();

        $usuario = $this->db->table('usuarios')
            ->where('id', $json->id)
            ->get()->getRowArray();

        if (!$usuario || !password_verify($json->password_old, $usuario['password'])) {
            return $this->fail('Credenciales incorrectas.', 401);
        }

        $this->db->table('usuarios')->update(
            [
                'password'         => password_hash($json->password_new, PASSWORD_DEFAULT),
                'password_changed' => 1,
            ],
            ['id' => $usuario['id']]
        );

        return $this->respond(['status' => 'ok', 'mensaje' => 'Contraseña actualizada.']);
    }

    // GET /api/auth/perfil
    public function perfil()
    {
        $usuario = $this->request->usuario;

        $datos = $this->db->table('usuarios u')
            ->select('u.id, u.curp, u.nombre, u.rol, u.activo, u.created_at, e.nombre as escuela')
            ->join('escuelas e', 'e.id = u.escuela_id', 'left')
            ->where('u.id', $usuario->id)
            ->get()->getRowArray();

        $auditoria = $this->db->table('auditoria')
            ->where('usuario_id', $usuario->id)
            ->orderBy('created_at', 'DESC')
            ->limit(20)
            ->get()->getResultArray();

        return $this->respond([
            'status'    => 'ok',
            'usuario'   => $datos,
            'auditoria' => $auditoria,
        ]);
    }

    // POST /api/auth/foto
    public function subirFoto()
    {
        $usuario = $this->request->usuario;
        $foto    = $this->request->getFile('foto');

        if (!$foto || !$foto->isValid()) {
            return $this->fail('Foto inválida.', 400);
        }

        if (!in_array(strtolower($foto->getClientExtension()), ['jpg','jpeg','png'])) {
            return $this->fail('Solo jpg o png.', 400);
        }

        if ($foto->getSize() > 3 * 1024 * 1024) {
            return $this->fail('La foto no puede pesar más de 3MB.', 400);
        }

        $nombre = 'perfil_' . $usuario->id . '_' . time() . '.' . $foto->getClientExtension();
        $foto->move(WRITEPATH . 'uploads/perfiles', $nombre);
        $ruta = 'uploads/perfiles/' . $nombre;

        $this->db->table('usuarios')->update(
            ['foto' => $ruta],
            ['id'   => $usuario->id]
        );

        return $this->respond([
            'status' => 'ok',
            'foto'   => $ruta,
        ]);
    }
}