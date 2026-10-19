<?php
namespace App\Controllers\Api\Admin;

use App\Libraries\Auditoria;
use CodeIgniter\RESTful\ResourceController;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Actualización masiva de alumnos desde xlsx.
 *
 * Columnas (en cualquier orden, la 1a fila es el encabezado):
 *   CURP | APELLIDOS | NOMBRES | GRADO | GRUPO
 *
 * - La CURP identifica al alumno dentro de la escuela.
 * - Si existe: actualiza nombre, grado y grupo (solo si cambiaron).
 * - Si no existe: lo inserta (y crea su usuario de padre, igual que la carga normal).
 * - Si el grado/grupo no existe para el turno elegido, lo crea.
 *
 * POST /api/admin/carga/alumnos-actualizar
 *   archivo  (xlsx)
 *   turno    matutino | vespertino
 *   preview  1 = solo revisar, no guarda nada | 0 = aplicar
 */
class ActualizacionAlumnosController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    private const COLUMNAS = ['CURP', 'APELLIDOS', 'NOMBRES', 'GRADO', 'GRUPO'];
    private const MAX_DETALLE = 1000; // máximo de filas de detalle que se regresan por lista

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function procesar()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['admin', 'super_admin', 'director'])) {
            return $this->fail('No tienes permiso para esta acción.', 403);
        }

        set_time_limit(600);
        ini_set('memory_limit', '256M');

        $turno   = $this->request->getPost('turno');
        $preview = $this->request->getPost('preview') !== '0';

        if (!in_array($turno, ['matutino', 'vespertino'], true)) {
            return $this->fail('Selecciona el turno: matutino o vespertino.', 400);
        }

        $archivo = $this->request->getFile('archivo');
        if (!$archivo || !$archivo->isValid()) {
            return $this->fail('Debes subir un archivo xlsx válido.', 400);
        }
        if (strtolower($archivo->getClientExtension()) !== 'xlsx') {
            return $this->fail('Solo se aceptan archivos .xlsx', 400);
        }

        // ─── 1. Leer archivo ─────────────────────────────────────────────────
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $hoja = $reader->load($archivo->getTempName())->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return $this->fail('No se pudo leer el archivo: ' . $e->getMessage(), 400);
        }

        if (count($hoja) < 2) {
            return $this->fail('El archivo no tiene filas de alumnos.', 400);
        }

        // ─── 2. Encabezados (en cualquier orden, sin importar acentos/mayúsculas) ─
        $encabezado = array_map(fn($h) => $this->_normalizarEncabezado($h), $hoja[0]);
        $alias = ['NOMBRE' => 'NOMBRES', 'APELLIDO' => 'APELLIDOS'];
        $idx = [];
        foreach ($encabezado as $i => $h) {
            $h = $alias[$h] ?? $h;
            if (in_array($h, self::COLUMNAS, true) && !isset($idx[$h])) {
                $idx[$h] = $i;
            }
        }
        $faltan = array_values(array_diff(self::COLUMNAS, array_keys($idx)));
        if ($faltan) {
            return $this->fail('Faltan columnas en el archivo: ' . implode(', ', $faltan)
                . '. Deben ser: ' . implode(', ', self::COLUMNAS), 400);
        }

        // ─── 3. Datos actuales de la escuela ────────────────────────────────────
        $existentes = [];
        foreach ($this->db->table('alumnos')
            ->select('id, curp, nombre, grado, grupo, activo')
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getResultArray() as $a) {
            $existentes[strtoupper(trim($a['curp']))] = $a;
        }

        $gruposExistentes = [];
        foreach ($this->db->table('grados_grupos')
            ->select('grado, grupo')
            ->where('escuela_id', $usuario->escuela_id)
            ->where('turno', $turno)
            ->get()->getResultArray() as $g) {
            $gruposExistentes[$this->_claveGrupo($g['grado'], $g['grupo'])] = true;
        }

        // ─── 4. Analizar fila por fila ──────────────────────────────────────────
        $nuevos = $actualizados = $sinCambios = $errores = $gruposNuevos = [];
        $vistos = []; // curp → fila (duplicados dentro del archivo)

        for ($r = 1; $r < count($hoja); $r++) {
            $fila  = $hoja[$r];
            $linea = $r + 1;

            $curp      = strtoupper(preg_replace('/\s+/', '', (string) ($fila[$idx['CURP']] ?? '')));
            $apellidos = $this->_limpiarTexto($fila[$idx['APELLIDOS']] ?? '');
            $nombres   = $this->_limpiarTexto($fila[$idx['NOMBRES']] ?? '');
            $grado     = $this->_normalizarGrado($fila[$idx['GRADO']] ?? '');
            $grupo     = $this->_limpiarTexto($fila[$idx['GRUPO']] ?? '');

            // fila vacía
            if ($curp === '' && $apellidos === '' && $nombres === '' && $grado === '' && $grupo === '') {
                continue;
            }

            if (!preg_match('/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/', $curp)) {
                $errores[] = ['fila' => $linea, 'curp' => $curp, 'mensaje' => 'CURP inválida.'];
                continue;
            }
            if (isset($vistos[$curp])) {
                $errores[] = ['fila' => $linea, 'curp' => $curp, 'mensaje' => "CURP repetida en el archivo (ya viene en la fila {$vistos[$curp]})."];
                continue;
            }
            $vistos[$curp] = $linea;

            if ($nombres === '' || $apellidos === '') {
                $errores[] = ['fila' => $linea, 'curp' => $curp, 'mensaje' => 'Faltan nombres o apellidos.'];
                continue;
            }
            if ($grado === '' || $grupo === '') {
                $errores[] = ['fila' => $linea, 'curp' => $curp, 'mensaje' => 'Faltan grado o grupo.'];
                continue;
            }
            if (mb_strlen($grado) > 20 || mb_strlen($grupo) > 10) {
                $errores[] = ['fila' => $linea, 'curp' => $curp, 'mensaje' => 'Grado (máx. 20) o grupo (máx. 10) demasiado largo.'];
                continue;
            }

            $nombre = mb_substr(trim("{$nombres} {$apellidos}"), 0, 100);

            // grupo nuevo para este turno
            $claveGrupo = $this->_claveGrupo($grado, $grupo);
            if (!isset($gruposExistentes[$claveGrupo])) {
                $gruposExistentes[$claveGrupo] = true;
                $gruposNuevos[] = ['grado' => $grado, 'grupo' => $grupo, 'turno' => $turno];
            }

            if (isset($existentes[$curp])) {
                $actual  = $existentes[$curp];
                $cambios = [];
                if ($this->_distinto($actual['nombre'], $nombre)) $cambios[] = ['campo' => 'nombre', 'antes' => $actual['nombre'], 'despues' => $nombre];
                if ($this->_distinto($actual['grado'], $grado))   $cambios[] = ['campo' => 'grado',  'antes' => $actual['grado'],  'despues' => $grado];
                if ($this->_distinto($actual['grupo'], $grupo))   $cambios[] = ['campo' => 'grupo',  'antes' => $actual['grupo'],  'despues' => $grupo];
                if (!(int) $actual['activo'])                     $cambios[] = ['campo' => 'activo', 'antes' => 'inactivo', 'despues' => 'activo'];

                if ($cambios) {
                    $actualizados[] = [
                        'fila' => $linea, 'curp' => $curp, 'id' => (int) $actual['id'],
                        'nombre' => $nombre, 'nombre_anterior' => $actual['nombre'],
                        'grado' => $grado, 'grupo' => $grupo, 'cambios' => $cambios,
                    ];
                } else {
                    $sinCambios[] = ['fila' => $linea, 'curp' => $curp, 'nombre' => $nombre];
                }
            } else {
                $nuevos[] = ['fila' => $linea, 'curp' => $curp, 'nombre' => $nombre, 'grado' => $grado, 'grupo' => $grupo];
            }
        }

        $resumen = [
            'total'         => count($nuevos) + count($actualizados) + count($sinCambios) + count($errores),
            'nuevos'        => count($nuevos),
            'actualizados'  => count($actualizados),
            'sin_cambios'   => count($sinCambios),
            'errores'       => count($errores),
            'grupos_nuevos' => count($gruposNuevos),
        ];

        $respuesta = fn(bool $esPreview) => [
            'status'        => 'ok',
            'preview'       => $esPreview,
            'turno'         => $turno,
            'resumen'       => $resumen,
            'nuevos'        => array_slice($nuevos, 0, self::MAX_DETALLE),
            'actualizados'  => array_map(fn($a) => array_diff_key($a, ['id' => 1, 'nombre_anterior' => 1]), array_slice($actualizados, 0, self::MAX_DETALLE)),
            'errores'       => array_slice($errores, 0, self::MAX_DETALLE),
            'grupos_nuevos' => $gruposNuevos,
        ];

        if ($preview) {
            return $this->respond($respuesta(true));
        }

        if (!$nuevos && !$actualizados && !$gruposNuevos) {
            return $this->respond($respuesta(false) + ['mensaje' => 'No había nada que actualizar.']);
        }

        // ─── 5. Aplicar (todo o nada) ───────────────────────────────────────────
        $ahora = date('Y-m-d H:i:s');
        $this->db->transBegin();

        try {
            // 5.1 Grupos
            foreach ($gruposNuevos as $g) {
                $this->db->table('grados_grupos')->insert([
                    'escuela_id' => $usuario->escuela_id,
                    'grado'      => $g['grado'],
                    'grupo'      => $g['grupo'],
                    'turno'      => $turno,
                    'activo'     => 1,
                    'created_at' => $ahora,
                ]);
            }

            // 5.2 Actualizaciones
            foreach ($actualizados as $a) {
                $this->db->table('alumnos')->where('id', $a['id'])->update([
                    'nombre'     => $a['nombre'],
                    'grado'      => $a['grado'],
                    'grupo'      => $a['grupo'],
                    'activo'     => 1,
                    'updated_at' => $ahora,
                ]);

                // El usuario "padre" que se creó con la CURP del alumno lleva su nombre: mantenerlo igual
                if ($this->_distinto($a['nombre_anterior'], $a['nombre'])) {
                    $this->db->table('usuarios')
                        ->where('curp', $a['curp'])
                        ->where('rol', 'padre')
                        ->where('nombre', $a['nombre_anterior'])
                        ->update(['nombre' => $a['nombre'], 'updated_at' => $ahora]);
                }
            }

            // 5.3 Altas (igual que la carga masiva de alumnos)
            foreach ($nuevos as $n) {
                $this->db->table('alumnos')->insert([
                    'escuela_id' => $usuario->escuela_id,
                    'uuid'       => $this->_generarUuid(),
                    'curp'       => $n['curp'],
                    'nombre'     => $n['nombre'],
                    'grado'      => $n['grado'],
                    'grupo'      => $n['grupo'],
                    'activo'     => 1,
                    'pagado'     => 0,
                    'created_at' => $ahora,
                ]);
                $alumnoId = (int) $this->db->insertID();

                $padre = $this->db->table('usuarios')->select('id')->where('curp', $n['curp'])->get()->getRowArray();
                if ($padre) {
                    $usuarioId = (int) $padre['id'];
                } else {
                    $this->db->table('usuarios')->insert([
                        'escuela_id'       => $usuario->escuela_id,
                        'curp'             => $n['curp'],
                        'nombre'           => $n['nombre'],
                        'password'         => password_hash(substr($n['curp'], 0, 8), PASSWORD_DEFAULT),
                        'rol'              => 'padre',
                        'activo'           => 1,
                        'password_changed' => 0,
                        'created_at'       => $ahora,
                    ]);
                    $usuarioId = (int) $this->db->insertID();
                }

                if (!$alumnoId || !$usuarioId) {
                    throw new \RuntimeException("Fila {$n['fila']}: no se pudo crear a {$n['nombre']}.");
                }

                $vinculado = $this->db->table('usuario_alumno')
                    ->where('usuario_id', $usuarioId)->where('alumno_id', $alumnoId)
                    ->countAllResults();
                if (!$vinculado) {
                    $this->db->table('usuario_alumno')->insert([
                        'usuario_id' => $usuarioId,
                        'alumno_id'  => $alumnoId,
                        'relacion'   => 'padre',
                        'created_at' => $ahora,
                    ]);
                }
            }

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Error de base de datos al guardar.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Actualización masiva alumnos: ' . $e->getMessage());
            return $this->fail('No se guardó ningún cambio. ' . $e->getMessage(), 500);
        }

        Auditoria::log(
            'actualizar',
            'alumnos',
            "Actualización masiva ({$turno}): {$resumen['nuevos']} nuevos, {$resumen['actualizados']} actualizados, {$resumen['grupos_nuevos']} grupos nuevos",
            $usuario
        );

        return $this->respond($respuesta(false));
    }

    // ─── Utilidades ─────────────────────────────────────────────────────────────

    private function _normalizarEncabezado($h): string
    {
        $h = mb_strtoupper(trim((string) $h));
        return strtr($h, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    }

    /** Mayúsculas y espacios dobles fuera */
    private function _limpiarTexto($v): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $v)));
    }

    /** "1", 1, 1.0, "1°", "1º", "1o." → "1" */
    private function _normalizarGrado($v): string
    {
        if (is_float($v) && floor($v) == $v) $v = (int) $v;
        $g = $this->_limpiarTexto($v);
        $g = preg_replace('/^(\d+)\s*(°|º|O\.?|ER\.?|DO\.?|RO\.?|TO\.?|VO\.?|NO\.?)$/u', '$1', $g);
        return rtrim($g, '.');
    }

    private function _claveGrupo($grado, $grupo): string
    {
        return mb_strtoupper(trim((string) $grado)) . '|' . mb_strtoupper(trim((string) $grupo));
    }

    private function _distinto($a, $b): bool
    {
        return mb_strtoupper(trim((string) $a)) !== mb_strtoupper(trim((string) $b));
    }

    private function _generarUuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }
}
