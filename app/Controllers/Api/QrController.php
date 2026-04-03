<?php
namespace App\Controllers\Api;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use CodeIgniter\RESTful\ResourceController;

class QrController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // GET /api/qr/alumno/:alumno_uuid
    public function generar(string $alumno_uuid = '')
    {
        $token = $this->request->getGet('token');

        if (!$token) {
            return $this->fail('Token requerido.', 401);
        }

        $payload = \App\Libraries\JwtHelper::validate($token);

        if (!$payload) {
            return $this->fail('Token inválido o expirado.', 401);
        }

        if (!in_array($payload->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $alumno = $this->db->table('alumnos')
            ->where('uuid', $alumno_uuid)
            ->where('escuela_id', $payload->escuela_id)
            ->where('activo', 1)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        $qrCode = new QrCode(
            data: $alumno['uuid'],
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 400,
            margin: 20,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255),
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return $this->response
            ->setStatusCode(200)
            ->setContentType('image/png')
            ->setHeader('Content-Disposition', 'inline; filename="qr_' . $alumno['uuid'] . '.png"')
            ->setBody($result->getString());
    }

    // GET /api/qr/grupo/:grado/:grupo
    public function porGrupo(string $grado = '', string $grupo = '')
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $alumnos = $this->db->table('alumnos')
            ->where('escuela_id', $usuario->escuela_id)
            ->where('grado', $grado)
            ->where('grupo', $grupo)
            ->where('activo', 1)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        if (empty($alumnos)) {
            return $this->failNotFound("No hay alumnos en {$grado} grupo {$grupo}.");
        }

        $zip       = new \ZipArchive();
        $zipNombre = "qr_{$grado}_{$grupo}_" . date('Ymd') . ".zip";
        $zipPath   = WRITEPATH . 'uploads/' . $zipNombre;

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->fail('No se pudo crear el archivo ZIP.', 500);
        }

        foreach ($alumnos as $alumno) {
            $result = Builder::create()
                ->writer(new PngWriter())
                ->data($alumno['uuid'])
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(400)
                ->margin(20)
                ->foregroundColor(new Color(0, 0, 0))
                ->backgroundColor(new Color(255, 255, 255))
                ->build();

            $nombreArchivo = $this->_limpiarNombre($alumno['nombre']) . '_' . substr($alumno['uuid'], 0, 8) . '.png';
            $zip->addFromString($nombreArchivo, $result->getString());
        }

        $zip->close();

        $contenido = file_get_contents($zipPath);
        unlink($zipPath);

        return $this->response
            ->setStatusCode(200)
            ->setContentType('application/zip')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$zipNombre}\"")
            ->setBody($contenido);
    }

    // GET /api/qr/credencial/:alumno_uuid
    public function credencial(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $alumno = $this->db->table('alumnos a')
            ->select('a.uuid, a.nombre, a.grado, a.grupo, a.curp, e.nombre as escuela')
            ->join('escuelas e', 'e.id = a.escuela_id')
            ->where('a.uuid', $alumno_uuid)
            ->where('a.escuela_id', $usuario->escuela_id)
            ->where('a.activo', 1)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        $token  = $this->request->getHeaderLine('Authorization');
        $token  = str_replace('Bearer ', '', $token);
        $qrUrl  = base_url("api/qr/alumno/{$alumno['uuid']}?token={$token}");

        return $this->respond([
            'status' => 'ok',
            'alumno' => [
                'nombre'  => $alumno['nombre'],
                'curp'    => $alumno['curp'],
                'grado'   => $alumno['grado'],
                'grupo'   => $alumno['grupo'],
                'escuela' => $alumno['escuela'],
                'uuid'    => $alumno['uuid'],
            ],
            'qr_url' => $qrUrl,
        ]);
    }

    private function _limpiarNombre(string $nombre): string
    {
        $nombre = strtolower($nombre);
        $nombre = str_replace(
            ['á','é','í','ó','ú','ü','ñ',' '],
            ['a','e','i','o','u','u','n','_'],
            $nombre
        );
        return preg_replace('/[^a-z0-9_]/', '', $nombre);
    }
}