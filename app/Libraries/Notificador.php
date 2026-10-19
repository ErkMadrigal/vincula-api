<?php
namespace App\Libraries;

/**
 * Punto único para mandar notificaciones push a los papás.
 * NUNCA lanza excepciones: si algo falla se registra en el log y regresa el resumen.
 */
class Notificador
{
    /** Tokens de todos los tutores de uno o varios alumnos */
    public static function tokensDeAlumnos(array $alumnoIds): array
    {
        $alumnoIds = array_values(array_filter(array_map('intval', $alumnoIds)));
        if (!$alumnoIds) return [];

        try {
            $filas = \Config\Database::connect()->table('dispositivos d')
                ->select('DISTINCT d.fcm_token', false)
                ->join('usuario_alumno ua', 'ua.usuario_id = d.usuario_id')
                ->join('usuarios u', 'u.id = d.usuario_id')
                ->whereIn('ua.alumno_id', $alumnoIds)
                ->where('d.activo', 1)
                ->where('u.activo', 1)
                ->get()->getResultArray();
            return array_values(array_filter(array_column($filas, 'fcm_token')));
        } catch (\Throwable $e) {
            log_message('error', 'Notificador tokensDeAlumnos: ' . $e->getMessage());
            return [];
        }
    }

    /** Tokens de los papás de una escuela (opcional: solo un grado y/o grupo) */
    public static function tokensDeEscuela(int $escuelaId, ?string $grado = null, ?string $grupo = null): array
    {
        try {
            $q = \Config\Database::connect()->table('dispositivos d')
                ->select('DISTINCT d.fcm_token', false)
                ->join('usuario_alumno ua', 'ua.usuario_id = d.usuario_id')
                ->join('alumnos a', 'a.id = ua.alumno_id')
                ->join('usuarios u', 'u.id = d.usuario_id')
                ->where('a.escuela_id', $escuelaId)
                ->where('a.activo', 1)
                ->where('u.activo', 1)
                ->where('d.activo', 1);

            if ($grado !== null && $grado !== '') $q->where('a.grado', $grado);
            if ($grupo !== null && $grupo !== '') $q->where('a.grupo', $grupo);

            return array_values(array_filter(array_column($q->get()->getResultArray(), 'fcm_token')));
        } catch (\Throwable $e) {
            log_message('error', 'Notificador tokensDeEscuela: ' . $e->getMessage());
            return [];
        }
    }

    /** Manda la push. Regresa ['enviados' => n, 'errores' => n, 'dispositivos' => n] */
    public static function enviar(array $tokens, string $titulo, string $cuerpo, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        $base   = ['enviados' => 0, 'errores' => 0, 'dispositivos' => count($tokens)];
        if (!$tokens) return $base;

        try {
            return (new FirebaseNotification())->enviar($tokens, $titulo, $cuerpo, $data) + $base;
        } catch (\Throwable $e) {
            log_message('error', 'Notificador enviar: ' . $e->getMessage());
            return ['enviados' => 0, 'errores' => count($tokens), 'dispositivos' => count($tokens), 'error' => $e->getMessage()];
        }
    }
}
