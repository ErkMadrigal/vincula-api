<?php
namespace App\Controllers\Api\Admin;

use App\Libraries\Auditoria;
use CodeIgniter\RESTful\ResourceController;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
    // POST /api/admin/pagos/carga
    public function carga()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $archivo = $this->request->getFile('archivo');
        if (!$archivo || !$archivo->isValid()) {
            return $this->fail('Archivo inválido.', 400);
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($archivo->getTempName());
        $filas       = $spreadsheet->getActiveSheet()->toArray();
        array_shift($filas); // quitar encabezado

        $activados  = 0;
        $bloqueados = 0;
        $errores    = [];

        foreach ($filas as $i => $fila) {
            $uuid   = trim($fila[0] ?? '');
            $pagado = intval($fila[2] ?? 0); // columna C = pagado

            if (!$uuid) continue;

            $alumno = $this->db->table('alumnos')
                ->where('uuid', $uuid)
                ->where('escuela_id', $usuario->escuela_id)
                ->where('activo', 1)
                ->get()->getRowArray();

            if (!$alumno) {
                $errores[] = "Fila " . ($i + 2) . ": UUID no encontrado — $uuid";
                continue;
            }

            $this->db->table('alumnos')->update(
                ['pagado' => $pagado ? 1 : 0],
                ['uuid'   => $uuid, 'escuela_id' => $usuario->escuela_id]
            );

            $pagado ? $activados++ : $bloqueados++;
        }

        return $this->respond([
            'status'    => 'ok',
            'activados' => $activados,
            'bloqueados'=> $bloqueados,
            'errores'   => $errores,
            'mensaje'   => "Procesados: $activados activados, $bloqueados bloqueados.",
        ]);
    }

    // GET /api/admin/pagos/exportar?estado=0
    public function exportar()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $estado = $this->request->getGet('estado'); // 0=sin pago, 1=pagados, null=todos

        $query = $this->db->table('alumnos')
            ->where('escuela_id', $usuario->escuela_id)
            ->where('activo', 1)
            ->orderBy('grado', 'ASC')
            ->orderBy('grupo', 'ASC')
            ->orderBy('nombre', 'ASC');

        if ($estado !== null && $estado !== '') {
            $query->where('pagado', intval($estado));
        }

        $alumnos = $query->get()->getResultArray();

        // Generar xlsx
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        // Encabezados
        $sheet->setCellValue('A1', 'uuid');
        $sheet->setCellValue('B1', 'nombre');
        $sheet->setCellValue('C1', 'pagado');
        $sheet->setCellValue('D1', 'grado');
        $sheet->setCellValue('E1', 'grupo');

        // Estilo encabezado
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle('A1:E1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('6B4FBB');
        $sheet->getStyle('A1:E1')->getFont()->getColor()->setRGB('FFFFFF');

        // Datos
        foreach ($alumnos as $i => $a) {
            $row = $i + 2;
            $sheet->setCellValue("A{$row}", $a['uuid']);
            $sheet->setCellValue("B{$row}", $a['nombre']);
            $sheet->setCellValue("C{$row}", (int)$a['pagado']);
            $sheet->setCellValue("D{$row}", $a['grado']);
            $sheet->setCellValue("E{$row}", $a['grupo']);

            // Color por estado
            $color = $a['pagado'] ? 'E1F5EE' : 'FCEBEB';
            $sheet->getStyle("A{$row}:E{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB($color);
        }

        // Autowidth
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'pagos_' . date('Ymd_His') . '.xlsx';
        $path     = WRITEPATH . 'uploads/' . $filename;
        $writer->save($path);

        $contenido = file_get_contents($path);
        unlink($path);

        return $this->response
            ->setStatusCode(200)
            ->setContentType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->setBody($contenido);
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