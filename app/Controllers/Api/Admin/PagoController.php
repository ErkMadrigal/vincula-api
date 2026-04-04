<?php
namespace App\Controllers\Api\Admin;

use App\Libraries\Auditoria;
use CodeIgniter\RESTful\ResourceController;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PagoController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // POST /api/admin/pagos/carga
    public function carga()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
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

        array_shift($hoja); // quitar encabezado

        $activados   = [];
        $bloqueados  = [];
        $errores     = [];
        $omitidos    = [];

        foreach ($hoja as $i => $fila) {
            $fila  = array_map('trim', $fila);
            $linea = $i + 2;

            [$curp, $nombre, $pagado] = array_pad($fila, 3, null);

            if (empty($curp)) {
                $errores[] = "Fila {$linea}: CURP vacía.";
                continue;
            }

            $curp = strtoupper($curp);

            if (!in_array((string)$pagado, ['0', '1'])) {
                $errores[] = "Fila {$linea}: El campo pagado debe ser 0 o 1 ({$curp}).";
                continue;
            }

            // Buscar alumno en la escuela
            $alumno = $this->db->table('alumnos')
                ->where('curp', $curp)
                ->where('escuela_id', $usuario->escuela_id)
                ->get()->getRowArray();

            if (!$alumno) {
                $omitidos[] = "Fila {$linea}: {$curp} no encontrado en esta escuela.";
                continue;
            }

            // Si ya tiene el mismo estado no hacer nada
            if ((int)$alumno['pagado'] === (int)$pagado) {
                $omitidos[] = "{$alumno['nombre']} ya tiene ese estado, se omitió.";
                continue;
            }

            // Actualizar estado de pago en alumno
            $this->db->table('alumnos')->update(
                ['pagado' => (int)$pagado, 'updated_at' => date('Y-m-d H:i:s')],
                ['id'     => $alumno['id']]
            );

            // Actualizar estado del usuario vinculado
            $this->db->table('usuarios')->update(
                ['activo'     => (int)$pagado, 'updated_at' => date('Y-m-d H:i:s')],
                ['curp'       => $curp, 'escuela_id' => $usuario->escuela_id, 'rol' => 'padre']
            );

            if ((int)$pagado === 1) {
                $activados[]  = $alumno['nombre'];
            } else {
                $bloqueados[] = $alumno['nombre'];
            }
        }

        Auditoria::log(
            'editar',
            'pagos',
            'Control de pagos: ' . count($activados) . ' activados, ' . count($bloqueados) . ' bloqueados.',
            $usuario
        );

        return $this->respond([
            'status'             => 'ok',
            'activados'          => count($activados),
            'bloqueados'         => count($bloqueados),
            'omitidos'           => count($omitidos),
            'errores'            => count($errores),
            'detalle_activados'  => $activados,
            'detalle_bloqueados' => $bloqueados,
            'detalle_omitidos'   => $omitidos,
            'detalle_errores'    => $errores,
        ]);
    }

    // GET /api/admin/pagos/estado
    // Ver qué alumnos están al corriente y cuáles no
    public function estado()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $alumnos = $this->db->table('alumnos')
            ->select('uuid, curp, nombre, grado, grupo, pagado')
            ->where('escuela_id', $usuario->escuela_id)
            ->where('activo', 1)
            ->orderBy('pagado', 'ASC') // primero los bloqueados
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        $pagados   = array_filter($alumnos, fn($a) => $a['pagado'] == 1);
        $bloqueados= array_filter($alumnos, fn($a) => $a['pagado'] == 0);

        return $this->respond([
            'status'     => 'ok',
            'total'      => count($alumnos),
            'al_corriente' => count($pagados),
            'bloqueados' => count($bloqueados),
            'alumnos'    => array_values($alumnos),
        ]);
    }

    // POST /api/admin/pagos/toggle/:alumno_uuid
    // Activar o bloquear un alumno individual desde el panel
    public function toggle(string $alumno_uuid = '')
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

        $nuevoEstado = $alumno['pagado'] ? 0 : 1;
        $texto       = $nuevoEstado ? 'activado' : 'bloqueado';

        // Actualizar alumno
        $this->db->table('alumnos')->update(
            ['pagado' => $nuevoEstado, 'updated_at' => date('Y-m-d H:i:s')],
            ['id'     => $alumno['id']]
        );

        // Actualizar usuario
        $this->db->table('usuarios')->update(
            ['activo'     => $nuevoEstado, 'updated_at' => date('Y-m-d H:i:s')],
            ['curp'       => $alumno['curp'], 'escuela_id' => $usuario->escuela_id]
        );

        Auditoria::log(
            'editar',
            'pagos',
            "Alumno {$alumno['nombre']} {$texto} manualmente.",
            $usuario
        );

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => "{$alumno['nombre']} {$texto} correctamente.",
            'pagado'  => $nuevoEstado,
        ]);
    }
}