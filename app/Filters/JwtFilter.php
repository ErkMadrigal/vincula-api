<?php

namespace App\Filters;

use App\Libraries\JwtHelper;
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

        // Inyecta el payload en el request para usarlo en los controllers
        $request->usuario = $payload;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // nada
    }
}