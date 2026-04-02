<?php

namespace App\Models;

use CodeIgniter\Model;

class UsuarioModel extends Model
{
    protected $table            = 'usuarios';
    protected $primaryKey       = 'id';
    protected $allowedFields    = [
        'escuela_id', 'curp', 'nombre', 'password',
        'rol', 'activo', 'fcm_token'
    ];
    protected $useTimestamps    = true;
    protected $hiddenFields     = ['password'];

    public function findByCurp(string $curp): array|null
    {
        return $this->where('curp', strtoupper($curp))
                    ->where('activo', 1)
                    ->first();
    }
}