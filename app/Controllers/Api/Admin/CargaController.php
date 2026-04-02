<?php
namespace App\Controllers\Api\Admin;

use App\Libraries\Auditoria;
use CodeIgniter\RESTful\ResourceController;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CargaController extends ResourceController
{
    protected $format = 'json';

    // ─── POST /api/admin/carga/alumnos ────────────────────────────────────────
    public function alumnos()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'super_admin'])) {
            return $this->fail('No tienes permiso para esta acción.', 403);
        }

        $archivo = $this->request->getFile('archivo');

        if (!$archivo || !$archivo->isValid()) {
            return $this->fail('Debes subir un archivo xlsx válido.', 400);
        }

        if ($archivo->getClientExtension() !== 'xlsx') {
            return $this->fail('Solo se aceptan archivos .xlsx', 400);
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($archivo->getTempName());
            $hoja        = $spreadsheet->getActiveSheet()->toArray();
        } catch (\Exception $e) {
            return $this->fail('No se pudo leer el archivo: ' . $e->getMessage(), 400);
        }

        // Quitar encabezado
        array_shift($hoja);

        $creados  = [];
        $errores  = [];
        $omitidos = [];

        foreach ($hoja as $i => $fila) {
            $fila = array_map('trim', $fila);
            $linea = $i + 2; // número de fila real en el xlsx

            [$curp, $nombre, $grado, $grupo] = array_pad($fila, 4, null);

            // Validaciones básicas
            if (empty($curp) || empty($nombre)) {
                $errores[] = "Fila {$linea}: CURP y nombre son obligatorios.";
                continue;
            }

            $curp = strtoupper($curp);

            if (strlen($curp) !== 18) {
                $errores[] = "Fila {$linea}: CURP inválida ({$curp}).";
                continue;
            }

            // Verificar si el alumno ya existe en esta escuela
            $existeAlumno = $this->db->table('alumnos')
                ->where('curp', $curp)
                ->where('escuela_id', $usuario->escuela_id)
                ->countAllResults();

            if ($existeAlumno) {
                $omitidos[] = "Fila {$linea}: {$nombre} ({$curp}) ya existe, se omitió.";
                continue;
            }

            $uuid     = $this->_generarUuid();
            $password = password_hash(substr($curp, 0, 8), PASSWORD_DEFAULT);

            // Iniciar transacción
            $this->db->transStart();

            // 1. Insertar alumno
            $this->db->table('alumnos')->insert([
                'escuela_id' => $usuario->escuela_id,
                'uuid'       => $uuid,
                'curp'       => $curp,
                'nombre'     => $nombre,
                'grado'      => $grado ?? null,
                'grupo'      => $grupo ?? null,
                'activo'     => 1,
                'pagado'     => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $alumnoId = $this->db->insertID();

            // 2. Insertar usuario (puede que ya exista si el papá tiene otro hijo)
            $usuarioExistente = $this->db->table('usuarios')
                ->where('curp', $curp)
                ->where('escuela_id', $usuario->escuela_id)
                ->get()->getRowArray();

            if ($usuarioExistente) {
                $usuarioId = $usuarioExistente['id'];
            } else {
                $this->db->table('usuarios')->insert([
                    'escuela_id' => $usuario->escuela_id,
                    'curp'       => $curp,
                    'nombre'     => $nombre,
                    'password'   => $password,
                    'rol'        => 'padre',
                    'activo'     => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $usuarioId = $this->db->insertID();
            }

            // 3. Vincular usuario con alumno
            $yaVinculado = $this->db->table('usuario_alumno')
                ->where('usuario_id', $usuarioId)
                ->where('alumno_id', $alumnoId)
                ->countAllResults();

            if (!$yaVinculado) {
                $this->db->table('usuario_alumno')->insert([
                    'usuario_id' => $usuarioId,
                    'alumno_id'  => $alumnoId,
                    'relacion'   => 'padre',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $this->db->transComplete();

            if ($this->db->transStatus()) {
                $creados[] = "{$nombre} ({$curp})";
            } else {
                $errores[] = "Fila {$linea}: Error al guardar {$nombre}.";
            }
        }

        Auditoria::log(
            'crear',
            'carga_alumnos',
            'Carga masiva: ' . count($creados) . ' alumnos creados, ' . count($errores) . ' errores.',
            $usuario
        );

        return $this->respond([
            'status'         => 'ok',
            'creados'        => count($creados),
            'omitidos'       => count($omitidos),
            'errores'        => count($errores),
            'detalle_creados'  => $creados,
            'detalle_omitidos' => $omitidos,
            'detalle_errores'  => $errores,
        ]);
    }

    // ─── POST /api/admin/carga/maestros ───────────────────────────────────────
    public function maestros()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'super_admin'])) {
            return $this->fail('No tienes permiso para esta acción.', 403);
        }

        $archivo = $this->request->getFile('archivo');

        if (!$archivo || !$archivo->isValid()) {
            return $this->fail('Debes subir un archivo xlsx válido.', 400);
        }

        if ($archivo->getClientExtension() !== 'xlsx') {
            return $this->fail('Solo se aceptan archivos .xlsx', 400);
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($archivo->getTempName());
            $hoja        = $spreadsheet->getActiveSheet()->toArray();
        } catch (\Exception $e) {
            return $this->fail('No se pudo leer el archivo: ' . $e->getMessage(), 400);
        }

        array_shift($hoja);

        $creados  = [];
        $errores  = [];
        $omitidos = [];

        foreach ($hoja as $i => $fila) {
            $fila  = array_map('trim', $fila);
            $linea = $i + 2;

            [$curp, $nombre] = array_pad($fila, 2, null);

            if (empty($curp) || empty($nombre)) {
                $errores[] = "Fila {$linea}: CURP y nombre son obligatorios.";
                continue;
            }

            $curp = strtoupper($curp);

            if (strlen($curp) !== 18) {
                $errores[] = "Fila {$linea}: CURP inválida ({$curp}).";
                continue;
            }

            $existe = $this->db->table('usuarios')
                ->where('curp', $curp)
                ->where('escuela_id', $usuario->escuela_id)
                ->countAllResults();

            if ($existe) {
                $omitidos[] = "Fila {$linea}: {$nombre} ({$curp}) ya existe, se omitió.";
                continue;
            }

            $password = password_hash(substr($curp, 0, 8), PASSWORD_DEFAULT);

            $insertado = $this->db->table('usuarios')->insert([
                'escuela_id' => $usuario->escuela_id,
                'curp'       => $curp,
                'nombre'     => $nombre,
                'password'   => $password,
                'rol'        => 'maestro',
                'activo'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($insertado) {
                $creados[] = "{$nombre} ({$curp})";
            } else {
                $errores[] = "Fila {$linea}: Error al guardar {$nombre}.";
            }
        }

        Auditoria::log(
            'crear',
            'carga_maestros',
            'Carga masiva: ' . count($creados) . ' maestros creados, ' . count($errores) . ' errores.',
            $usuario
        );

        return $this->respond([
            'status'           => 'ok',
            'creados'          => count($creados),
            'omitidos'         => count($omitidos),
            'errores'          => count($errores),
            'detalle_creados'  => $creados,
            'detalle_omitidos' => $omitidos,
            'detalle_errores'  => $errores,
        ]);
    }

    // ─── Genera UUID v4 ───────────────────────────────────────────────────────
    private function _generarUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}