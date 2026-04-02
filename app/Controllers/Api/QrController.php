<?php
namespace App\Controllers\Api;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use CodeIgniter\RESTful\ResourceController;

class QrController extends ResourceController
{
    // GET /api/qr/alumno/:alumno_uuid
    // Genera el QR en PNG directo para imprimir en la credencial
    public function generar(string $alumno_uuid = '')
    {
        $usuario = $this->request->usuario;

        // Solo admin, director y super_admin pueden generar QRs
        if (!in_array($usuario->rol, ['admin', 'director', 'super_admin'])) {
            return $this->fail('No tienes permiso para generar QRs.', 403);
        }

        // Verificar que el alumno pertenezca a la escuela
        $alumno = $this->db->table('alumnos')
            ->where('uuid', $alumno_uuid)
            ->where('escuela_id', $usuario->escuela_id)
            ->where('activo', 1)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        // El QR solo contiene el UUID — nunca la CURP ni datos sensibles
        $qrCode = QrCode::create($alumno['uuid'])
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
            ->setSize(400)
            ->setMargin(20)
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        // Devolver imagen PNG directo
        return $this->response
            ->setStatusCode(200)
            ->setContentType('image/png')
            ->setHeader('Content-Disposition', 'inline; filename="qr_' . $alumno['uuid'] . '.png"')
            ->setBody($result->getString());
    }

    // GET /api/qr/grupo/:grado/:grupo
    // Genera QRs de todo un grupo en un ZIP para imprimir de jalón
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

        // Crear ZIP con todos los QRs
        $zip      = new \ZipArchive();
        $zipNombre= "qr_{$grado}_{$grupo}_" . date('Ymd') . ".zip";
        $zipPath  = WRITEPATH . 'uploads/' . $zipNombre;

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->fail('No se pudo crear el archivo ZIP.', 500);
        }

        $writer = new PngWriter();

        foreach ($alumnos as $alumno) {
            $qrCode = QrCode::create($alumno['uuid'])
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(400)
                ->setMargin(20)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $result   = $writer->write($qrCode);
            $nombreArchivo = $this->_limpiarNombre($alumno['nombre']) . '_' . substr($alumno['uuid'], 0, 8) . '.png';
            $zip->addFromString($nombreArchivo, $result->getString());
        }

        $zip->close();

        // Devolver ZIP y luego borrarlo del servidor
        $contenido = file_get_contents($zipPath);
        unlink($zipPath);

        return $this->response
            ->setStatusCode(200)
            ->setContentType('application/zip')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$zipNombre}\"")
            ->setBody($contenido);
    }

    // GET /api/qr/credencial/:alumno_uuid
    // Devuelve JSON con los datos para armar la credencial en el frontend
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

        // URL pública del QR para embeber en la credencial
        $baseUrl  = base_url();
        $qrUrl    = "{$baseUrl}api/qr/alumno/{$alumno['uuid']}";

        return $this->respond([
            'status'  => 'ok',
            'alumno'  => [
                'nombre'  => $alumno['nombre'],
                'curp'    => $alumno['curp'],
                'grado'   => $alumno['grado'],
                'grupo'   => $alumno['grupo'],
                'escuela' => $alumno['escuela'],
                'uuid'    => $alumno['uuid'],
            ],
            'qr_url'  => $qrUrl,
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