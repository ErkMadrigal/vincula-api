<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

class HealthController extends ResourceController
{
    public function ping()
    {
        return $this->respond([
            'status' => 'ok',
            'message' => 'api.vincula365.com funcionando',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}