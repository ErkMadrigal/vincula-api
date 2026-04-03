<?php
namespace App\Controllers\Api;

use App\Libraries\Auditoria;
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

    // POST /api/aviso/publicar
    public function publicar()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['maestro', 'admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso para publicar avisos.', 403);
        }

        $rules = [
            'titulo'      => 'required|min_length[3]|max_length[150]',
            'descripcion' => 'required|min_length[5]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $json    = $this->request->getJSON();
        $grado   = $json->grado   ?? null;
        $grupo   = $json->grupo   ?? null;
        $vigencia= $json->vigencia ?? null;

        // Insertar aviso
        $this->db->table('avisos')->insert([
            'escuela_id'  => $usuario->escuela_id,
            'autor_id'    => $usuario->id,
            'titulo'      => $json->titulo,
            'descripcion' => $json->descripcion,
            'grado'       => $grado,
            'grupo'       => $grupo,
            'vigencia'    => $vigencia,
            'activo'      => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $avisoId = $this->db->insertID();

        // Obtener FCM tokens según si el aviso es general o por grado/grupo
        $query = $this->db->table('dispositivos d')
            ->select('d.fcm_token')
            ->join('usuario_alumno ua', 'ua.usuario_id = d.usuario_id')
            ->join('alumnos a', 'a.id = ua.alumno_id')
            ->where('a.escuela_id', $usuario->escuela_id)
            ->where('d.activo', 1);

        if ($grado) $query->where('a.grado', $grado);
        if ($grupo) $query->where('a.grupo', $grupo);

        $tokens    = $query->get()->getResultArray();
        $fcmTokens = array_unique(array_column($tokens, 'fcm_token'));

        // Enviar push
        if (!empty($fcmTokens)) {
            $destino  = $grado ? "Grado {$grado}" . ($grupo ? " Grupo {$grupo}" : '') : 'Toda la escuela';
            $firebase = new FirebaseNotification();
            $firebase->enviar(
                $fcmTokens,
                "Vincúla — {$json->titulo}",
                $json->descripcion,
                [
                    'aviso_id' => $avisoId,
                    'destino'  => $destino,
                ]
            );
        }

        Auditoria::log(
            'crear',
            'avisos',
            "Publicó aviso: {$json->titulo}" . ($grado ? " para {$grado}" . ($grupo ? "/{$grupo}" : '') : ' (toda la escuela)'),
            $usuario
        );

        return $this->respond([
            'status'   => 'ok',
            'mensaje'  => 'Aviso publicado correctamente.',
            'aviso_id' => $avisoId,
            'enviado_a'=> count($fcmTokens) . ' dispositivos',
        ]);
    }

    // GET /api/aviso/mis-avisos
    // El papá ve los avisos vigentes de sus hijos
    public function misAvisos()
    {
        $usuario = $this->request->usuario;

        // Obtener grados y grupos de los hijos del padre
        $hijos = $this->db->table('alumnos a')
            ->select('a.grado, a.grupo')
            ->join('usuario_alumno ua', 'ua.alumno_id = a.id')
            ->where('ua.usuario_id', $usuario->id)
            ->where('a.activo', 1)
            ->get()->getResultArray();

        if (empty($hijos)) {
            return $this->respond(['status' => 'ok', 'avisos' => []]);
        }

        // Avisos generales de la escuela + los del grado/grupo de sus hijos
        $hoy = date('Y-m-d');

        $query = $this->db->table('avisos av')
            ->select('av.id, av.titulo, av.descripcion, av.grado, av.grupo, av.vigencia, av.created_at, u.nombre as autor')
            ->join('usuarios u', 'u.id = av.autor_id')
            ->where('av.escuela_id', $usuario->escuela_id)
            ->where('av.activo', 1)
            ->groupStart()
                // Avisos para toda la escuela
                ->groupStart()
                    ->where('av.grado IS NULL')
                    ->where('av.grupo IS NULL')
                ->groupEnd();

        // Avisos del grado/grupo de cada hijo
        foreach ($hijos as $hijo) {
            $query->orGroupStart()
                ->where('av.grado', $hijo['grado'])
                ->where('av.grupo', $hijo['grupo'])
            ->groupEnd();
        }

        $avisos = $query->groupEnd()
            ->groupStart()
                ->where('av.vigencia IS NULL')
                ->orWhere('av.vigencia >=', $hoy)
            ->groupEnd()
            ->orderBy('av.created_at', 'DESC')
            ->get()->getResultArray();

        return $this->respond([
            'status' => 'ok',
            'total'  => count($avisos),
            'avisos' => $avisos,
        ]);
    }

    // GET /api/aviso/escuela
    // Director/admin ve todos los avisos de su escuela
    public function porEscuela()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['maestro', 'admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $hoy    = date('Y-m-d');
        $avisos = $this->db->table('avisos av')
            ->select('av.id, av.titulo, av.descripcion, av.grado, av.grupo, av.vigencia, av.activo, av.created_at, u.nombre as autor')
            ->join('usuarios u', 'u.id = av.autor_id')
            ->where('av.escuela_id', $usuario->escuela_id)
            ->groupStart()
                ->where('av.vigencia IS NULL')
                ->orWhere('av.vigencia >=', $hoy)
            ->groupEnd()
            ->orderBy('av.created_at', 'DESC')
            ->get()->getResultArray();

        return $this->respond([
            'status' => 'ok',
            'total'  => count($avisos),
            'avisos' => $avisos,
        ]);
    }

    // DELETE /api/aviso/eliminar/:id
    // Solo el autor o director/admin puede eliminar
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

        // Solo el autor o director/admin puede eliminar
        $puedeEliminar = $aviso['autor_id'] == $usuario->id
            || in_array($usuario->rol, ['admin', 'director', 'super_admin']);

        if (!$puedeEliminar) {
            return $this->fail('No tienes permiso para eliminar este aviso.', 403);
        }

        // Soft delete — solo desactiva
        $this->db->table('avisos')->update(
            ['activo' => 0, 'updated_at' => date('Y-m-d H:i:s')],
            ['id'     => $id]
        );

        Auditoria::log('eliminar', 'avisos',
            "Eliminó aviso ID: {$id} — {$aviso['titulo']}", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Aviso eliminado correctamente.',
        ]);
    }
}