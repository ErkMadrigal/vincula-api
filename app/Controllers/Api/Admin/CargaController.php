<?php
namespace App\Controllers\Api\Admin;

use App\Libraries\Auditoria;
use CodeIgniter\RESTful\ResourceController;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CargaController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

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

    // POST /api/admin/usuario/nuevo
    public function nuevoUsuario()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['super_admin', 'admin', 'director'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $json  = $this->request->getJSON();
        $rules = [
            'curp'   => 'required|min_length[18]|max_length[18]',
            'nombre' => 'required',
            'rol'    => 'required|in_list[admin,director,maestro]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $curp = strtoupper($json->curp);

        $existe = $this->db->table('usuarios')
            ->where('curp', $curp)
            ->where('escuela_id', $usuario->escuela_id)
            ->countAllResults();

        if ($existe) {
            return $this->fail('Ya existe un usuario con esa CURP.', 409);
        }

        $password = password_hash(substr($curp, 0, 8), PASSWORD_DEFAULT);

        $this->db->table('usuarios')->insert([
            'escuela_id' => $usuario->escuela_id,
            'curp'       => $curp,
            'nombre'     => $json->nombre,
            'password'   => $password,
            'rol'        => $json->rol,
            'activo'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Auditoria::log('crear', 'usuarios',
            "Nuevo usuario: {$json->nombre} ({$curp}) rol: {$json->rol}", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Usuario creado correctamente.',
            'usuario' => [
                'curp'   => $curp,
                'nombre' => $json->nombre,
                'rol'    => $json->rol,
            ]
        ]);
    }

    // GET /api/admin/usuarios
    public function listarUsuarios()
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['super_admin', 'admin', 'director'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $usuarios = $this->db->table('usuarios')
            ->select('id, curp, nombre, rol, activo, created_at')
            ->where('escuela_id', $usuario->escuela_id)
            ->whereIn('rol', ['admin', 'director', 'maestro'])
            ->orderBy('rol', 'ASC')
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        return $this->respond([
            'status'   => 'ok',
            'usuarios' => $usuarios,
        ]);
    }

    // PUT /api/admin/usuario/editar/:id
    public function editarUsuario(int $id = 0)
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['super_admin', 'admin', 'director'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $json = $this->request->getJSON();

        // Verificar que el usuario pertenezca a la escuela
        $target = $this->db->table('usuarios')
            ->where('id', $id)
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getRowArray();

        if (!$target) {
            return $this->failNotFound('Usuario no encontrado.');
        }

        $datos = [];

        if (!empty($json->nombre)) {
            $datos['nombre'] = $json->nombre;
        }

        if (!empty($json->rol)) {
            if (!in_array($json->rol, ['admin', 'director', 'maestro'])) {
                return $this->fail('Rol inválido.', 400);
            }
            $datos['rol'] = $json->rol;
        }

        if (isset($json->activo)) {
            $datos['activo'] = (int)$json->activo;
        }

        if (empty($datos)) {
            return $this->fail('No hay datos para actualizar.', 400);
        }

        $datos['updated_at'] = date('Y-m-d H:i:s');

        $this->db->table('usuarios')->update($datos, ['id' => $id]);

        Auditoria::log('editar', 'usuarios',
            "Editó usuario ID: {$id} — {$target['nombre']}", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Usuario actualizado correctamente.',
        ]);
    }

    // PUT /api/admin/usuario/reset-password/:id
    public function resetPassword(int $id = 0)
    {
        $usuario = $this->request->usuario;

        if (!in_array($usuario->rol, ['super_admin', 'admin', 'director'])) {
            return $this->fail('No tienes permiso.', 403);
        }

        $target = $this->db->table('usuarios')
            ->where('id', $id)
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getRowArray();

        if (!$target) {
            return $this->failNotFound('Usuario no encontrado.');
        }

        // Reset a los primeros 8 dígitos de su CURP
        $nuevaPassword = password_hash(substr($target['curp'], 0, 8), PASSWORD_DEFAULT);

        $this->db->table('usuarios')->update(
            ['password' => $nuevaPassword, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );

        Auditoria::log('editar', 'usuarios',
            "Reset de contraseña para: {$target['nombre']} (ID: {$id})", $usuario);

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => "Contraseña restablecida. Nueva contraseña: " . substr($target['curp'], 0, 8),
            'password'=> substr($target['curp'], 0, 8),
        ]);
    }

    // GET /api/admin/alumnos
    public function listarAlumnos()
    {
        $usuario = $this->request->usuario;

        $alumnos = $this->db->table('alumnos')
            ->where('escuela_id', $usuario->escuela_id)
            ->where('activo', 1)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        return $this->respond(['status' => 'ok', 'alumnos' => $alumnos]);
    }

    // PUT /api/admin/alumno/editar/:uuid
    public function editarAlumno(string $uuid = '')
    {
        $usuario = $this->request->usuario;
        $json    = $this->request->getJSON();

        $alumno = $this->db->table('alumnos')
            ->where('uuid', $uuid)
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        $this->db->table('alumnos')->update([
            'nombre'     => $json->nombre ?? $alumno['nombre'],
            'grado'      => $json->grado  ?? $alumno['grado'],
            'grupo'      => $json->grupo  ?? $alumno['grupo'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $alumno['id']]);

        Auditoria::log('editar', 'alumnos', "Editó alumno: {$alumno['nombre']}", $usuario);

        return $this->respond(['status' => 'ok', 'mensaje' => 'Alumno actualizado correctamente.']);
    }

    // GET /api/admin/calificaciones/alumno/:uuid
    public function calificacionesPorAlumno(string $uuid = '')
    {
        $usuario = $this->request->usuario;

        $alumno = $this->db->table('alumnos')
            ->where('uuid', $uuid)
            ->where('escuela_id', $usuario->escuela_id)
            ->get()->getRowArray();

        if (!$alumno) {
            return $this->failNotFound('Alumno no encontrado.');
        }

        $calificaciones = $this->db->table('calificaciones c')
            ->select('c.id, c.bimestre, c.ciclo, c.promedio, c.created_at')
            ->where('c.alumno_id', $alumno['id'])
            ->orderBy('c.ciclo', 'DESC')
            ->orderBy('c.bimestre', 'ASC')
            ->get()->getResultArray();

        foreach ($calificaciones as &$cal) {
            $cal['materias'] = $this->db->table('calificacion_materias')
                ->where('calificacion_id', $cal['id'])
                ->orderBy('materia', 'ASC')
                ->get()->getResultArray();
        }

        return $this->respond([
            'status'         => 'ok',
            'calificaciones' => $calificaciones,
        ]);
    }
}