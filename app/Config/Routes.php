<?php

use CodeIgniter\Router\RouteCollection;

// Preflight CORS
$routes->options('(:any)', function() {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
    header('Access-Control-Allow-Credentials: true');
    header('HTTP/1.1 200 OK');
    exit();
});

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// API Vincúla
$routes->group('api', ['namespace' => 'App\Controllers\Api'], function ($routes) {

    // Rutas públicas
    $routes->post('auth/login',            'AuthController::login');
    $routes->post('auth/cambiar-password', 'AuthController::cambiarPassword');

    // Rutas protegidas con JWT
    $routes->group('', ['filter' => 'jwt'], function ($routes) {

        // Alumnos
        $routes->get('alumno/mis-hijos',               'AlumnoController::misHijos');
        $routes->post('alumno/vincular',               'AlumnoController::vincular');

        // Asistencias
        $routes->post('asistencia/registrar',          'AsistenciaController::registrar');
        $routes->get('asistencia/hoy',                 'AsistenciaController::hoy');
        $routes->get('asistencia/historial/(:segment)', 'AsistenciaController::historial/$1');
        $routes->get('asistencia/rango/(:segment)',    'AsistenciaController::rango/$1');
        $routes->get('asistencia/mes/(:segment)',      'AsistenciaController::mes/$1');
        $routes->get('asistencia/semana/(:segment)',   'AsistenciaController::semana/$1');

        // Incidencias
        $routes->post('incidencia/registrar',           'IncidenciaController::registrar');
        $routes->get('incidencia/alumno/(:segment)',    'IncidenciaController::porAlumno/$1');
        $routes->get('incidencia/escuela',             'IncidenciaController::porEscuela');
        $routes->post('incidencia/acuse/(:num)',        'IncidenciaController::acuse/$1');
        
        // Avisos
        $routes->post('aviso/publicar',          'AvisoController::publicar');
        $routes->get('aviso/mis-avisos',         'AvisoController::misAvisos');
        $routes->get('aviso/escuela',            'AvisoController::porEscuela');
        $routes->delete('aviso/eliminar/(:num)', 'AvisoController::eliminar/$1');

        // Calificaciones — papá
        $routes->get('calificacion/hijo/(:segment)',                    'CalificacionController::porHijo/$1');
        $routes->get('calificacion/hijo/(:segment)/bimestre/(:num)',    'CalificacionController::porBimestre/$1/$2');


        // QR
        $routes->get('qr/alumno/(:segment)',         'QrController::generar/$1');
        $routes->get('qr/grupo/(:segment)/(:segment)','QrController::porGrupo/$1/$2');
        $routes->get('qr/credencial/(:segment)',     'QrController::credencial/$1');

        // Admin — carga masiva
        $routes->group('admin', ['namespace' => 'App\Controllers\Api\Admin'], function ($routes) {
            $routes->post('carga/alumnos',              'CargaController::alumnos');
            $routes->post('carga/maestros',             'CargaController::maestros');
            $routes->post('alumno/nuevo',               'CargaController::nuevo');
            $routes->post('calificaciones/carga',       'CalificacionController::carga');
            $routes->get('calificaciones/alumno/(:segment)', 'CalificacionController::porAlumno/$1');


            // Pagos
            $routes->post('pagos/carga',                'PagoController::carga');
            $routes->get('pagos/estado',                'PagoController::estado');
            $routes->post('pagos/toggle/(:segment)',    'PagoController::toggle/$1');

            $routes->post('usuario/nuevo',  'CargaController::nuevoUsuario');
            $routes->get('usuarios',        'CargaController::listarUsuarios');
            $routes->put('usuario/editar/(:num)',        'CargaController::editarUsuario/$1');
            $routes->put('usuario/reset-password/(:num)','CargaController::resetPassword/$1');

        });

    });
});