<?php
namespace App\Controllers\Api;

use App\Libraries\FirebaseNotification;
use CodeIgniter\RESTful\ResourceController;

class AvisoController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function publicar()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $json  = $this->request->getJSON();
        $rules = [
            'titulo'      => 'required|max_length[150]',
            'descripcion' => 'required',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $avisoId = $this->db->table('avisos')->insert([
            'escuela_id'  => $usuario->escuela_id,
            'autor_id'  => $usuario->id,
            'titulo'      => $json->titulo,
            'descripcion' => $json->descripcion,
            'grado'       => $json->grado    ?? null,
            'grupo'       => $json->grupo    ?? null,
            'vigencia'    => $json->vigencia ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
        ], true);

        // Obtener FCM tokens de los padres según grado/grupo
        $query = $this->db->table('usuarios u')
            ->select('DISTINCT d.fcm_token')
            ->join('usuario_alumno ua', 'ua.usuario_id = u.id')
            ->join('alumnos a',         'a.id = ua.alumno_id')
            ->join('dispositivos d',    'd.usuario_id = u.id')
            ->where('u.escuela_id', $usuario->escuela_id)
            ->where('u.rol', 'padre')
            ->where('u.activo', 1)
            ->where('a.activo', 1)
            ->whereNotNull('d.fcm_token');

        if (!empty($json->grado)) {
            $query->where('a.grado', $json->grado);
        }
        if (!empty($json->grupo)) {
            $query->where('a.grupo', $json->grupo);
        }

        $tokens = array_column($query->get()->getResultArray(), 'd.fcm_token');

        $resultado = ['enviados' => 0, 'errores' => 0];
        if (!empty($tokens)) {
            $firebase  = new FirebaseNotification();
            $resultado = $firebase->enviar(
                $tokens,
                $json->titulo,
                $json->descripcion,
                ['tipo' => 'aviso', 'aviso_id' => (string)$avisoId]
            );
        }

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Aviso publicado.',
            'push'    => $resultado,
        ]);
    }

    public function porEscuela()
    {
        $usuario = $this->request->usuario;

        $avisos = $this->db->table('avisos a')
            ->select('a.id, a.titulo, a.descripcion, a.grado, a.grupo, a.vigencia, a.created_at, u.nombre as autor')
            ->join('usuarios u', 'u.id = a.autor_id')
            ->where('a.escuela_id', $usuario->escuela_id)
            ->orderBy('a.created_at', 'DESC')
            ->get()->getResultArray();

        return $this->respond(['status' => 'ok', 'avisos' => $avisos]);
    }

    public function misAvisos()
    {
        $usuario = $this->request->usuario;

        $query = $this->db->table('avisos a')
            ->select('a.id, a.titulo, a.descripcion, a.grado, a.grupo, a.vigencia, a.created_at, u.nombre as autor')
            ->join('usuarios u',        'u.id = a.autor_id')
            ->join('usuario_alumno ua', 'ua.usuario_id = ' . $usuario->id)
            ->join('alumnos al',        'al.id = ua.alumno_id')
            ->where('a.escuela_id', $usuario->escuela_id)
            ->groupStart()
                ->where('a.grado IS NULL')
                ->orWhere('a.grado = al.grado')
            ->groupEnd()
            ->groupStart()
                ->where('a.grupo IS NULL')
                ->orWhere('a.grupo = al.grupo')
            ->groupEnd()
            ->groupBy('a.id')
            ->orderBy('a.created_at', 'DESC');

        $avisos = $query->get()->getResultArray();

        return $this->respond(['status' => 'ok', 'avisos' => $avisos]);
    }

    public function eliminar(int $id = 0)
    {
        $usuario = $this->request->usuario;

        $aviso = $this->db->table('avisos')
            ->where('id', $id)
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getRowArray();

        if (!$aviso) {
            return $this->failNotFound('Aviso no encontrado.');
        }

        $this->db->table('avisos')->delete(['id' => $id]);

        return $this->respond(['status' => 'ok', 'mensaje' => 'Aviso eliminado.']);
    }
}