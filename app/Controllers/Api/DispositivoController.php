<?php
namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

class DispositivoController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    
    public function registrar()
    {
        $usuario = $this->request->usuario;
        $json    = $this->request->getJSON();

        if (empty($json->token)) {
            return $this->fail('Token requerido.', 400);
        }

        $existe = $this->db->table('dispositivos')
            ->where('fcm_token', $json->token)
            ->get()->getRowArray();

        if ($existe) {
            $this->db->table('dispositivos')->update(
                [
                    'usuario_id' => $usuario->id,
                    'activo'     => 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                ['fcm_token' => $json->token]
            );
        } else {
            $this->db->table('dispositivos')->insert([
                'usuario_id' => $usuario->id,
                'fcm_token'  => $json->token,
                'plataforma' => 'android',
                'activo'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->respond(['status' => 'ok', 'mensaje' => 'Token registrado.']);
    }
}