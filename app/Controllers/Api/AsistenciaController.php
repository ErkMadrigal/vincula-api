<?php
namespace App\Controllers\Api;

use App\Libraries\Auditoria;
use App\Libraries\FirebaseNotification;
use App\Models\AlumnoModel;
use CodeIgniter\RESTful\ResourceController;

class AsistenciaController extends ResourceController
{

    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // POST /api/asistencia/registrar
    // Lo llama el maestro al escanear el QR
    public function registrar()
    {
        $usuario = $this->request->usuario;

        // Solo maestros y admins pueden registrar asistencia
        if (!in_array($usuario->rol, ['maestro', 'admin', 'super_admin'])) {
            return $this->fail('No tienes permiso para registrar asistencia.', 403);
        }

        $rules = [
            'alumno_uuid' => 'required',
            'tipo'        => 'required|in_list[entrada,salida]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $json = $this->request->getJSON();

        // 1. Buscar alumno por UUID dentro de la misma escuela
        $alumnoModel = new AlumnoModel();
        $alumno = $alumnoModel
            ->where('uuid', $json->alumno_uuid)
            ->where('escuela_id', $usuario->escuela_id)
            ->where('activo', 1)
            ->first();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        // 2. Verificar que el alumno esté pagado
        if (!$alumno['pagado']) {
            return $this->fail('El alumno no tiene acceso activo.', 403);
        }
        

        $tz    = new \DateTimeZone('America/Mexico_City');
        $now   = new \DateTime('now', $tz);
        $fecha = $now->format('Y-m-d');
        $hora  = $now->format('H:i:s');

        // 3. Evitar duplicar entrada/salida el mismo día
        $yaRegistrado = $this->db->table('asistencias')
            ->where('alumno_id', $alumno['id'])
            ->where('tipo', $json->tipo)
            ->where('fecha', $fecha)
            ->countAllResults();

        if ($yaRegistrado) {
            return $this->fail("Ya se registró la {$json->tipo} de este alumno hoy.", 409);
        }

        // 4. Registrar asistencia
        $this->db->table('asistencias')->insert([
            'escuela_id' => $usuario->escuela_id,
            'alumno_id'  => $alumno['id'],
            'maestro_id' => $usuario->id,
            'tipo'       => $json->tipo,
            'fecha'      => $fecha,
            'hora'       => $hora,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 5. Obtener FCM tokens de todos los tutores del alumno
        $tokens = $this->db->table('dispositivos d')
            ->select('d.fcm_token')
            ->join('usuario_alumno ua', 'ua.usuario_id = d.usuario_id')
            ->where('ua.alumno_id', $alumno['id'])
            ->where('d.activo', 1)
            ->get()->getResultArray();

        $fcmTokens = array_column($tokens, 'fcm_token');

        // 6. Enviar push a papá y mamá
        if (!empty($fcmTokens)) {
            $tipoTexto = $json->tipo === 'entrada' ? 'entró' : 'salió';
            $firebase  = new FirebaseNotification();
            $firebase->enviar(
                $fcmTokens,
                "Vincúla — {$alumno['nombre']}",
                "{$alumno['nombre']} {$tipoTexto} a las {$hora}",
                [
                    'alumno_uuid' => $alumno['uuid'],
                    'tipo'        => $json->tipo,
                    'hora'        => $hora,
                    'fecha'       => $fecha,
                ]
            );
        }

        // 7. Auditoría
        Auditoria::log(
            'crear',
            'asistencia',
            "Registró {$json->tipo} de {$alumno['nombre']} a las {$hora}",
            $usuario
        );

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => ucfirst($json->tipo) . ' registrada correctamente.',
            'alumno'  => $alumno['nombre'],
            'tipo'    => $json->tipo,
            'hora'    => $hora,
            'fecha'   => $fecha,
        ]);
    }

    // GET /api/asistencia/hoy
    // El maestro ve quién ya entró/salió hoy
    public function hoy()
    {
        $usuario = $this->request->usuario;

        $asistencias = $this->db->table('asistencias a')
            ->select('a.id, al.nombre as alumno, al.grado, al.grupo, a.tipo, a.hora')
            ->join('alumnos al', 'al.id = a.alumno_id')
            ->where('a.escuela_id', $usuario->escuela_id)
            ->where('a.fecha', date('Y-m-d'))
            ->orderBy('a.hora', 'DESC')
            ->get()->getResultArray();

        return $this->respond([
            'status'      => 'ok',
            'fecha'       => date('Y-m-d'),
            'asistencias' => $asistencias,
        ]);
    }

    // GET /api/asistencia/historial/:alumno_uuid
    // El papá ve el historial de su hijo
    public function historial(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;

        // Verificar que el alumno le pertenezca al padre
        $alumno = $this->db->table('alumnos a')
            ->select('a.id, a.nombre')
            ->join('usuario_alumno ua', 'ua.alumno_id = a.id')
            ->where('a.uuid', $alumno_uuid)
            ->where('ua.usuario_id', $usuario->id)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado o no vinculado.');
        }

        $historial = $this->db->table('asistencias')
            ->select('tipo, fecha, hora')
            ->where('alumno_id', $alumno['id'])
            ->orderBy('fecha', 'DESC')
            ->orderBy('hora',  'DESC')
            ->limit(30)
            ->get()->getResultArray();

        return $this->respond([
            'status'   => 'ok',
            'alumno'   => $alumno['nombre'],
            'historial'=> $historial,
        ]);
    }

    // GET /api/asistencia/rango/:alumno_uuid?desde=2026-03-01&hasta=2026-03-31
    public function rango(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;
        $desde   = $this->request->getGet('desde');
        $hasta   = $this->request->getGet('hasta');

        if (!$desde || !$hasta) {
            return $this->fail('Debes enviar los parámetros desde y hasta.', 400);
        }

        // Validar formato de fechas
        if (!strtotime($desde) || !strtotime($hasta)) {
            return $this->fail('Formato de fecha inválido. Usa YYYY-MM-DD.', 400);
        }

        if ($desde > $hasta) {
            return $this->fail('La fecha desde no puede ser mayor que hasta.', 400);
        }

        $alumno = $this->_verificarHijo($alumno_uuid, $usuario->id);
        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado o no vinculado.');
        }

        $registros = $this->_consultarAsistencias($alumno['id'], $desde, $hasta);

        return $this->respond([
            'status'  => 'ok',
            'alumno'  => $alumno['nombre'],
            'desde'   => $desde,
            'hasta'   => $hasta,
            'total'   => count($registros),
            'registros' => $registros,
        ]);
    }

    // GET /api/asistencia/mes/:alumno_uuid
    public function mes(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;
        $alumno  = $this->_verificarHijo($alumno_uuid, $usuario->id);

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado o no vinculado.');
        }

        $desde = date('Y-m-01');           // primer día del mes actual
        $hasta = date('Y-m-t');            // último día del mes actual

        $registros = $this->_consultarAsistencias($alumno['id'], $desde, $hasta);

        return $this->respond([
            'status'    => 'ok',
            'alumno'    => $alumno['nombre'],
            'mes'       => date('F Y'),
            'desde'     => $desde,
            'hasta'     => $hasta,
            'total'     => count($registros),
            'registros' => $registros,
        ]);
    }

    // GET /api/asistencia/semana/:alumno_uuid
    public function semana(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;
        $alumno  = $this->_verificarHijo($alumno_uuid, $usuario->id);

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado o no vinculado.');
        }

        // Lunes de la semana actual
        $desde = date('Y-m-d', strtotime('monday this week'));
        // Domingo de la semana actual
        $hasta = date('Y-m-d', strtotime('sunday this week'));

        $registros = $this->_consultarAsistencias($alumno['id'], $desde, $hasta);

        return $this->respond([
            'status'    => 'ok',
            'alumno'    => $alumno['nombre'],
            'semana'    => 'Semana del ' . $desde . ' al ' . $hasta,
            'desde'     => $desde,
            'hasta'     => $hasta,
            'total'     => count($registros),
            'registros' => $registros,
        ]);
    }

    // ─── Métodos privados reutilizables ───────────────────────────────────────────

    private function _verificarHijo(string $uuid, int $usuarioId): array|null
    {
        return $this->db->table('alumnos a')
            ->select('a.id, a.nombre, a.grado, a.grupo')
            ->join('usuario_alumno ua', 'ua.alumno_id = a.id')
            ->where('a.uuid', $uuid)
            ->where('ua.usuario_id', $usuarioId)
            ->get()->getRowArray() ?: null;
    }

    private function _consultarAsistencias(int $alumnoId, string $desde, string $hasta): array
    {
        return $this->db->table('asistencias')
            ->select('tipo, fecha, hora')
            ->where('alumno_id', $alumnoId)
            ->where('fecha >=', $desde)
            ->where('fecha <=', $hasta)
            ->orderBy('fecha', 'DESC')
            ->orderBy('hora',  'ASC')
            ->get()->getResultArray();
    }


    // GET /api/asistencia/escuela/rango?desde=&hasta=
    public function escuelaRango()
    {
        $usuario = $this->request->usuario;
        $desde   = $this->request->getGet('desde');
        $hasta   = $this->request->getGet('hasta');

        if (!$desde || !$hasta) {
            return $this->fail('Debes enviar desde y hasta.', 400);
        }

        $registros = $this->db->table('asistencias a')
            ->select('a.id, al.nombre as alumno, al.grado, al.grupo, a.tipo, a.fecha, a.hora, u.nombre as maestro')
            ->join('alumnos al',  'al.id = a.alumno_id')
            ->join('usuarios u',  'u.id  = a.maestro_id')
            ->where('a.escuela_id', $usuario->escuela_id)
            ->where('a.fecha >=', $desde)
            ->where('a.fecha <=', $hasta)
            ->orderBy('a.fecha', 'DESC')
            ->orderBy('a.hora',  'DESC')
            ->get()->getResultArray();

        return $this->respond([
            'status'    => 'ok',
            'desde'     => $desde,
            'hasta'     => $hasta,
            'total'     => count($registros),
            'registros' => $registros,
        ]);
    }

}