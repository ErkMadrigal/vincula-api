<?php
namespace App\Controllers\Api\Admin;

use App\Libraries\Auditoria;
use App\Libraries\FirebaseNotification;
use CodeIgniter\RESTful\ResourceController;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CalificacionController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // POST /api/admin/calificaciones/carga
    // El xlsx debe tener: curp, materia, calificacion, observaciones
    public function carga()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $archivo  = $this->request->getFile('archivo');
        $bimestre = $this->request->getPost('bimestre');
        $ciclo    = $this->request->getPost('ciclo');

        if (!$archivo || !$archivo->isValid()) {
            return $this->fail('Debes subir un archivo xlsx válido.', 400);
        }

        if (!$bimestre || !in_array($bimestre, ['1','2','3','4','5'])) {
            return $this->fail('Bimestre inválido. Debe ser del 1 al 5.', 400);
        }

        if (!$ciclo) {
            return $this->fail('Debes indicar el ciclo escolar. Ej: 2025-2026', 400);
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($archivo->getTempName());
            $hoja        = $spreadsheet->getActiveSheet()->toArray();
        } catch (\Exception $e) {
            return $this->fail('No se pudo leer el archivo: ' . $e->getMessage(), 400);
        }

        array_shift($hoja); // quitar encabezado

        $procesados = [];
        $errores    = [];
        $omitidos   = [];

        // Agrupar filas por CURP para procesar todas las materias juntas
        $porAlumno = [];
        foreach ($hoja as $i => $fila) {
            $fila   = array_map('trim', $fila);
            $linea  = $i + 2;
            [$curp, $materia, $calificacion, $observaciones] = array_pad($fila, 4, null);

            if (empty($curp) || empty($materia) || $calificacion === null) {
                $errores[] = "Fila {$linea}: CURP, materia y calificación son obligatorios.";
                continue;
            }

            $curp = strtoupper($curp);

            if (!is_numeric($calificacion) || $calificacion < 0 || $calificacion > 10) {
                $errores[] = "Fila {$linea}: Calificación inválida para {$curp} en {$materia}.";
                continue;
            }

            $porAlumno[$curp][] = [
                'materia'       => $materia,
                'calificacion'  => round((float)$calificacion, 2),
                'observaciones' => $observaciones ?? null,
                'linea'         => $linea,
            ];
        }

        // Procesar cada alumno
        foreach ($porAlumno as $curp => $materias) {
            // Buscar alumno
            $alumno = $this->db->table('alumnos')
                ->where('curp', $curp)
                ->where('escuela_id', $usuario->escuela_id)
                ->where('activo', 1)
                ->get()->getRowArray();

            if (!$alumno) {
                $errores[] = "CURP {$curp}: Alumno no encontrado en esta escuela.";
                continue;
            }

            // Verificar si ya existe calificación para este bimestre/ciclo
            $existe = $this->db->table('calificaciones')
                ->where('alumno_id', $alumno['id'])
                ->where('bimestre', $bimestre)
                ->where('ciclo', $ciclo)
                ->get()->getRowArray();

            if ($existe) {
                $omitidos[] = "{$alumno['nombre']} ({$curp}) bimestre {$bimestre} ya existe, se omitió.";
                continue;
            }

            // Calcular promedio
            $promedio = round(
                array_sum(array_column($materias, 'calificacion')) / count($materias),
                2
            );

            $this->db->transStart();

            // Insertar calificación
            $this->db->table('calificaciones')->insert([
                'escuela_id' => $usuario->escuela_id,
                'alumno_id'  => $alumno['id'],
                'autor_id'   => $usuario->id,
                'bimestre'   => $bimestre,
                'ciclo'      => $ciclo,
                'promedio'   => $promedio,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $calificacionId = $this->db->insertID();

            // Insertar detalle por materia
            foreach ($materias as $m) {
                $this->db->table('calificacion_materias')->insert([
                    'calificacion_id' => $calificacionId,
                    'materia'         => $m['materia'],
                    'calificacion'    => $m['calificacion'],
                    'observaciones'   => $m['observaciones'],
                ]);
            }

            $this->db->transComplete();

            if ($this->db->transStatus()) {
                $procesados[] = $alumno['nombre'];

                // Push al papá
                $tokens = $this->db->table('dispositivos d')
                    ->select('d.fcm_token')
                    ->join('usuario_alumno ua', 'ua.usuario_id = d.usuario_id')
                    ->where('ua.alumno_id', $alumno['id'])
                    ->where('d.activo', 1)
                    ->get()->getResultArray();

                $fcmTokens = array_column($tokens, 'fcm_token');

                if (!empty($fcmTokens)) {
                    $firebase = new FirebaseNotification();
                    $firebase->enviar(
                        $fcmTokens,
                        "Vincúla — Calificaciones disponibles",
                        "{$alumno['nombre']} ya tiene calificaciones del bimestre {$bimestre}. Promedio: {$promedio}",
                        [
                            'alumno_uuid' => $alumno['uuid'],
                            'bimestre'    => $bimestre,
                            'ciclo'       => $ciclo,
                            'promedio'    => $promedio,
                        ]
                    );
                }
            } else {
                $errores[] = "{$alumno['nombre']} ({$curp}): Error al guardar.";
            }
        }

        Auditoria::log(
            'crear',
            'calificaciones',
            "Carga bimestre {$bimestre} ciclo {$ciclo}: " . count($procesados) . ' alumnos procesados.',
            $usuario
        );

        return $this->respond([
            'status'            => 'ok',
            'bimestre'          => $bimestre,
            'ciclo'             => $ciclo,
            'procesados'        => count($procesados),
            'omitidos'          => count($omitidos),
            'errores'           => count($errores),
            'detalle_procesados'=> $procesados,
            'detalle_omitidos'  => $omitidos,
            'detalle_errores'   => $errores,
        ]);
    }

    // GET /api/admin/calificaciones/alumno/:alumno_uuid
    // Director/admin ve todas las calificaciones de un alumno
    public function porAlumno(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $alumno = $this->db->table('alumnos')
            ->where('uuid', $alumno_uuid)
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        $calificaciones = $this->_obtenerCalificaciones($alumno['id']);

        return $this->respond([
            'status'         => 'ok',
            'alumno'         => $alumno['nombre'],
            'calificaciones' => $calificaciones,
        ]);
    }
}