<?php

namespace App\Filters;

use App\Libraries\JwtHelper;
use App\Libraries\EscuelaAcceso;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class JwtFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['status' => 401, 'messages' => ['error' => 'Token requerido.']]);
        }

        $token   = substr($header, 7);
        $payload = JwtHelper::validate($token);

        if (!$payload) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['status' => 401, 'messages' => ['error' => 'Token inválido o expirado.']]);
        }

        // ─── Escuela activa ───────────────────────────────────────────────
        // Super admin y admin pueden trabajar en varias escuelas: el panel manda
        // la escuela elegida en el header X-Escuela-Id. Aquí se valida que de verdad
        // tenga acceso; todos los controllers siguen usando $usuario->escuela_id.
        if (in_array($payload->rol ?? '', ['super_admin', 'admin'], true)) {
            $pedida = (int) $request->getHeaderLine('X-Escuela-Id');

            if ($pedida) {
                if (!EscuelaAcceso::puedeVer($payload, $pedida)) {
                    return service('response')
                        ->setStatusCode(403)
                        ->setJSON(['status' => 403, 'messages' => ['error' => 'No tienes acceso a esa escuela.']]);
                }
                $payload->escuela_id = $pedida;
            } elseif ($payload->rol === 'admin') {
                // Sin header: usar su escuela del token solo si todavía la tiene asignada
                $ids = EscuelaAcceso::idsPermitidos($payload);
                if (!in_array((int) ($payload->escuela_id ?? 0), $ids, true)) {
                    $payload->escuela_id = $ids[0] ?? null;
                }
            }
        }

        // Inyecta el payload en el request para usarlo en los controllers
        $request->usuario = $payload;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // nada
    }
}