<?php

namespace App\Controllers\Api;

use App\Libraries\JwtHelper;
use App\Models\UsuarioModel;
use CodeIgniter\RESTful\ResourceController;

class AuthController extends ResourceController
{
    protected $format = 'json';

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
                'id'         => $usuario['id'],
                'nombre'     => $usuario['nombre'],
                'rol'        => $usuario['rol'],
                'escuela_id' => $usuario['escuela_id'],
            ]
        ]);
    }

    public function cambiarPassword()
    {
        $rules = [
            'curp'         => 'required',
            'password_old' => 'required',
            'password_new' => 'required|min_length[8]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $json    = $this->request->getJSON();
        $model   = new UsuarioModel();
        $usuario = $model->findByCurp(strtoupper($json->curp));

        if (!$usuario || !password_verify($json->password_old, $usuario['password'])) {
            return $this->fail('Credenciales incorrectas.', 401);
        }

        $model->update($usuario['id'], [
            'password' => password_hash($json->password_new, PASSWORD_DEFAULT)
        ]);

        return $this->respond(['status' => 'ok', 'mensaje' => 'Contraseña actualizada.']);
    }
}