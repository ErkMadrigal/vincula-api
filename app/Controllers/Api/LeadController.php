<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

class LeadController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function nuevo()
    {
        $json = $this->request->getJSON();

        $rules = [
            'nombre'   => 'required|max_length[150]',
            'escuela'  => 'required|max_length[200]',
            'telefono' => 'required|max_length[20]',
            'correo'   => 'required|valid_email|max_length[150]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        // Verificar si ya existe ese correo
        $existe = $this->db->table('leads')
            ->where('correo', $json->correo)
            ->countAllResults();

        if ($existe) {
            return $this->fail('Ya tenemos tu solicitud registrada. Te contactaremos pronto.', 409);
        }

        $this->db->table('leads')->insert([
            'nombre'    => $json->nombre,
            'escuela'   => $json->escuela,
            'telefono'  => $json->telefono,
            'correo'    => $json->correo,
            'alumnos'   => $json->alumnos   ?? null,
            'intereses' => $json->intereses ?? null,
            'mensaje'   => $json->mensaje   ?? null,
            'status'    => 'nuevo',
            'created_at'=> date('Y-m-d H:i:s'),
        ]);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => '¡Solicitud recibida! Te contactaremos en menos de 24 horas.',
        ]);
    }

    public function index()
    {
        $leads = $this->db->table('leads')
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        return $this->respond([
            'status' => 'ok',
            'leads'  => $leads,
            'total'  => count($leads),
        ]);
    }

    public function actualizarStatus($id)
    {
        $json = $this->request->getJSON();

        $this->db->table('leads')->update(
            ['status' => $json->status],
            ['id'     => $id]
        );

        return $this->respond(['status' => 'ok']);
    }
}