<?php
namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

class SuperAdminController extends ResourceController
{
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // GET /api/superadmin/dashboard
    public function dashboard()
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        // Totales globales
        $totalEscuelas  = $this->db->table('escuelas')->where('activa', 1)->countAllResults();
        $totalAlumnos   = $this->db->table('alumnos')->where('activo', 1)->countAllResults();
        $alumnosPagados = $this->db->table('alumnos')->where('activo', 1)->where('pagado', 1)->countAllResults();
        $alumnosBloqueados = $totalAlumnos - $alumnosPagados;

        // Ingresos
        $ingreseMes  = $alumnosPagados * 15;
        $ingreseAnio = $ingreseMes * 12;

        // Escuelas con más alumnos
        $escuelas = $this->db->table('escuelas e')
            ->select('e.id, e.nombre, e.subdominio, e.activa,
                      COUNT(a.id) as total_alumnos,
                      SUM(a.pagado) as alumnos_pagados')
            ->join('alumnos a', 'a.escuela_id = e.id', 'left')
            ->where('e.activa', 1)
            ->groupBy('e.id')
            ->orderBy('total_alumnos', 'DESC')
            ->get()->getResultArray();

        // Usuarios por rol en todo el sistema
        $usuarios = $this->db->table('usuarios')
            ->select('rol, COUNT(*) as total')
            ->whereIn('rol', ['super_admin','admin','director','maestro','padre'])
            ->groupBy('rol')
            ->get()->getResultArray();

        return $this->respond([
            'status'             => 'ok',
            'totales' => [
                'escuelas'          => $totalEscuelas,
                'alumnos'           => $totalAlumnos,
                'alumnos_pagados'   => $alumnosPagados,
                'alumnos_bloqueados'=> $alumnosBloqueados,
                'ingreso_mes'       => $ingreseMes,
                'ingreso_anio'      => $ingreseAnio,
            ],
            'escuelas'  => $escuelas,
            'usuarios'  => $usuarios,
        ]);
    }

    // GET /api/superadmin/escuelas
    public function escuelas()
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $escuelas = $this->db->table('escuelas e')
            ->select('e.id, e.nombre, e.subdominio, e.plan, e.activa, e.cobro_activo, e.created_at,
                    COUNT(DISTINCT a.id) as total_alumnos,
                    COUNT(DISTINCT CASE WHEN a.pagado = 1 THEN a.id END) as alumnos_pagados')
            ->join('alumnos a', 'a.escuela_id = e.id AND a.activo = 1', 'left')
            ->groupBy('e.id')
            ->orderBy('e.created_at', 'DESC')
            ->get()->getResultArray();

        foreach ($escuelas as &$e) {
            $e['alumnos_pagados'] = (int)$e['alumnos_pagados'];
            $e['total_alumnos']   = (int)$e['total_alumnos'];
            $e['ingreso_mes']     = $e['alumnos_pagados'] * 15;
            $e['ingreso_anio']    = $e['ingreso_mes'] * 12;
        }

        return $this->respond([
            'status'   => 'ok',
            'escuelas' => $escuelas,
        ]);
    }
    // POST /api/superadmin/escuelas/nueva
    public function nuevaEscuela()
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $json  = $this->request->getJSON();
        $rules = [
            'nombre'     => 'required|min_length[3]',
            'subdominio' => 'required|min_length[2]|max_length[50]',
            'plan'       => 'required|in_list[basico,pro]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        // Verificar subdominio único
        $existe = $this->db->table('escuelas')
            ->where('subdominio', strtolower($json->subdominio))
            ->countAllResults();

        if ($existe) {
            return $this->fail('Ya existe una escuela con ese subdominio.', 409);
        }

        $this->db->table('escuelas')->insert([
            'nombre'     => $json->nombre,
            'subdominio' => strtolower($json->subdominio),
            'plan'       => $json->plan,
            'activa'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $escuelaId = $this->db->insertID();

        // Crear usuario admin de la escuela automáticamente si viene en el request
        if (!empty($json->admin_curp) && !empty($json->admin_nombre)) {
            $curp     = strtoupper($json->admin_curp);
            $password = password_hash(substr($curp, 0, 8), PASSWORD_DEFAULT);

            $this->db->table('usuarios')->insert([
                'escuela_id' => $escuelaId,
                'curp'       => $curp,
                'nombre'     => $json->admin_nombre,
                'password'   => $password,
                'rol'        => 'director',
                'activo'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->respond([
            'status'    => 'ok',
            'mensaje'   => 'Escuela creada correctamente.',
            'escuela_id'=> $escuelaId,
        ]);
    }

    // PUT /api/superadmin/escuelas/toggle/:id
    public function toggleEscuela(int $id = 0)
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $escuela = $this->db->table('escuelas')
            ->where('id', $id)
            ->get()->getRowArray();

        if (!$escuela) {
            return $this->failNotFound('Escuela no encontrada.');
        }

        $nuevoEstado = $escuela['activa'] ? 0 : 1;
        $texto       = $nuevoEstado ? 'activada' : 'desactivada';

        $this->db->table('escuelas')->update(
            ['activa' => $nuevoEstado, 'updated_at' => date('Y-m-d H:i:s')],
            ['id'     => $id]
        );

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => "Escuela {$texto} correctamente.",
            'activa'  => $nuevoEstado,
        ]);
    }

    // POST /api/superadmin/usuario/nuevo
    public function nuevoUsuario()
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $json  = $this->request->getJSON();
        $rules = [
            'escuela_id' => 'required|is_natural_no_zero',
            'curp'       => 'required|min_length[18]|max_length[18]',
            'nombre'     => 'required',
            'rol'        => 'required|in_list[admin,director,maestro]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $curp = strtoupper($json->curp);

        // Verificar que la escuela exista
        $escuela = $this->db->table('escuelas')
            ->where('id', $json->escuela_id)
            ->where('activa', 1)
            ->get()->getRowArray();

        if (!$escuela) {
            return $this->fail('Escuela no encontrada o inactiva.', 404);
        }

        // Verificar que no exista en esa escuela
        $existe = $this->db->table('usuarios')
            ->where('curp', $curp)
            ->where('escuela_id', $json->escuela_id)
            ->countAllResults();

        if ($existe) {
            return $this->fail('Ya existe un usuario con esa CURP en esa escuela.', 409);
        }

        $password = password_hash(substr($curp, 0, 8), PASSWORD_DEFAULT);

        $this->db->table('usuarios')->insert([
            'escuela_id' => $json->escuela_id,
            'curp'       => $curp,
            'nombre'     => $json->nombre,
            'password'   => $password,
            'rol'        => $json->rol,
            'activo'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($json->rol === 'admin') {
            $this->db->table('admin_escuelas')->ignore(true)->insert([
                'usuario_id' => (int) $this->db->insertID(),
                'escuela_id' => (int) $json->escuela_id,
                'origen'     => 'asignada',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Usuario creado correctamente.',
            'escuela' => $escuela['nombre'],
            'usuario' => [
                'curp'   => $curp,
                'nombre' => $json->nombre,
                'rol'    => $json->rol,
            ]
        ]);
    }
    // GET /api/superadmin/usuarios
    public function usuarios()
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $usuarios = $this->db->table('usuarios u')
            ->select('u.id, u.curp, u.nombre, u.rol, u.activo, u.created_at, e.nombre as escuela_nombre')
            ->join('escuelas e', 'e.id = u.escuela_id', 'left')
            ->whereIn('u.rol', ['admin', 'director', 'maestro'])
            ->orderBy('e.nombre', 'ASC')
            ->orderBy('u.rol', 'ASC')
            ->orderBy('u.nombre', 'ASC')
            ->get()->getResultArray();

        return $this->respond([
            'status'   => 'ok',
            'usuarios' => $usuarios,
        ]);
    }

    public function editarUsuario(int $id = 0)
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $json   = $this->request->getJSON();
        $target = $this->db->table('usuarios')->where('id', $id)->get()->getRowArray();

        if (!$target) {
            return $this->failNotFound('Usuario no encontrado.');
        }

        $datos = [];
        if (!empty($json->nombre))  $datos['nombre'] = $json->nombre;
        if (!empty($json->rol))     $datos['rol']    = $json->rol;
        if (isset($json->activo))   $datos['activo'] = (int)$json->activo;

        if (empty($datos)) {
            return $this->fail('No hay datos para actualizar.', 400);
        }

        $datos['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('usuarios')->update($datos, ['id' => $id]);

        return $this->respond(['status' => 'ok', 'mensaje' => 'Usuario actualizado correctamente.']);
    }

    public function resetPassword(int $id = 0)
    {
        $usuario = $this->request->usuario;

        if ($usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $target = $this->db->table('usuarios')->where('id', $id)->get()->getRowArray();

        if (!$target) {
            return $this->failNotFound('Usuario no encontrado.');
        }

        $nuevaPassword = password_hash(substr($target['curp'], 0, 8), PASSWORD_DEFAULT);

        $this->db->table('usuarios')->update(
            ['password' => $nuevaPassword, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );

        return $this->respond([
            'status'   => 'ok',
            'mensaje'  => 'Contraseña restablecida.',
            'password' => substr($target['curp'], 0, 8),
        ]);
    }

    // ─── Admins y sus escuelas ─────────────────────────────────────────────────

    // GET /api/superadmin/admins
    public function admins()
    {
        if ($this->request->usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $admins = $this->db->table('usuarios')
            ->select('id, curp, nombre, activo')
            ->where('rol', 'admin')
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        $asignaciones = $this->db->table('admin_escuelas ae')
            ->select('ae.usuario_id, ae.origen, e.id, e.nombre')
            ->join('escuelas e', 'e.id = ae.escuela_id')
            ->orderBy('e.nombre', 'ASC')
            ->get()->getResultArray();

        $porAdmin = [];
        foreach ($asignaciones as $a) {
            $porAdmin[$a['usuario_id']][] = ['id' => (int) $a['id'], 'nombre' => $a['nombre'], 'origen' => $a['origen']];
        }
        foreach ($admins as &$ad) {
            $ad['escuelas'] = $porAdmin[$ad['id']] ?? [];
        }

        return $this->respond(['status' => 'ok', 'admins' => $admins]);
    }

    // PUT /api/superadmin/admins/:id/escuelas   { escuelas: [ids] }
    // Reemplaza las escuelas ASIGNADAS. Las que el admin creó se conservan siempre.
    public function asignarEscuelas(int $id = 0)
    {
        if ($this->request->usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $admin = $this->db->table('usuarios')->where('id', $id)->where('rol', 'admin')->get()->getRowArray();
        if (!$admin) {
            return $this->failNotFound('Admin no encontrado.');
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($this->request->getJSON()->escuelas ?? []))));
        if ($ids) {
            $validas = $this->db->table('escuelas')->select('id')->whereIn('id', $ids)->get()->getResultArray();
            $ids = array_map('intval', array_column($validas, 'id'));
        }

        $creadas = array_map('intval', array_column(
            $this->db->table('admin_escuelas')->select('escuela_id')
                ->where('usuario_id', $id)->where('origen', 'creada')->get()->getResultArray(),
            'escuela_id'
        ));

        $this->db->transStart();
        $this->db->table('admin_escuelas')->where('usuario_id', $id)->where('origen', 'asignada')->delete();
        foreach (array_diff($ids, $creadas) as $escuelaId) {
            $this->db->table('admin_escuelas')->insert([
                'usuario_id' => $id,
                'escuela_id' => $escuelaId,
                'origen'     => 'asignada',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $this->db->transComplete();

        \App\Libraries\Auditoria::log('actualizar', 'admin_escuelas',
            "Asignó " . count($ids) . " escuelas a {$admin['nombre']}", $this->request->usuario);

        return $this->respond(['status' => 'ok', 'mensaje' => 'Escuelas asignadas.']);
    }

    // PUT /api/superadmin/escuelas/cobro/:id
    // Enciende/apaga el cobro de la escuela. Apagado = la asistencia no revisa pagos.
    public function toggleCobro(int $id = 0)
    {
        if ($this->request->usuario->rol !== 'super_admin') {
            return $this->fail('No tienes permiso.', 403);
        }

        $escuela = $this->db->table('escuelas')->where('id', $id)->get()->getRowArray();
        if (!$escuela) {
            return $this->failNotFound('Escuela no encontrada.');
        }

        $nuevo = $escuela['cobro_activo'] ? 0 : 1;
        $this->db->table('escuelas')->where('id', $id)
            ->update(['cobro_activo' => $nuevo, 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->respond([
            'status'       => 'ok',
            'cobro_activo' => $nuevo,
            'mensaje'      => $nuevo ? 'Cobro activado.' : 'Cobro desactivado.',
        ]);
    }
}
