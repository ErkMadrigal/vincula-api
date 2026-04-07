<?php
namespace App\Controllers\Api;

use App\Libraries\Auditoria;
use App\Libraries\FirebaseNotification;
use App\Models\AlumnoModel;
use CodeIgniter\RESTful\ResourceController;

class IncidenciaController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // POST /api/incidencia/registrar
    public function registrar()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['maestro', 'admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso para registrar incidencias.', 403);
        }

        $rules = [
            'alumno_uuid' => 'required',
            'tipo'        => 'required|in_list[pelea,falta,conducta,accidente,otro]',
            'gravedad'    => 'required|in_list[leve,moderada,grave]',
            'descripcion' => 'required|min_length[10]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $alumnoModel = new AlumnoModel();
        $alumno = $alumnoModel
            ->where('uuid', $this->request->getPost('alumno_uuid'))
            ->where('escuela_id', $usuario->escuela_id)
            ->where('activo', 1)
            ->first();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        $fotoPath = null;
        $foto     = $this->request->getFile('foto');

        if ($foto && $foto->isValid() && !$foto->hasMoved()) {
            if (!in_array(strtolower($foto->getClientExtension()), ['jpg', 'jpeg', 'png'])) {
                return $this->fail('Solo se permiten imágenes jpg o png.', 400);
            }
            if ($foto->getSize() > 5 * 1024 * 1024) {
                return $this->fail('La imagen no puede pesar más de 5MB.', 400);
            }
            $nombreFoto = $alumno['uuid'] . '_' . time() . '.' . $foto->getClientExtension();
            $foto->move(WRITEPATH . 'uploads/incidencias', $nombreFoto);
            $fotoPath = 'uploads/incidencias/' . $nombreFoto;
        }

        $this->db->table('incidencias')->insert([
            'escuela_id'  => $usuario->escuela_id,
            'alumno_id'   => $alumno['id'],
            'maestro_id'  => $usuario->id,
            'tipo'        => $this->request->getPost('tipo'),
            'gravedad'    => $this->request->getPost('gravedad'),
            'descripcion' => $this->request->getPost('descripcion'),
            'foto'        => $fotoPath,
            'acuse'       => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $incidenciaId = $this->db->insertID();

        // Notificación push a todos los tutores del alumno
        $tokens = $this->db->table('dispositivos d')
            ->select('d.fcm_token')
            ->join('usuario_alumno ua', 'ua.usuario_id = d.usuario_id')
            ->where('ua.alumno_id', $alumno['id'])
            ->where('d.activo', 1)
            ->get()->getResultArray();

        $fcmTokens = array_column($tokens, 'fcm_token');

        if (!empty($fcmTokens)) {
            $gravedad = $this->request->getPost('gravedad');
            $tipo     = $this->request->getPost('tipo');

            $firebase = new FirebaseNotification();
            $firebase->enviar(
                $fcmTokens,
                "Vincúla — Incidencia {$gravedad}",
                "{$alumno['nombre']} tuvo una incidencia de tipo {$tipo}.",
                [
                    'incidencia_id' => (string)$incidenciaId,
                    'alumno_uuid'   => $alumno['uuid'],
                    'tipo'          => $tipo,
                    'gravedad'      => $gravedad,
                ]
            );
        }

        Auditoria::log(
            'crear',
            'incidencias',
            "Registró incidencia {$this->request->getPost('tipo')} para {$alumno['nombre']}",
            $usuario
        );

        return $this->respond([
            'status'        => 'ok',
            'mensaje'       => 'Incidencia registrada correctamente.',
            'incidencia_id' => $incidenciaId,
        ]);
    }

    // GET /api/incidencia/alumno/:alumno_uuid
    public function porAlumno(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol === 'padre') {
            $alumno = $this->db->table('alumnos a')
                ->select('a.id, a.nombre')
                ->join('usuario_alumno ua', 'ua.alumno_id = a.id')
                ->where('a.uuid', $alumno_uuid)
                ->where('ua.usuario_id', $usuario->id)
                ->get()->getRowArray();

            if (!$alumno) {
                return $this->failNotFound('Alumno no encontrado o no vinculado.');
            }
        } else {
            $alumno = $this->db->table('alumnos')
                ->select('id, nombre')
                ->where('uuid', $alumno_uuid)
                ->where('escuela_id', $usuario->escuela_id)
                ->get()->getRowArray();

            if (!$alumno) {
                return $this->failNotFound('Alumno no encontrado.');
            }
        }

        $incidencias = $this->db->table('incidencias i')
            ->select('i.id, i.tipo, i.gravedad, i.descripcion, i.foto, i.acuse, i.acuse_at, i.created_at, u.nombre as maestro')
            ->join('usuarios u', 'u.id = i.maestro_id')
            ->where('i.alumno_id', $alumno['id'])
            ->orderBy('i.created_at', 'DESC')
            ->get()->getResultArray();

        return $this->respond([
            'status'      => 'ok',
            'alumno'      => $alumno['nombre'],
            'incidencias' => $incidencias,
        ]);
    }

    // GET /api/incidencia/escuela
    public function porEscuela()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $gravedad = $this->request->getGet('gravedad');
        $desde    = $this->request->getGet('desde');
        $hasta    = $this->request->getGet('hasta');

        $query = $this->db->table('incidencias i')
            ->select('i.id, i.tipo, i.gravedad, i.descripcion, i.foto, i.acuse, i.created_at,
                      al.nombre as alumno, al.grado, al.grupo,
                      u.nombre as maestro')
            ->join('alumnos al', 'al.id = i.alumno_id')
            ->join('usuarios u', 'u.id  = i.maestro_id')
            ->where('i.escuela_id', $usuario->escuela_id)
            ->orderBy('i.created_at', 'DESC');

        if ($gravedad) $query->where('i.gravedad', $gravedad);
        if ($desde)    $query->where('i.created_at >=', $desde . ' 00:00:00');
        if ($hasta)    $query->where('i.created_at <=', $hasta . ' 23:59:59');

        $incidencias = $query->get()->getResultArray();

        return $this->respond([
            'status'      => 'ok',
            'total'       => count($incidencias),
            'incidencias' => $incidencias,
        ]);
    }

    // POST /api/incidencia/acuse/:id
    public function acuse(int $id = 0)
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'padre') {
            return $this->fail('Solo los padres pueden firmar el acuse.', 403);
        }

        $incidencia = $this->db->table('incidencias i')
            ->select('i.id, i.acuse, i.alumno_id')
            ->join('usuario_alumno ua', 'ua.alumno_id = i.alumno_id')
            ->where('i.id', $id)
            ->where('ua.usuario_id', $usuario->id)
            ->get()->getRowArray();

        if (!$incidencia) {
            return $this->failNotFound('Incidencia no encontrada.');
        }

        if ($incidencia['acuse']) {
            return $this->fail('Ya firmaste el acuse de esta incidencia.', 409);
        }

        $this->db->table('incidencias')->update(
            ['acuse' => 1, 'acuse_at' => date('Y-m-d H:i:s')],
            ['id'    => $id]
        );

        Auditoria::log('editar', 'incidencias',
            "Firmó acuse de incidencia ID: {$id}", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Acuse registrado correctamente.',
        ]);
    }
}