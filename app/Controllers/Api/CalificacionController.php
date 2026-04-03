<?php
namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

class CalificacionController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // GET /api/calificacion/hijo/:alumno_uuid
    public function porHijo(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;

        // Verificar que sea su hijo
        $alumno = $this->db->table('alumnos a')
            ->select('a.id, a.nombre, a.grado, a.grupo')
            ->join('usuario_alumno ua', 'ua.alumno_id = a.id')
            ->where('a.uuid', $alumno_uuid)
            ->where('ua.usuario_id', $usuario->id)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado o no vinculado.');
        }

        $calificaciones = $this->_obtenerCalificaciones($alumno['id']);

        return $this->respond([
            'status'         => 'ok',
            'alumno'         => $alumno['nombre'],
            'grado'          => $alumno['grado'],
            'grupo'          => $alumno['grupo'],
            'calificaciones' => $calificaciones,
        ]);
    }

    // GET /api/calificacion/hijo/:alumno_uuid/bimestre/:bimestre
    public function porBimestre(string $alumno_uuid = '', int $bimestre = 0)
    {
        $usuario = $this->request->usuario;

        $alumno = $this->db->table('alumnos a')
            ->select('a.id, a.nombre')
            ->join('usuario_alumno ua', 'ua.alumno_id = a.id')
            ->where('a.uuid', $alumno_uuid)
            ->where('ua.usuario_id', $usuario->id)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado o no vinculado.');
        }

        $calificacion = $this->db->table('calificaciones c')
            ->select('c.id, c.bimestre, c.ciclo, c.promedio, c.created_at')
            ->where('c.alumno_id', $alumno['id'])
            ->where('c.bimestre', $bimestre)
            ->get()->getRowArray();

        if (!$calificacion) {
            return $this->failNotFound("No hay calificaciones para el bimestre {$bimestre}.");
        }

        $materias = $this->db->table('calificacion_materias')
            ->where('calificacion_id', $calificacion['id'])
            ->orderBy('materia', 'ASC')
            ->get()->getResultArray();

        return $this->respond([
            'status'      => 'ok',
            'alumno'      => $alumno['nombre'],
            'bimestre'    => $calificacion['bimestre'],
            'ciclo'       => $calificacion['ciclo'],
            'promedio'    => $calificacion['promedio'],
            'materias'    => $materias,
        ]);
    }

    protected function _obtenerCalificaciones(int $alumnoId): array
    {
        $calificaciones = $this->db->table('calificaciones c')
            ->select('c.id, c.bimestre, c.ciclo, c.promedio, c.created_at')
            ->where('c.alumno_id', $alumnoId)
            ->orderBy('c.ciclo', 'DESC')
            ->orderBy('c.bimestre', 'ASC')
            ->get()->getResultArray();

        foreach ($calificaciones as &$cal) {
            $cal['materias'] = $this->db->table('calificacion_materias')
                ->where('calificacion_id', $cal['id'])
                ->orderBy('materia', 'ASC')
                ->get()->getResultArray();
        }

        return $calificaciones;
    }
}